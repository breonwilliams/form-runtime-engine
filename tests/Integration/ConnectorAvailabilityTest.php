<?php
/**
 * Availability through the connector: the callbacks behind
 * formengine_create_form / formengine_update_form / formengine_get_form /
 * formengine_list_forms / formengine_test_submit, against a real database.
 *
 * The handlers are called with WP_REST_Request objects directly; the
 * connector's auth stack (application passwords, enable switch, rate limit)
 * has its own tests and is not what these pin.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Set, change, clear and read availability rules through the connector.
 */
class ConnectorAvailabilityTest extends IntegrationTestCase {

    /**
     * @var \PForms_Connector_API
     */
    private $api;

    public function set_up() {
        parent::set_up();
        $this->api = new \PForms_Connector_API();
        update_option( 'timezone_string', 'America/New_York' );
    }

    public static function tear_down_after_class() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pforms\\_places\\_p5-conn-%'" );
        $ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id LIKE 'p5-conn-%'" );
        foreach ( $ids as $id ) {
            $wpdb->delete( $wpdb->prefix . 'fre_entry_meta', array( 'entry_id' => (int) $id ) );
            $wpdb->delete( $wpdb->prefix . 'fre_entries', array( 'id' => (int) $id ) );
        }
        $wpdb->query( 'COMMIT' );

