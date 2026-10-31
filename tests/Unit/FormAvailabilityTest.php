<?php
/**
 * Unit tests for PForms_Availability — the one "is this form open?" decision.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Availability decision, settings normalisation and save-time validation.
 */
class FormAvailabilityTest extends UnitTestCase {

    /**
     * Options served by the get_option stub.
     *
     * @var array
     */
    private $options = array();

    /**
     * PHP default timezone before the test, restored in tear_down.
     *
     * @var string
     */
    private $php_timezone;

    protected function set_up() {
        parent::set_up();

        // A server zone that is neither UTC nor the site zone, so a
        // comparison done in server time fails the timezone tests too.
        $this->php_timezone = date_default_timezone_get();
        date_default_timezone_set( 'America/Sao_Paulo' );

        $this->options = array(
            'date_format'     => 'F j, Y',
            'time_format'     => 'g:i a',
            'timezone_string' => 'Pacific/Auckland',
            'gmt_offset'      => 0,
        );

        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
            return array_key_exists( $option, $this->options ) ? $this->options[ $option ] : $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value ) {
            $this->options[ $option ] = $value;
            return true;
        } );

        // Mirrors core: wp_timezone() is built from timezone_string, else
        // from gmt_offset.
        Functions\when( 'wp_timezone' )->alias( function () {
            $tz = get_option( 'timezone_string' );
            if ( $tz ) {
                return new \DateTimeZone( $tz );
            }
            $offset  = (float) get_option( 'gmt_offset' );
            $hours   = (int) $offset;
            $minutes = abs( ( $offset - $hours ) * 60 );
            return new \DateTimeZone( sprintf( '%s%02d:%02d', $offset < 0 ? '-' : '+', abs( $hours ), $minutes ) );
        } );

        Functions\when( 'wp_date' )->alias( function ( $format, $timestamp = null, $timezone = null ) {
            $timezone = $timezone ? $timezone : wp_timezone();
            return ( new \DateTimeImmutable( '@' . (int) $timestamp ) )->setTimezone( $timezone )->format( $format );
        } );

        Functions\when( 'wp_cache_get' )->justReturn( false );
        Functions\when( 'wp_cache_set' )->justReturn( true );
        Functions\when( 'wp_cache_delete' )->justReturn( true );
        Functions\when( 'wp_generate_password' )->alias( function ( $length = 12 ) {
            return str_repeat( 'x', $length );
        } );
        Functions\when( 'wp_json_encode' )->alias( function ( $data, $options = 0 ) {
            return json_encode( $data, $options );
        } );
    }

    protected function tear_down() {
        date_default_timezone_set( $this->php_timezone );
        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * A config with the given availability block.
     *
     * @param array|null $availability settings.availability, null = omit.
     * @return array
     */
    private function config( $availability = null ) {
        $config = array(
            'fields'   => array(
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
            ),
            'settings' => array(),
        );
        if ( null !== $availability ) {
            $config['settings']['availability'] = $availability;
        }
        return $config;
    }

    /**
     * Unix timestamp of a UTC wall-clock time, computed independently of
     * the code under test.
     *
     * @param string $utc "Y-m-d H:i:s" in UTC.
     * @return int
     */
    private function utc( $utc ) {
        return ( new \DateTimeImmutable( $utc, new \DateTimeZone( 'UTC' ) ) )->getTimestamp();
    }

    /**
     * Errors the save-time validator returns for a settings.availability block.
     *
     * @param mixed $availability settings.availability.
     * @return string[]
     */
    private function save_errors( $availability ) {
        $result = \PForms_JSON_Schema_Validator::validate( $this->config_with_raw( $availability ) );
        return $result['errors'];
    }

    /**
     * Config with an availability value that may be anything (even null).
     *
     * @param mixed $availability Raw value.
     * @return array
     */
    private function config_with_raw( $availability ) {
        $config                             = $this->config();
        $config['settings']['availability'] = $availability;
        return $config;
    }

    // ------------------------------------------------------------------
    // No settings: every existing form stays open.
    // ------------------------------------------------------------------

    public function test_form_without_any_settings_is_open() {
        $config = array( 'fields' => array( array( 'key' => 'name', 'type' => 'text' ) ) );

        $result = \PForms_Availability::check( $config, array( 'count' => 1000000 ) );

        $this->assertTrue( $result['open'] );
        $this->assertSame( 'open', $result['reason'] );
        $this->assertSame( '', $result['message'] );
        $this->assertNull( $result['cap'] );
        $this->assertNull( $result['opens_at'] );
        $this->assertNull( $result['closes_at'] );
    }

    public function test_form_with_settings_but_no_availability_is_open() {
        $config = $this->config();
        $config['settings']['success_message'] = 'Thanks';

        $this->assertTrue( \PForms_Availability::check( $config, array( 'count' => 50 ) )['open'] );
    }

    public function test_registry_normalises_a_form_without_availability_to_open() {
        $registry = new \PForms_Registry();
        $this->assertTrue( $registry->register( 'legacy', $this->config() ) );

        $config = $registry->get( 'legacy' );

        $this->assertSame( \PForms_Availability::DEFAULTS, $config['settings']['availability'] );
        $this->assertTrue( \PForms_Availability::check( $config, array( 'count' => 99 ) )['open'] );
    }

    public function test_registry_fills_missing_availability_keys() {
        $registry = new \PForms_Registry();
        $registry->register( 'capped', $this->config( array( 'max_entries' => 3 ) ) );

        $availability = $registry->get( 'capped' )['settings']['availability'];

        $this->assertSame( 3, $availability['max_entries'] );
        $this->assertArrayHasKey( 'opens_at', $availability );
        $this->assertNull( $availability['opens_at'] );
    }

    public function test_form_id_is_resolved_through_the_runtime_registry() {
        $registry = new \PForms_Registry();
        $registry->register( 'full', $this->config( array( 'max_entries' => 0 ) ) );
        // Same convention as ProcessSubmissionTest: pforms() is defined once
        // per process via eval and serves $GLOBALS['__fre_test_plugin'].
        $previous = null;
        if ( ! function_exists( 'pforms' ) ) {
            $GLOBALS['__fre_test_plugin'] = new class( $registry ) {
                public $registry;
                public function __construct( $registry ) { $this->registry = $registry; }
            };
            eval( 'function pforms() { return $GLOBALS["__fre_test_plugin"]; }' );
        } else {
            $previous                               = $GLOBALS['__fre_test_plugin']->registry;
            $GLOBALS['__fre_test_plugin']->registry = $registry;
        }

        try {
            $result  = \PForms_Availability::check( 'full', array( 'count' => 0 ) );
            $missing = \PForms_Availability::check( 'nope' );
        } finally {
            if ( null !== $previous ) {
                $GLOBALS['__fre_test_plugin']->registry = $previous;
            }
        }

        $this->assertFalse( $result['open'] );
        $this->assertSame( 'cap_reached', $result['reason'] );
        $this->assertSame( 'full', $result['form_id'] );

        $this->assertFalse( $missing['open'] );
        $this->assertSame( 'form_not_found', $missing['reason'] );
    }

    // ------------------------------------------------------------------
    // Unlimited is explicit.
    // ------------------------------------------------------------------

    public function test_absent_cap_means_no_limit() {
        $config = $this->config( array( 'closed_message' => 'Full.' ) );

        $result = \PForms_Availability::check( $config, array( 'count' => PHP_INT_MAX ) );

        $this->assertTrue( $result['open'] );
        $this->assertNull( $result['cap'] );
    }

    public function test_null_cap_is_the_same_as_absent() {
        $result = \PForms_Availability::check( $this->config( array( 'max_entries' => null ) ), array( 'count' => 10 ) );

        $this->assertTrue( $result['open'] );
        $this->assertNull( $result['cap'] );
        $this->assertSame( array(), $this->save_errors( array( 'max_entries' => null ) ) );
    }

    public function test_cap_of_zero_means_closed_not_unlimited() {
        $result = \PForms_Availability::check( $this->config( array( 'max_entries' => 0 ) ), array( 'count' => 0 ) );

        $this->assertFalse( $result['open'] );
        $this->assertSame( 'cap_reached', $result['reason'] );
        $this->assertSame( 0, $result['cap'] );
        $this->assertSame( array(), $this->save_errors( array( 'max_entries' => 0 ) ), '0 is a valid cap.' );
    }

    public function test_cap_closes_when_count_reaches_it() {
        $config = $this->config( array( 'max_entries' => 3 ) );

        $this->assertTrue( \PForms_Availability::check( $config, array( 'count' => 2 ) )['open'] );

        $full = \PForms_Availability::check( $config, array( 'count' => 3 ) );
        $this->assertFalse( $full['open'] );
        $this->assertSame( 'cap_reached', $full['reason'] );
        $this->assertSame( 3, $full['count'] );
        $this->assertSame( 'This form is no longer accepting submissions.', $full['message'] );
    }

    /**
     * @dataProvider refused_caps
     */
    public function test_validator_refuses_a_cap_that_is_not_a_whole_number( $cap ) {
        $errors = $this->save_errors( array( 'max_entries' => $cap ) );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'settings.availability.max_entries', $errors[0] );
    }

    public function refused_caps() {
        return array(
            'negative'       => array( -1 ),
            'fraction'       => array( 2.5 ),
            'float integral' => array( 5.0 ),
            'numeric string' => array( '5' ),
            'unlimited'      => array( 'unlimited' ),
            'empty string'   => array( '' ),
            'boolean'        => array( true ),
            'array'          => array( array( 5 ) ),
        );
    }

    /**
     * A refused value never reaches "unlimited" at decision time either.
     *
     * @dataProvider refused_caps
     */
    public function test_a_refused_cap_reaching_check_fails_closed( $cap ) {
        $result = \PForms_Availability::check( $this->config( array( 'max_entries' => $cap ) ), array( 'count' => 0 ) );

        $this->assertFalse( $result['open'] );
        $this->assertSame( 'invalid_settings', $result['reason'] );
    }

    public function test_validator_refuses_unknown_availability_key() {
        $errors = $this->save_errors( array( 'max_entry' => 5 ) );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'max_entry', $errors[0] );
    }

    public function test_validator_refuses_non_object_availability() {
        $errors = $this->save_errors( 'closed' );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'settings.availability', $errors[0] );
    }

    public function test_validator_accepts_a_complete_valid_block_without_warnings() {
        $result = \PForms_JSON_Schema_Validator::validate( $this->config( array(
            'opens_at'            => '2027-03-15',
            'closes_at'           => '2027-03-20 17:00',
            'max_entries'         => 30,
            'closed_message'      => 'Registration has closed.',
            'before_open_message' => 'Registration opens soon.',
        ) ) );

        $this->assertTrue( $result['valid'] );
        $this->assertSame( array(), $result['warnings'], 'availability is a known settings key.' );
    }

    // ------------------------------------------------------------------
    // Dates: format and order (Q-007).
    // ------------------------------------------------------------------

    /**
     * @dataProvider malformed_dates
     */
    public function test_validator_refuses_a_malformed_date( $value ) {
        foreach ( array( 'opens_at', 'closes_at' ) as $key ) {
            $errors = $this->save_errors( array( $key => $value ) );

            $this->assertCount( 1, $errors, $key );
            $this->assertStringContainsString( 'settings.availability.' . $key, $errors[0] );
        }
    }

    public function malformed_dates() {
        return array(
            'impossible day'   => array( '2027-02-30' ),
            'impossible hour'  => array( '2027-03-15 25:00' ),
            'impossible min'   => array( '2027-03-15 17:60' ),
            'iso T separator'  => array( '2027-03-15T17:00' ),
            'with seconds'     => array( '2027-03-15 17:00:00' ),
            'with offset'      => array( '2027-03-15 17:00+13:00' ),
            'day first'        => array( '15/03/2027' ),
            'words'            => array( 'next friday' ),
            'empty string'     => array( '' ),
            'integer'          => array( 20270315 ),
        );
    }

    public function test_validator_accepts_both_date_formats() {
        $this->assertSame( array(), $this->save_errors( array( 'opens_at' => '2027-03-15' ) ) );
        $this->assertSame( array(), $this->save_errors( array( 'opens_at' => '2027-03-15 09:30' ) ) );
        $this->assertSame( array(), $this->save_errors( array( 'closes_at' => '2027-03-15' ) ) );
        $this->assertSame( array(), $this->save_errors( array( 'closes_at' => '2027-03-15 17:00' ) ) );
    }

    public function test_validator_refuses_close_at_the_open_moment() {
        $errors = $this->save_errors( array( 'opens_at' => '2027-03-15 09:00', 'closes_at' => '2027-03-15 09:00' ) );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'settings.availability.closes_at', $errors[0] );
    }

    public function test_validator_refuses_close_before_open() {
        $errors = $this->save_errors( array( 'opens_at' => '2027-03-15', 'closes_at' => '2027-03-14' ) );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'settings.availability.closes_at', $errors[0] );

        $errors = $this->save_errors( array( 'opens_at' => '2027-03-15 09:00', 'closes_at' => '2027-03-15 08:59' ) );
        $this->assertCount( 1, $errors );
    }

    public function test_same_day_date_only_window_is_a_whole_day_and_valid() {
        $this->assertSame( array(), $this->save_errors( array( 'opens_at' => '2027-03-15', 'closes_at' => '2027-03-15' ) ) );

        // Auckland is UTC+13 in March: 2027-03-15 00:00 local = 2027-03-14 11:00 UTC.
        $config = $this->config( array( 'opens_at' => '2027-03-15', 'closes_at' => '2027-03-15' ) );
        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 11:00:00' ) ) )['open'] );
        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-15 10:59:59' ) ) )['open'] );
        $this->assertFalse( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-15 11:00:00' ) ) )['open'] );
    }

    public function test_date_only_open_starts_at_local_midnight() {
        // 2027-03-15 00:00 Pacific/Auckland (NZDT, UTC+13) = 2027-03-14 11:00 UTC.
        $config = $this->config( array( 'opens_at' => '2027-03-15' ) );

        $before = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 10:59:59' ) ) );
        $this->assertFalse( $before['open'] );
        $this->assertSame( 'not_yet_open', $before['reason'] );

        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 11:00:00' ) ) )['open'] );
    }

    public function test_date_only_close_is_inclusive_to_the_end_of_that_day() {
        // End of 2027-03-20 Pacific/Auckland: 23:59:59 NZDT = 10:59:59 UTC.
        $config = $this->config( array( 'closes_at' => '2027-03-20' ) );

        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-20 10:59:59' ) ) )['open'], '23:59:59 local is still open.' );

        $after = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-20 11:00:00' ) ) );
        $this->assertFalse( $after['open'], '00:00 the next day is closed.' );
        $this->assertSame( 'closed_date', $after['reason'] );
        $this->assertSame( $this->utc( '2027-03-20 11:00:00' ), $after['closes_at'] );
    }

    // ------------------------------------------------------------------
    // Timezone: the flip happens at the configured LOCAL minute.
    // ------------------------------------------------------------------

    public function test_opens_at_the_configured_local_minute_in_auckland() {
        // 2027-03-15 09:00 Pacific/Auckland (UTC+13) = 2027-03-14 20:00 UTC.
        // In UTC that same wall-clock is 13 hours later, in server time
        // (America/Sao_Paulo, UTC-3) 16 hours later.
        $config = $this->config( array( 'opens_at' => '2027-03-15 09:00' ) );

        $minute_before = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 19:59:00' ) ) );
        $at            = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 20:00:00' ) ) );
        $minute_after  = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-14 20:01:00' ) ) );

        $this->assertFalse( $minute_before['open'] );
        $this->assertSame( 'not_yet_open', $minute_before['reason'] );
        $this->assertTrue( $at['open'], 'Opens AT opens_at.' );
        $this->assertTrue( $minute_after['open'] );
        $this->assertSame( $this->utc( '2027-03-14 20:00:00' ), $at['opens_at'] );
    }

    public function test_closes_at_the_configured_local_minute_in_auckland() {
        // 2027-03-20 17:00 Pacific/Auckland (UTC+13) = 2027-03-20 04:00 UTC.
        $config = $this->config( array( 'opens_at' => '2027-03-15 09:00', 'closes_at' => '2027-03-20 17:00' ) );

        $minute_before = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-20 03:59:00' ) ) );
        $at            = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-20 04:00:00' ) ) );
        $minute_after  = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-20 04:01:00' ) ) );

        $this->assertTrue( $minute_before['open'] );
        $this->assertFalse( $at['open'], 'Closed FROM closes_at.' );
        $this->assertSame( 'closed_date', $at['reason'] );
        $this->assertFalse( $minute_after['open'] );
    }

    public function test_flips_at_the_local_minute_in_los_angeles() {
        // 2027-07-01 08:00 America/Los_Angeles (PDT, UTC-7) = 15:00 UTC.
        $this->options['timezone_string'] = 'America/Los_Angeles';
        $config = $this->config( array( 'opens_at' => '2027-07-01 08:00', 'closes_at' => '2027-07-01 18:30' ) );

        $this->assertFalse( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-07-01 14:59:00' ) ) )['open'] );
        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-07-01 15:00:00' ) ) )['open'] );
        // 18:30 PDT = 01:30 UTC the next day.
        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-07-02 01:29:00' ) ) )['open'] );
        $this->assertFalse( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-07-02 01:30:00' ) ) )['open'] );
    }

    public function test_offset_only_site_uses_gmt_offset() {
        $this->options['timezone_string'] = '';
        $this->options['gmt_offset']      = -5;
        // 2027-03-15 09:00 at UTC-5 = 14:00 UTC.
        $config = $this->config( array( 'opens_at' => '2027-03-15 09:00' ) );

        $this->assertFalse( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-15 13:59:00' ) ) )['open'] );
        $this->assertTrue( \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-15 14:00:00' ) ) )['open'] );
    }

    // ------------------------------------------------------------------
    // Messages (Q-006).
    // ------------------------------------------------------------------

    public function test_before_open_default_names_the_local_opening_date_and_time() {
        $config = $this->config( array( 'opens_at' => '2027-03-15 09:00', 'closed_message' => 'Registration has closed.' ) );

        $result = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-01 00:00:00' ) ) );

        // In UTC this would read "March 14, 2027 at 8:00 pm". A prefix check
        // (Q-011): the timezone label after the time is pinned by P4's tests.
        $this->assertStringStartsWith( 'This form opens on March 15, 2027 at 9:00 am', $result['message'] );
    }

    public function test_custom_before_open_message_is_used() {
        $config = $this->config( array( 'opens_at' => '2027-03-15', 'before_open_message' => 'Sign-ups open in spring.' ) );

        $result = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-01 00:00:00' ) ) );

        $this->assertSame( 'Sign-ups open in spring.', $result['message'] );
    }

    public function test_custom_closed_message_covers_both_full_and_past_close_date() {
        $availability = array( 'closes_at' => '2027-03-20', 'max_entries' => 2, 'closed_message' => 'Registration has closed.' );
        $config       = $this->config( $availability );

        $full = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-03-10 00:00:00' ), 'count' => 2 ) );
        $late = \PForms_Availability::check( $config, array( 'now' => $this->utc( '2027-04-01 00:00:00' ), 'count' => 0 ) );

        $this->assertSame( 'cap_reached', $full['reason'] );
        $this->assertSame( 'Registration has closed.', $full['message'] );
        $this->assertSame( 'closed_date', $late['reason'] );
        $this->assertSame( 'Registration has closed.', $late['message'] );
    }

    public function test_default_closed_message_when_none_is_set() {
        $late = \PForms_Availability::check(
            $this->config( array( 'closes_at' => '2027-03-20' ) ),
            array( 'now' => $this->utc( '2027-04-01 00:00:00' ) )
        );

        $this->assertSame( 'This form is no longer accepting submissions.', $late['message'] );
    }

    /**
     * @dataProvider refused_messages
     */
    public function test_validator_refuses_empty_or_non_text_messages( $value ) {
        foreach ( array( 'closed_message', 'before_open_message' ) as $key ) {
            $errors = $this->save_errors( array( $key => $value ) );

            $this->assertCount( 1, $errors );
            $this->assertStringContainsString( 'settings.availability.' . $key, $errors[0] );
        }
    }

    public function refused_messages() {
        return array(
            'empty'      => array( '' ),
            'whitespace' => array( '   ' ),
            'number'     => array( 5 ),
            'array'      => array( array( 'Closed' ) ),
        );
    }

    // ------------------------------------------------------------------
    // The filter.
    // ------------------------------------------------------------------

    /**
     * Route pforms_form_availability through a callback.
     *
     * @param callable $callback Receives ( $result, $config, $context ).
     */
    private function on_availability_filter( callable $callback ) {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value, ...$args ) use ( $callback ) {
            if ( 'pforms_form_availability' === $tag ) {
                return $callback( $value, ...$args );
            }
            return $value;
        } );
    }

    public function test_filter_can_close_an_open_form_with_its_own_reason_and_message() {
        $seen = null;
        $this->on_availability_filter( function ( $result, $config, $context ) use ( &$seen ) {
            $seen = array( $result, $config, $context );
            $result['open']    = false;
            $result['reason']  = 'waitlist_only';
            $result['message'] = 'Join the waitlist instead.';
            return $result;
        } );

        $config = $this->config();
        $result = \PForms_Availability::check( $config, array( 'count' => 4, 'form_id' => 'camp' ) );

        $this->assertFalse( $result['open'] );
        $this->assertSame( 'waitlist_only', $result['reason'] );
        $this->assertSame( 'Join the waitlist instead.', $result['message'] );

        // The callback sees the unfiltered decision, the config and the count.
        $this->assertTrue( $seen[0]['open'] );
        $this->assertSame( 4, $seen[0]['count'] );
        $this->assertSame( 'camp', $seen[0]['form_id'] );
        $this->assertSame( $config, $seen[1] );
    }

    public function test_filter_can_open_a_closed_form_with_its_own_reason_and_message() {
        $this->on_availability_filter( function ( $result ) {
            if ( 'cap_reached' === $result['reason'] ) {
                $result['open']    = true;
                $result['reason']  = 'vip_override';
                $result['message'] = 'One extra place for you.';
            }
            return $result;
        } );

        $result = \PForms_Availability::check( $this->config( array( 'max_entries' => 1 ) ), array( 'count' => 1 ) );

        $this->assertTrue( $result['open'] );
        $this->assertSame( 'vip_override', $result['reason'] );
        $this->assertSame( 'One extra place for you.', $result['message'] );
    }

    public function test_filter_returning_garbage_keeps_the_original_decision() {
        $this->on_availability_filter( function () {
            return 'nonsense';
        } );

        $result = \PForms_Availability::check( $this->config( array( 'max_entries' => 0 ) ) );

        $this->assertFalse( $result['open'] );
        $this->assertSame( 'cap_reached', $result['reason'] );
    }

    // ------------------------------------------------------------------
    // Save paths: admin and connector.
    // ------------------------------------------------------------------

    /**
     * JSON config string with an availability block.
     *
     * @param array $availability settings.availability.
     * @return string
     */
    private function json( array $availability ) {
        return json_encode( $this->config( $availability ) );
    }

    public function test_admin_save_path_refuses_invalid_availability() {
        $bad_cap = \PForms_Forms_Manager::save_form( 'camp', 'Camp', $this->json( array( 'max_entries' => 'unlimited' ) ) );
        $this->assertInstanceOf( \WP_Error::class, $bad_cap );
        $this->assertStringContainsString( 'settings.availability.max_entries', $bad_cap->get_error_message() );

        $bad_date = \PForms_Forms_Manager::save_form( 'camp', 'Camp', $this->json( array( 'opens_at' => '2027-13-01' ) ) );
        $this->assertInstanceOf( \WP_Error::class, $bad_date );
        $this->assertStringContainsString( 'settings.availability.opens_at', $bad_date->get_error_message() );

        $bad_order = \PForms_Forms_Manager::save_form(
            'camp',
            'Camp',
            $this->json( array( 'opens_at' => '2027-03-20', 'closes_at' => '2027-03-19 23:00' ) )
        );
        $this->assertInstanceOf( \WP_Error::class, $bad_order );
        $this->assertStringContainsString( 'settings.availability.closes_at', $bad_order->get_error_message() );

        $this->assertArrayNotHasKey( 'camp', (array) ( $this->options['pforms_client_forms'] ?? array() ), 'Nothing was stored.' );

        $ok = \PForms_Forms_Manager::save_form( 'camp', 'Camp', $this->json( array( 'max_entries' => 0, 'closes_at' => '2027-03-20' ) ) );
        $this->assertIsArray( $ok );
    }

    /**
     * Minimal stand-in for WP_REST_Request.
     *
     * @param array $params Request params.
     * @return object
     */
    private function request( array $params ) {
        return new class( $params ) {
            private $params;
            public function __construct( $params ) {
                $this->params = $params;
            }
            public function get_param( $key ) {
                return $this->params[ $key ] ?? null;
            }
        };
    }

    public function test_connector_create_refuses_invalid_availability() {
        $api = new \PForms_Connector_API();

        $error = $api->handle_create_form( $this->request( array(
            'id'     => 'camp',
            'config' => $this->json( array( 'max_entries' => -1 ) ),
        ) ) );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertSame( 'schema_error', $error->get_error_code() );
        $this->assertStringContainsString( 'settings.availability.max_entries', $error->get_error_message() );
        $this->assertSame( 400, $error->get_error_data()['status'] );

        $error = $api->handle_create_form( $this->request( array(
            'id'     => 'camp',
            'config' => $this->json( array( 'opens_at' => '2027-03-15 09:00', 'closes_at' => '2027-03-15 09:00' ) ),
        ) ) );
        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'settings.availability.closes_at', $error->get_error_message() );

        $error = $api->handle_create_form( $this->request( array(
            'id'     => 'camp',
            'config' => $this->json( array( 'closes_at' => 'end of march' ) ),
        ) ) );
        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'settings.availability.closes_at', $error->get_error_message() );

        $this->assertArrayNotHasKey( 'camp', (array) ( $this->options['pforms_client_forms'] ?? array() ) );
    }

    public function test_connector_update_refuses_invalid_availability_and_keeps_the_old_config() {
        Functions\when( 'rest_ensure_response' )->returnArg( 1 );

        $original = $this->json( array( 'max_entries' => 30 ) );
        $saved    = \PForms_Forms_Repository::save( 'camp', array( 'config' => $original ) );
        $this->assertIsArray( $saved );

        $api   = new \PForms_Connector_API();
        $error = $api->handle_update_form( $this->request( array(
            'form_id' => 'camp',
            'config'  => $this->json( array( 'max_entries' => '' ) ),
        ) ) );

        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'settings.availability.max_entries', $error->get_error_message() );

        $error = $api->handle_update_form( $this->request( array(
            'form_id' => 'camp',
            'config'  => $this->json( array( 'opens_at' => '2027-03-15', 'closes_at' => '2027-03-14' ) ),
        ) ) );
        $this->assertInstanceOf( \WP_Error::class, $error );
        $this->assertStringContainsString( 'settings.availability.closes_at', $error->get_error_message() );

        $this->assertSame( $original, \PForms_Forms_Repository::get( 'camp' )['config'] );

        $ok = $api->handle_update_form( $this->request( array(
            'form_id' => 'camp',
            'config'  => $this->json( array( 'max_entries' => 0 ) ),
        ) ) );
        $this->assertIsArray( $ok );
        $this->assertTrue( $ok['success'] );
    }
}
