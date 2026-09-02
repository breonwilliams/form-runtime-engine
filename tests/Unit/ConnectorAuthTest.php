<?php
/**
 * Unit tests for PForms_Connector_Auth.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the Phase 2 permission callback stack.
 *
 * Focus: the four distinct failure modes (disabled, not logged in, no cap,
 * entry-read gate) each return the correct error code and HTTP status.
 * Plus the rate-limit enforcement's under/over behavior.
 */
class ConnectorAuthTest extends UnitTestCase {

    private $options   = array();
    private $transients = array();
    private $is_logged_in = false;
    private $has_cap      = false;
    private $current_user_id = 0;

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-capabilities.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-settings.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-auth.php';

        $this->options        = array();
        $this->transients     = array();
        $this->is_logged_in   = false;
        $this->has_cap        = false;
        $this->current_user_id = 0;

        Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
            return $this->options[ $name ] ?? $default;
        } );
        Functions\when( 'update_option' )->alias( function ( $name, $value ) {
            $this->options[ $name ] = $value;
            return true;
        } );
        Functions\when( 'delete_option' )->alias( function ( $name ) {
            unset( $this->options[ $name ] );
            return true;
        } );

        Functions\when( 'is_user_logged_in' )->alias( function () {
            return $this->is_logged_in;
        } );
        Functions\when( 'current_user_can' )->alias( function ( $cap ) {
            return $this->has_cap && 'pforms_manage_forms' === $cap;
        } );
        Functions\when( 'get_current_user_id' )->alias( function () {
            return $this->current_user_id;
        } );

        Functions\when( 'get_transient' )->alias( function ( $key ) {
            return $this->transients[ $key ] ?? false;
        } );
        Functions\when( 'set_transient' )->alias( function ( $key, $value, $ttl ) {
            $this->transients[ $key ] = $value;
            $this->options[ '_transient_timeout_' . $key ] = time() + $ttl;
            return true;
        } );

        Functions\when( 'sanitize_key' )->alias( function ( $k ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $k ) );
        } );

        if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
            define( 'MINUTE_IN_SECONDS', 60 );
        }
    }

    /**
     * Helper: set the scenario the permission stack should observe.
     */
    private function scenario( array $s ) {
        $this->options['pforms_connector_enabled']            = $s['enabled'] ?? false;
        $this->options['pforms_connector_entry_read_enabled'] = $s['entry_read'] ?? false;
        $this->is_logged_in                                 = $s['logged_in'] ?? false;
        $this->has_cap                                      = $s['has_cap'] ?? false;
        $this->current_user_id                              = $s['user_id'] ?? 0;
    }

    public function test_disabled_connector_returns_403() {
        $this->scenario( array( 'enabled' => false, 'logged_in' => true, 'has_cap' => true, 'user_id' => 1 ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'preflight', false, null );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'connector_disabled', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_unauthenticated_returns_401() {
        $this->scenario( array( 'enabled' => true, 'logged_in' => false ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'preflight', false, null );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rest_not_logged_in', $result->get_error_code() );
        $this->assertSame( 401, $result->get_error_data()['status'] );
    }

    public function test_missing_capability_returns_403_forbidden() {
        $this->scenario( array( 'enabled' => true, 'logged_in' => true, 'has_cap' => false ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'preflight', false, null );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rest_forbidden', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_entry_read_gate_blocks_when_off() {
        $this->scenario( array(
            'enabled'    => true,
            'entry_read' => false,
            'logged_in'  => true,
            'has_cap'    => true,
            'user_id'    => 1,
        ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'list_entries', true, null );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'entry_access_disabled', $result->get_error_code() );
        $this->assertSame( 403, $result->get_error_data()['status'] );
    }

    public function test_entry_read_gate_passes_when_on() {
        $this->scenario( array(
            'enabled'    => true,
            'entry_read' => true,
            'logged_in'  => true,
            'has_cap'    => true,
            'user_id'    => 1,
        ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'list_entries', true, null );

        $this->assertTrue( $result );
    }

    public function test_non_entry_route_ignores_entry_read_gate() {
        $this->scenario( array(
            'enabled'    => true,
            'entry_read' => false, // off, but we're not hitting an entry route
            'logged_in'  => true,
            'has_cap'    => true,
            'user_id'    => 1,
        ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'list_forms', false, null );

        $this->assertTrue( $result );
    }

    public function test_full_permission_stack_passes_when_all_checks_satisfied() {
        $this->scenario( array(
            'enabled'   => true,
            'logged_in' => true,
            'has_cap'   => true,
            'user_id'   => 42,
        ) );

        $result = \PForms_Connector_Auth::run_permission_stack( 'preflight', false, null );

        $this->assertTrue( $result );
    }

    public function test_rate_limit_under_limit_allows() {
        $result = \PForms_Connector_Auth::enforce_rate_limit( 'preflight', 1 );
        $this->assertTrue( $result );
    }

    public function test_rate_limit_increments_counter() {
        \PForms_Connector_Auth::enforce_rate_limit( 'preflight', 1 );
        \PForms_Connector_Auth::enforce_rate_limit( 'preflight', 1 );
        \PForms_Connector_Auth::enforce_rate_limit( 'preflight', 1 );

        $key = 'pforms_connector_rate_preflight_1';
        $this->assertSame( 3, (int) $this->transients[ $key ] );
    }

    public function test_rate_limit_exceeded_returns_429() {
        // create_form has a limit of 10. Simulate 10 prior calls then check 11th.
        $key = 'pforms_connector_rate_create_form_42';
        $this->transients[ $key ] = 10;
        $this->options[ '_transient_timeout_' . $key ] = time() + 30;

        $result = \PForms_Connector_Auth::enforce_rate_limit( 'create_form', 42 );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'rate_limit_exceeded', $result->get_error_code() );
        $data = $result->get_error_data();
        $this->assertSame( 429, $data['status'] );
        $this->assertSame( 10, $data['limit'] );
        $this->assertSame( 'create_form', $data['route'] );
        $this->assertGreaterThan( 0, $data['retry_after'] );
    }

    public function test_rate_limit_bucket_is_per_user() {
        // User 1 exhausts delete_form (limit 5).
        $user1_key = 'pforms_connector_rate_delete_form_1';
        $this->transients[ $user1_key ] = 5;
        $this->options[ '_transient_timeout_' . $user1_key ] = time() + 30;

        // User 2 should still have full allowance.
        $result = \PForms_Connector_Auth::enforce_rate_limit( 'delete_form', 2 );

        $this->assertTrue( $result, 'User 2 is unaffected by User 1 exhausting the bucket.' );
    }

    public function test_unknown_route_key_falls_back_to_strict_default() {
        // DEFAULT_RATE_LIMIT is 10. Simulate being at that limit.
        $key = 'pforms_connector_rate_unknown_route_1';
        $this->transients[ $key ] = 10;
        $this->options[ '_transient_timeout_' . $key ] = time() + 30;

        $result = \PForms_Connector_Auth::enforce_rate_limit( 'unknown_route', 1 );

        $this->assertInstanceOf(
            'WP_Error',
            $result,
            'Unknown routes hit the strict default of 10/min — this prevents wiring up a route without configuring its limit.'
        );
    }
}