        parent::tear_down_after_class();
    }

    private function form_id( $name ) {
        return 'p5-conn-' . $name . '-' . strtolower( wp_generate_password( 6, false ) );
    }

    private function config( $availability = null ) {
        $config = array(
            'title'  => 'Camp',
            'fields' => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ) ),
        );
        if ( null !== $availability ) {
            $config['settings'] = array( 'availability' => $availability );
        }
        return wp_json_encode( $config );
    }

    private function request( $method, array $params ) {
        $request = new \WP_REST_Request( $method, '/fre/v1/connector/forms' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return $request;
    }

    private function create( $form_id, $availability ) {
        $response = $this->api->handle_create_form( $this->request( 'POST', array(
            'id'     => $form_id,
            'config' => $this->config( $availability ),
        ) ) );
        return is_wp_error( $response ) ? $response : $response->get_data();
    }

    private function update( $form_id, $availability ) {
        $response = $this->api->handle_update_form( $this->request( 'PATCH', array(
            'form_id' => $form_id,
            'config'  => $this->config( $availability ),
        ) ) );
        return is_wp_error( $response ) ? $response : $response->get_data();
    }

    private function get( $form_id ) {
        $response = $this->api->handle_get_form( $this->request( 'GET', array( 'form_id' => $form_id ) ) );
        $this->assertNotWPError( $response );
        return $response->get_data()['data'];
    }

    private function stored_availability( $form_id ) {
        $config = json_decode( \PForms_Forms_Repository::get( $form_id )['config'], true );
        return $config['settings']['availability'] ?? null;
    }

    public function test_set_change_and_clear_every_availability_setting() {
        $form_id = $this->form_id( 'cycle' );

        $all = array(
            'opens_at'            => '2020-01-01 09:00',
            'closes_at'           => '2099-12-31',
            'max_entries'         => 30,
            'closed_message'      => 'Registration has closed.',
            'before_open_message' => 'Registration opens soon.',
        );
        $created = $this->create( $form_id, $all );
        $this->assertNotWPError( $created );
        $this->assertSame( $all, $this->stored_availability( $form_id ) );

        $read = $this->get( $form_id )['availability'];
        $this->assertTrue( $read['open'] );
        $this->assertSame( 'open', $read['reason'] );
        $this->assertSame( '2020-01-01 09:00', $read['opens_at'] );
        $this->assertSame( '2099-12-31', $read['closes_at'] );
        $this->assertSame( '2020-01-01T09:00:00-05:00', $read['opens_at_iso'] );
        $this->assertSame( '2100-01-01T00:00:00-05:00', $read['closes_at_iso'], 'A bare close date closes at the end of that day.' );
        $this->assertSame( 'America/New_York', $read['timezone'] );
        $this->assertSame( 30, $read['max_entries'] );
        $this->assertSame( 0, $read['count'] );

        $changed = array(
            'opens_at'            => '2099-03-15 09:00',
            'closes_at'           => '2099-03-20 17:00',
            'max_entries'         => 5,
            'closed_message'      => 'Full, sorry.',
            'before_open_message' => 'Not yet.',
        );
        $this->assertNotWPError( $this->update( $form_id, $changed ) );
        $this->assertSame( $changed, $this->stored_availability( $form_id ) );

        $read = $this->get( $form_id )['availability'];
        $this->assertFalse( $read['open'] );
        $this->assertSame( 'not_yet_open', $read['reason'] );
        $this->assertSame( 'Not yet.', $read['message'] );
        $this->assertSame( 5, $read['max_entries'] );

        // Cleared: an update whose config has no availability block.
        $this->assertNotWPError( $this->update( $form_id, null ) );
        $this->assertNull( $this->stored_availability( $form_id ) );

        $read = $this->get( $form_id )['availability'];
        $this->assertTrue( $read['open'], 'Cleared rules mean always open.' );
        $this->assertSame( 'open', $read['reason'] );
        $this->assertNull( $read['max_entries'], 'Cleared rules mean unlimited.' );
        $this->assertNull( $read['opens_at'] );
        $this->assertNull( $read['closes_at'] );
        $this->assertNull( $read['opens_at_iso'] );
        $this->assertNull( $read['closes_at_iso'] );
    }

    /**
     * @dataProvider refused_values
     */
    public function test_a_refused_value_gives_the_admin_message( $availability ) {
        $form_id = $this->form_id( 'refuse' );

        $admin = \PForms_Forms_Manager::save_form( $form_id, 'Camp', $this->config( $availability ) );
        $this->assertWPError( $admin );

        $connector = $this->create( $form_id, $availability );
        $this->assertWPError( $connector );
        $this->assertSame( 'schema_error', $connector->get_error_code() );
        $this->assertSame( $admin->get_error_message(), $connector->get_error_message() );
        $this->assertSame( 400, $connector->get_error_data()['status'] );

        // And on update of an existing form, which keeps its old config.
        $this->assertNotWPError( $this->create( $form_id, array( 'max_entries' => 3 ) ) );
        $updated = $this->update( $form_id, $availability );
        $this->assertWPError( $updated );
        $this->assertSame( $admin->get_error_message(), $updated->get_error_message() );
        $this->assertSame( array( 'max_entries' => 3 ), $this->stored_availability( $form_id ) );
    }

    public function refused_values() {
        return array(
            'unlimited string' => array( array( 'max_entries' => 'unlimited' ) ),
            'negative cap'     => array( array( 'max_entries' => -1 ) ),
            'close before open' => array( array( 'opens_at' => '2027-03-15', 'closes_at' => '2027-03-14' ) ),
            'unknown key'      => array( array( 'max_entrys' => 5 ) ),
            'empty message'    => array( array( 'closed_message' => '' ) ),
            'bad date'         => array( array( 'closes_at' => '15/03/2027' ) ),
            'dst gap'          => array( array( 'opens_at' => '2027-03-14 02:30' ) ),
        );
    }

    public function test_the_dst_gap_message_names_the_gap() {
        $result = $this->create( $this->form_id( 'dst' ), array( 'opens_at' => '2027-03-14 02:30' ) );
        $this->assertWPError( $result );
        $this->assertStringContainsString( 'does not exist in the site timezone (America/New_York)', $result->get_error_message() );
    }

    public function test_get_form_reports_a_full_form_with_its_count_and_cap() {
        $form_id = $this->form_id( 'full' );
        $this->assertNotWPError( $this->create( $form_id, array( 'max_entries' => 2, 'closed_message' => 'All places taken.' ) ) );

        ( new \PForms_Place_Counter() )->set( $form_id, 1 );
        $read = $this->get( $form_id )['availability'];
        $this->assertTrue( $read['open'] );
        $this->assertSame( 1, $read['count'] );
        $this->assertSame( 2, $read['max_entries'] );

        ( new \PForms_Place_Counter() )->set( $form_id, 2 );
        $read = $this->get( $form_id )['availability'];
        $this->assertFalse( $read['open'] );
        $this->assertSame( 'cap_reached', $read['reason'] );
        $this->assertSame( 'All places taken.', $read['message'] );
        $this->assertSame( 2, $read['count'] );
        $this->assertSame( 2, $read['max_entries'] );
    }

    public function test_get_form_reports_a_form_closed_by_date() {
        $form_id = $this->form_id( 'past' );
        $this->assertNotWPError( $this->create( $form_id, array( 'closes_at' => '2020-01-01 17:00' ) ) );

        $read = $this->get( $form_id )['availability'];
        $this->assertFalse( $read['open'] );
        $this->assertSame( 'closed_date', $read['reason'] );
        $this->assertSame( 'This form is no longer accepting submissions.', $read['message'] );
        $this->assertSame( '2020-01-01T17:00:00-05:00', $read['closes_at_iso'] );
        $this->assertNull( $read['max_entries'] );
    }

    public function test_list_forms_includes_availability() {
        $form_id = $this->form_id( 'list' );
        $this->assertNotWPError( $this->create( $form_id, array( 'max_entries' => 0 ) ) );

        $response = $this->api->handle_list_forms( $this->request( 'GET', array( 'page' => 1, 'per_page' => 100 ) ) );
        $items    = $response->get_data()['data'];
        $mine     = array_values( array_filter( $items, function ( $item ) use ( $form_id ) {
            return $item['id'] === $form_id;
        } ) );

        $this->assertCount( 1, $mine );
        $this->assertFalse( $mine[0]['availability']['open'] );
        $this->assertSame( 'cap_reached', $mine[0]['availability']['reason'] );
        $this->assertSame( 0, $mine[0]['availability']['max_entries'] );
    }

    public function test_closed_test_submit_returns_form_closed_with_its_data_and_takes_no_place() {
        $form_id = $this->form_id( 'submit' );
        $this->assertNotWPError( $this->create( $form_id, array( 'closes_at' => '2020-01-01' ) ) );
        pforms()->registry->register( $form_id, json_decode( $this->config( array( 'closes_at' => '2020-01-01' ) ), true ) );

        foreach ( array( true, false ) as $dry_run ) {
            $request = new \WP_REST_Request( 'POST', '/fre/v1/connector/forms/' . $form_id . '/submit' );
            $request->set_param( 'form_id', $form_id );
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( wp_json_encode( array(
                'data'    => array( 'email' => 'maya@example.com' ),
                'options' => array( 'dry_run' => $dry_run ),
            ) ) );

            $result = $this->api->handle_submit( $request );
            $this->assertWPError( $result );
            $this->assertSame( 'form_closed', $result->get_error_code() );
            $data = $result->get_error_data();
            $this->assertSame( 400, $data['status'] );
            $this->assertSame( $form_id, $data['form_id'], 'The refusal keeps its data through the REST handler.' );
            $this->assertSame( 'closed_date', $data['reason'] );
            $this->assertArrayHasKey( 'opens_at', $data );
            $this->assertArrayHasKey( 'closes_at', $data );
        }

        $this->assertSame( 0, $this->get( $form_id )['availability']['count'] );
    }

    public function test_a_validation_failure_keeps_its_field_errors_through_the_rest_handler() {
        $form_id = $this->form_id( 'fields' );
        $this->assertNotWPError( $this->create( $form_id, null ) );
        pforms()->registry->register( $form_id, json_decode( $this->config(), true ) );

        $request = new \WP_REST_Request( 'POST', '/fre/v1/connector/forms/' . $form_id . '/submit' );
        $request->set_param( 'form_id', $form_id );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( wp_json_encode( array(
            'data'    => array( 'email' => '' ),
            'options' => array( 'dry_run' => true ),
        ) ) );

        $result = $this->api->handle_submit( $request );
        $this->assertWPError( $result );
        $this->assertSame( 'validation_failed', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 400, $data['status'] );
        $this->assertArrayHasKey( 'field_errors', $data, 'CONNECTOR_SPEC.md §7 promises data.field_errors.' );
        $this->assertArrayHasKey( 'email', $data['field_errors'] );
    }
}
