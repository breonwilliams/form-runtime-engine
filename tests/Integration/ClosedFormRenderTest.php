<?php
/**
 * Rendering a form that is not accepting submissions (P4).
 *
 * A form closed when the page renders shows its message instead of the
 * form, in the same role="alert" `.fre-form__messages` container (with the
 * `.fre-form--submitted` state) the success confirmation leaves behind. An
 * open form with availability rules renders exactly as one without them.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

class ClosedFormRenderTest extends IntegrationTestCase {

    private function fields() {
        return array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
        );
    }

    private function register_with( $form_id, array $settings ) {
        $this->assertTrue( pforms_register_form( $form_id, array(
            'title'    => 'Signup',
            'fields'   => $this->fields(),
            'settings' => $settings,
        ) ) );
    }

    private function shortcode( $form_id ) {
        return do_shortcode( '[pforms_form id="' . $form_id . '"]' );
    }

    private function assert_no_form( $html ) {
        $this->assertStringNotContainsString( '<form', $html );
        $this->assertStringNotContainsString( 'type="submit"', $html );
        $this->assertStringNotContainsString( 'fre-form__submit', $html );
        $this->assertStringNotContainsString( '<button', $html );
        $this->assertStringNotContainsString( 'pforms_field_', $html );
        $this->assertStringNotContainsString( '<input', $html );
    }

    public function test_past_close_date_renders_the_closed_message_and_no_form() {
        $this->register_with( 'closed-date', array(
            'availability' => array( 'closes_at' => '2000-01-01', 'closed_message' => 'Registration has closed.' ),
        ) );

        $html = $this->shortcode( 'closed-date' );

        $this->assertStringContainsString( 'Registration has closed.', $html );
        $this->assert_no_form( $html );
        $this->assertStringContainsString( 'data-availability="closed_date"', $html );
    }

    public function test_full_form_renders_the_default_closed_message() {
        $this->register_with( 'full', array( 'availability' => array( 'max_entries' => 0 ) ) );

        $html = $this->shortcode( 'full' );

        $this->assertStringContainsString( 'This form is no longer accepting submissions.', $html );
        $this->assert_no_form( $html );
    }

    public function test_before_open_renders_the_before_open_message() {
        $this->register_with( 'later', array(
            'availability' => array( 'opens_at' => '2099-01-01 09:00', 'before_open_message' => 'Opens in 2099.' ),
        ) );

        $html = $this->shortcode( 'later' );

        $this->assertStringContainsString( 'Opens in 2099.', $html );
        $this->assert_no_form( $html );
    }

    public function test_closed_message_is_announced_like_the_confirmation() {
        $this->register_with( 'a11y', array( 'availability' => array( 'max_entries' => 0 ) ) );

        $html = $this->shortcode( 'a11y' );

        // The container the confirmation is written to and focused in.
        $this->assertMatchesRegularExpression(
            '/<div class="fre-form__messages" role="alert" aria-live="polite" tabindex="-1"><div class="fre-form__message fre-form__message--closed">/',
            $html
        );
        // The state showConfirmation() puts a form in.
        $this->assertMatchesRegularExpression( '/class="fre-form fre-form--closed fre-form--submitted[ "]/', $html );
    }

    public function test_closed_message_markup_is_escaped_like_success_message() {
        $this->register_with( 'xss', array(
            'availability' => array(
                'max_entries'    => 0,
                'closed_message' => 'Closed <script>alert(1)</script> <b>now</b>',
            ),
        ) );

        $html = $this->shortcode( 'xss' );

        $this->assertStringNotContainsString( '<script', $html );
        $this->assertStringNotContainsString( '<b>', $html );
        $this->assertStringContainsString( '&lt;script&gt;alert(1)&lt;/script&gt;', $html );
    }

    public function test_title_and_classes_follow_the_form_settings() {
        $this->register_with( 'styled', array(
            'show_title'    => true,
            'theme_variant' => 'dark',
            'css_class'     => 'my-form',
            'availability'  => array( 'max_entries' => 0 ),
        ) );

        $html = $this->shortcode( 'styled' );

        $this->assertStringContainsString( '<h3 class="fre-form__title">Signup</h3>', $html );
        $this->assertStringContainsString( 'fre-form--dark', $html );
        $this->assertStringContainsString( 'my-form', $html );
    }

    public function test_open_form_with_rules_renders_exactly_like_one_without() {
        $this->register_with( 'plain', array() );
        $this->register_with( 'ruled', array(
            'availability' => array( 'opens_at' => '2000-01-01', 'closes_at' => '2099-01-01', 'max_entries' => 1000 ),
        ) );

        $normalise = static function ( $html, $form_id ) {
            $html = str_replace( $form_id, 'FORMID', $html );
            // Per-form, per-request values.
            $html = preg_replace( '/_pforms_hp_[0-9a-f]+/', '_pforms_hp_X', $html );
            return preg_replace( '/(name="(?:_wpnonce|_pforms_timing_token)" value=")[^"]*"/', '$1X"', $html );
        };

        $plain = $this->shortcode( 'plain' );
        $ruled = $this->shortcode( 'ruled' );

        $this->assertStringContainsString( '<form', $ruled );
        $this->assertStringContainsString( 'pforms_field_name', $ruled );
        $this->assertSame( $normalise( $plain, 'plain' ), $normalise( $ruled, 'ruled' ) );
    }

    // ------------------------------------------------------------------
    // A-003: the default before-open message names the site timezone.
    // ------------------------------------------------------------------

    /**
     * The default before-open message for an opening at $opens_at (site time),
     * read at the epoch, on a site with the given timezone settings.
     */
    private function before_open_default( $opens_at, $timezone_string, $gmt_offset = 0, $form_id = null ) {
        $form_id = $form_id ? $form_id : 'tz-' . md5( $opens_at . $timezone_string . $gmt_offset );
        update_option( 'timezone_string', $timezone_string );
        update_option( 'gmt_offset', $gmt_offset );
        update_option( 'date_format', 'F j, Y' );
        update_option( 'time_format', 'g:i a' );

        $this->assertTrue( pforms_register_form( $form_id, array(
            'fields'   => $this->fields(),
            'settings' => array( 'availability' => array( 'opens_at' => $opens_at ) ),
        ) ) );

        $result = \PForms_Availability::check( $form_id, array( 'now' => 0, 'count' => 0 ) );
        $this->assertSame( 'not_yet_open', $result['reason'] );

        return $result['message'];
    }

    public function test_before_open_default_names_new_york_winter_zone() {
        $this->assertSame(
            'This form opens on January 15, 2027 at 9:00 am EST.',
            $this->before_open_default( '2027-01-15 09:00', 'America/New_York' )
        );
    }

    public function test_before_open_default_names_new_york_summer_zone() {
        $this->assertSame(
            'This form opens on July 15, 2027 at 9:00 am EDT.',
            $this->before_open_default( '2027-07-15 09:00', 'America/New_York' )
        );
    }

    public function test_before_open_default_on_offset_only_site_reads_utc_offset() {
        $this->assertSame(
            'This form opens on March 15, 2027 at 9:00 am UTC+5:30.',
            $this->before_open_default( '2027-03-15 09:00', '', 5.5 )
        );
        $this->assertSame(
            'This form opens on March 15, 2027 at 9:00 am UTC-5.',
            $this->before_open_default( '2027-03-15 09:00', '', -5 )
        );
    }

    public function test_before_open_default_names_auckland_zone() {
        $this->assertSame(
            'This form opens on March 15, 2027 at 9:00 am NZDT.',
            $this->before_open_default( '2027-03-15 09:00', 'Pacific/Auckland' )
        );
    }

    public function test_before_open_default_renders_with_the_zone_and_custom_text_is_untouched() {
        $this->before_open_default( '2099-07-15 09:00', 'America/New_York', 0, 'tz-render' );
        $html = $this->shortcode( 'tz-render' );
        $this->assertStringContainsString( 'This form opens on July 15, 2099 at 9:00 am EDT.', $html );
        $this->assert_no_form( $html );

        $this->register_with( 'custom-open', array(
            'availability' => array( 'opens_at' => '2099-07-15 09:00', 'before_open_message' => 'Opens in July.' ),
        ) );
        $this->assertSame(
            'Opens in July.',
            \PForms_Availability::check( 'custom-open', array( 'now' => 0, 'count' => 0 ) )['message']
        );
    }
}
