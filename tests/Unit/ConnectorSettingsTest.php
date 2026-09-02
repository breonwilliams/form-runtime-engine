<?php
/**
 * Unit tests for PForms_Connector_Settings.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the Phase 2 connector settings accessor.
 *
 * Focus: default-off posture, option round-trip, user meta round-trip.
 */
class ConnectorSettingsTest extends UnitTestCase {

    private $options   = array();
    private $user_meta = array();

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-settings.php';

        $this->options   = array();
        $this->user_meta = array();

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

        Functions\when( 'get_current_user_id' )->justReturn( 1 );

        Functions\when( 'get_user_meta' )->alias( function ( $user_id, $key, $single = false ) {
            $val = $this->user_meta[ $user_id ][ $key ] ?? '';
            if ( $single ) {
                return $val;
            }
            return $val === '' ? array() : array( $val );
        } );

        Functions\when( 'update_user_meta' )->alias( function ( $user_id, $key, $value ) {
            $this->user_meta[ $user_id ][ $key ] = $value;
            return true;
        } );

        Functions\when( 'delete_user_meta' )->alias( function ( $user_id, $key ) {
            unset( $this->user_meta[ $user_id ][ $key ] );
            return true;
        } );
    }

    public function test_enabled_defaults_to_false() {
        $this->assertFalse( \PForms_Connector_Settings::is_enabled() );
    }

    public function test_entry_read_enabled_defaults_to_false() {
        $this->assertFalse( \PForms_Connector_Settings::is_entry_read_enabled() );
    }

    public function test_set_enabled_persists_and_reads_back() {
        \PForms_Connector_Settings::set_enabled( true );
        $this->assertTrue( \PForms_Connector_Settings::is_enabled() );

        \PForms_Connector_Settings::set_enabled( false );
        $this->assertFalse( \PForms_Connector_Settings::is_enabled() );
    }

    public function test_set_entry_read_enabled_persists_and_reads_back() {
        \PForms_Connector_Settings::set_entry_read_enabled( true );
        $this->assertTrue( \PForms_Connector_Settings::is_entry_read_enabled() );
    }

    public function test_configured_at_returns_zero_when_unset() {
        $this->assertSame( 0, \PForms_Connector_Settings::configured_at() );
    }

    public function test_mark_configured_stores_timestamp() {
        \PForms_Connector_Settings::mark_configured();
        $this->assertGreaterThan( 0, \PForms_Connector_Settings::configured_at() );
    }

    public function test_clear_configured_removes_marker() {
        \PForms_Connector_Settings::mark_configured();
        $this->assertGreaterThan( 0, \PForms_Connector_Settings::configured_at() );

        \PForms_Connector_Settings::clear_configured();
        $this->assertSame( 0, \PForms_Connector_Settings::configured_at() );
    }

    public function test_app_password_name_constant_is_stable() {
        // If this string changes, every existing install's App Password
        // revocation logic silently fails because the name-match breaks.
        // Pin it.
        $this->assertSame(
            'Promptless Forms — Claude Cowork',
            \PForms_Connector_Settings::APP_PASSWORD_NAME
        );
    }
}
