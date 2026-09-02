<?php
/**
 * Unit tests verifying PForms_Forms_Manager correctly delegates CRUD to the repository.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Delegation tests.
 *
 * Forms Manager's CRUD methods are now thin wrappers over PForms_Forms_Repository.
 * External callers (including the pforms_save_db_form() wrapper function) go
 * through these methods unchanged, so regressions in delegation would break
 * the public API.
 */
class FormsManagerDelegationTest extends UnitTestCase {

    private $options = array();

    protected function set_up() {
        parent::set_up();

        $this->options = array();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-json-schema-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-css-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-webhook-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-forms-repository.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Admin/class-fre-forms-manager.php';

        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
            return $this->options[ $option ] ?? $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value ) {
            $this->options[ $option ] = $value;
            return true;
        } );

        Functions\when( 'wp_generate_password' )->alias( function ( $length = 12 ) {
            return str_repeat( 'x', $length );
        } );

        Functions\when( 'wp_json_encode' )->alias( function ( $data, $options = 0 ) {
            return json_encode( $data, $options );
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        Functions\when( 'do_action' )->justReturn();
    }

    private function valid_config_json() {
        return json_encode(
            array(
                'fields' => array(
                    array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
                ),
            )
        );
    }

    public function test_save_form_delegates_and_returns_normalized_record() {
        $result = \PForms_Forms_Manager::save_form(
            'contact',
            'Contact Us',
            $this->valid_config_json()
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'contact', $result['id'] );
        $this->assertSame( 'Contact Us', $result['title'] );
        $this->assertSame( 'admin', $result['managed_by'] );
        $this->assertSame( 1, $result['connector_version'] );
    }

    public function test_get_form_delegates() {
        \PForms_Forms_Manager::save_form( 'contact', 'Contact', $this->valid_config_json() );

        $fetched = \PForms_Forms_Manager::get_form( 'contact' );

        $this->assertIsArray( $fetched );
        $this->assertSame( 'contact', $fetched['id'] );
    }

    public function test_get_forms_delegates() {
        \PForms_Forms_Manager::save_form( 'a', 'A', $this->valid_config_json() );
        \PForms_Forms_Manager::save_form( 'b', 'B', $this->valid_config_json() );

        $forms = \PForms_Forms_Manager::get_forms();

        $this->assertCount( 2, $forms );
        $this->assertArrayHasKey( 'a', $forms );
        $this->assertArrayHasKey( 'b', $forms );
    }

    public function test_delete_form_delegates() {
        \PForms_Forms_Manager::save_form( 'contact', 'Contact', $this->valid_config_json() );

        $this->assertTrue( \PForms_Forms_Manager::delete_form( 'contact' ) );
        $this->assertNull( \PForms_Forms_Manager::get_form( 'contact' ) );
    }

    /**
     * OPTION_KEY is retained as a public constant for any external code that
     * references it. It must continue to point at the same option row the
     * repository uses, otherwise the two classes would read different data.
     */
    public function test_option_key_constant_matches_repository() {
        $this->assertSame(
            \PForms_Forms_Repository::OPTION_KEY,
            \PForms_Forms_Manager::OPTION_KEY
        );
    }

    /**
     * Webhook arguments flow through the 8-positional-arg delegation surface.
     *
     * This is the most likely site for a delegation bug.
     */
    public function test_save_form_passes_all_webhook_fields() {
        $result = \PForms_Forms_Manager::save_form(
            'contact',
            'Contact',
            $this->valid_config_json(),
            '', // custom_css
            true, // webhook_enabled
            'https://hooks.example.com/abc', // webhook_url
            '', // webhook_secret (should auto-generate)
            'zapier' // webhook_preset
        );

        $this->assertTrue( $result['webhook_enabled'] );
        $this->assertSame( 'https://hooks.example.com/abc', $result['webhook_url'] );
        $this->assertSame( 'zapier', $result['webhook_preset'] );
        $this->assertSame( 32, strlen( $result['webhook_secret'] ) );
    }
}
