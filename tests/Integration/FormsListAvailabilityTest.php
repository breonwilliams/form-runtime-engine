<?php
/**
 * The Forms list's availability Status column (P6), against a real database
 * and real WordPress timezone handling.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Open / closed-with-reason / count against cap, dates in the site timezone.
 */
class FormsListAvailabilityTest extends IntegrationTestCase {

    public function set_up() {
        parent::set_up();
        // The P1 non-UTC zone (FormAvailabilityTimezoneTest). January is NZDT (UTC+13).
        update_option( 'timezone_string', 'Pacific/Auckland' );
        update_option( 'gmt_offset', '' );
        update_option( 'date_format', 'F j, Y' );
        update_option( 'time_format', 'g:i a' );

        $user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
        $user->add_cap( \PForms_Capabilities::MANAGE_FORMS );
        wp_set_current_user( $user->ID );
    }

    public function tear_down() {
        foreach ( array_keys( \PForms_Forms_Repository::get_all() ) as $form_id ) {
            if ( 0 === strpos( $form_id, 'p6-list-' ) ) {
                \PForms_Forms_Repository::delete( $form_id );
            }
        }
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function save( $form_id, $availability = null ) {
        $config = array(
            'title'  => 'Camp ' . $form_id,
            'fields' => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
        );
        if ( null !== $availability ) {
            $config['settings'] = array( 'availability' => $availability );
        }

        $result = \PForms_Forms_Repository::save( $form_id, array(
            'title'  => 'Camp ' . $form_id,
            'config' => wp_json_encode( $config ),
        ) );
        $this->assertNotWPError( $result );

        return \PForms_Forms_Repository::get( $form_id );
    }

    private function cell( $html, $form_id ) {
        $start = strpos( $html, '<tr data-form-id="' . $form_id . '">' );
        $this->assertNotFalse( $start, "The Forms list has no row for {$form_id}." );
        $end = strpos( $html, '</tr>', $start );

        $row = substr( $html, $start, $end - $start );
        $from = strpos( $row, '<td class="column-availability">' );
        $this->assertNotFalse( $from, "Row {$form_id} has no Status cell." );

        return substr( $row, $from, strpos( $row, '</td>', $from ) - $from );
    }

    private function render_list() {
        unset( $_GET['action'], $_GET['form'] );
        ob_start();
        \PForms_Forms_Manager::render_page();
        return ob_get_clean();
    }

    public function test_the_forms_list_shows_each_state_with_reason_and_count_against_cap() {
        $this->save( 'p6-list-plain' );
        $this->save( 'p6-list-capped', array( 'max_entries' => 10 ) );
        ( new \PForms_Place_Counter() )->set( 'p6-list-capped', 3 );
        $this->save( 'p6-list-early', array( 'opens_at' => '2099-01-15 09:00' ) );
        $this->save( 'p6-list-late', array( 'closes_at' => '2020-01-20 17:00' ) );
        $this->save( 'p6-list-full', array( 'max_entries' => 2 ) );
        ( new \PForms_Place_Counter() )->set( 'p6-list-full', 2 );

        $html = $this->render_list();

        $this->assertStringContainsString( '<th scope="col" class="column-availability">Status</th>', $html );

        $plain = $this->cell( $html, 'p6-list-plain' );
        $this->assertStringContainsString( '>Open</strong>', $plain );
        $this->assertStringNotContainsString( 'place', $plain );
        $this->assertStringNotContainsString( 'Adjust count', $plain );

        $capped = $this->cell( $html, 'p6-list-capped' );
        $this->assertStringContainsString( '>Open</strong>', $capped );
        $this->assertStringContainsString( '3 of 10 places taken', $capped );
        $this->assertStringContainsString( 'Adjust count', $capped );

        $early = $this->cell( $html, 'p6-list-early' );
        $this->assertStringContainsString( 'Closed: not open yet', $early );
        $this->assertStringContainsString( 'Opens January 15, 2099 at 9:00 am NZDT', $early );

        $late = $this->cell( $html, 'p6-list-late' );
        $this->assertStringContainsString( 'Closed: past its closing date', $late );
        $this->assertStringContainsString( 'Closed January 20, 2020 at 5:00 pm NZDT', $late );

        $full = $this->cell( $html, 'p6-list-full' );
        $this->assertStringContainsString( 'Closed: entry limit reached', $full );
        $this->assertStringContainsString( '2 of 2 places taken', $full );
        $this->assertStringContainsString( 'data-reason="cap_reached"', $full );
        $this->assertStringContainsString( 'Adjust count', $full );
    }

    public function test_dates_are_in_the_site_timezone_with_the_zone_named() {
        // 09:00 NZDT on 15 Jan 2099 is 20:00 UTC on the 14th; a UTC rendering would say so.
        $early = \PForms_Forms_Availability_Status::describe( 'p6-list-early', $this->save( 'p6-list-early', array(
            'opens_at'  => '2099-01-15 09:00',
            'closes_at' => '2099-07-20 17:00',
        ) ) );
        $this->assertSame( 'Opens January 15, 2099 at 9:00 am NZDT', $early['details'][0] );
        // July is winter in Auckland: the label follows daylight saving.
        $this->assertSame( 'Closes July 20, 2099 at 5:00 pm NZST', $early['details'][1] );

        // A bare closes_at date closes at the end of that day, named as that day.
        $bare = \PForms_Forms_Availability_Status::describe( 'p6-list-bare', $this->save( 'p6-list-bare', array(
            'closes_at' => '2099-01-20',
        ) ) );
        $this->assertSame( 'Open', $bare['label'] );
        $this->assertSame( array( 'Closes at the end of January 20, 2099 (NZDT)' ), $bare['details'] );

        // Offset zones are named the way General Settings names them.
        update_option( 'timezone_string', '' );
        update_option( 'gmt_offset', 5.5 );
        $this->assertSame(
            'January 15, 2099 at 2:30 am UTC+5:30',
            \PForms_Forms_Availability_Status::format_moment( gmmktime( 21, 0, 0, 1, 14, 2099 ) )
        );
    }

    public function test_a_form_whose_cap_was_removed_keeps_showing_its_count() {
        $this->save( 'p6-list-was-capped', array( 'max_entries' => 5 ) );
        ( new \PForms_Place_Counter() )->set( 'p6-list-was-capped', 4 );
        $record = $this->save( 'p6-list-was-capped' );

        $status = \PForms_Forms_Availability_Status::describe( 'p6-list-was-capped', $record );

        $this->assertTrue( $status['open'] );
        $this->assertSame( 'Open', $status['label'] );
        $this->assertSame( array( '4 counted (no entry limit set)' ), $status['details'] );
        $this->assertTrue( $status['adjustable'] );
    }

    public function test_invalid_settings_and_filter_reasons_are_named() {
        $closer = static function ( $result ) {
            $result['open']   = false;
            $result['reason'] = 'waitlist_only';
            return $result;
        };
        add_filter( 'pforms_form_availability', $closer );
        $status = \PForms_Forms_Availability_Status::describe( 'p6-list-filtered', $this->save( 'p6-list-filtered' ) );
        remove_filter( 'pforms_form_availability', $closer );

        $this->assertSame( 'Closed: by a custom rule (waitlist_only)', $status['label'] );

        // Only a hand-written record can hold invalid rules: both save paths refuse them.
        $status = \PForms_Forms_Availability_Status::describe( 'p6-list-invalid', array(
            'config' => wp_json_encode( array(
                'fields'   => array( array( 'key' => 'email', 'type' => 'email' ) ),
                'settings' => array( 'availability' => array( 'max_entries' => 'unlimited' ) ),
            ) ),
        ) );
        $this->assertFalse( $status['open'] );
        $this->assertSame( 'Closed: availability settings are invalid', $status['label'] );
    }

    public function test_status_output_is_escaped() {
        $closer = static function ( $result ) {
            $result['open']   = false;
            $result['reason'] = '<script>x</script>';
            return $result;
        };
        add_filter( 'pforms_form_availability', $closer );
        $html = \PForms_Forms_Availability_Status::render( 'p6-list-esc', $this->save( 'p6-list-esc' ) );
        remove_filter( 'pforms_form_availability', $closer );

        $this->assertStringNotContainsString( '<script>', $html );
        $this->assertStringContainsString( '&lt;script&gt;', $html );
    }
}
