<?php
/**
 * Unit tests for PForms_Forms_Repository.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the pure-CRUD form repository extracted in the Phase 1 Cowork
 * connector groundwork.
 *
 * Focus: verify CRUD parity with the previous PForms_Forms_Manager static
 * methods, plus the new Phase 1 fields (managed_by, connector_version) that
 * the connector depends on.
 *
 * Strategy: use real plugin classes (loaded via autoloader). Mock WordPress
 * functions (get_option, update_option, etc.) via Brain\Monkey. Feed real
 * form configs to exercise real validation paths.
 *
 * Tests that need to reach PForms_Entry (DB layer) are in the integration suite.
 */
class FormsRepositoryTest extends UnitTestCase {

    /**
     * In-memory option store shared across all mocked get/update_option calls.
     *
     * @var array
     */
    private $options = array();

    protected function set_up() {
        parent::set_up();

        $this->options = array();

        // Explicitly load the classes the repository touches at save time,
        // mirroring the pattern in ConfigValidatorTest. The autoloader would
        // load them anyway, but being explicit makes test dependencies obvious.
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-json-schema-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-css-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-webhook-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-forms-repository.php';

        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
            return $this->options[ $option ] ?? $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value ) {
            $this->options[ $option ] = $value;
            return true;
        } );

        // wp_generate_password is deterministic for tests — returns 32 'x's
        // so we can assert exact lengths without brittleness.
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

    /**
     * Build a minimal form config that genuinely validates against the real
     * PForms_JSON_Schema_Validator. Used by tests that don't care about the
     * specific config content.
     */
    private function valid_config_json( array $extra_fields = array() ) {
        $fields = array_merge(
            array(
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            ),
            $extra_fields
        );

        return json_encode( array( 'fields' => $fields ) );
    }

    public function test_save_creates_new_form_with_defaults() {
        $result = \PForms_Forms_Repository::save(
            'contact',
            array(
                'title'  => 'Contact Us',
                'config' => $this->valid_config_json(),
            )
        );

        $this->assertIsArray( $result );
        $this->assertSame( 'contact', $result['id'] );
        $this->assertSame( 'Contact Us', $result['title'] );
        $this->assertSame( 1, $result['connector_version'], 'New forms start at connector_version 1.' );
        $this->assertSame( 'admin', $result['managed_by'], 'Default managed_by is admin.' );
        $this->assertFalse( $result['webhook_enabled'] );
        $this->assertGreaterThan( 0, $result['created'] );
        $this->assertSame( $result['created'], $result['modified'] );
    }

    public function test_save_update_increments_connector_version() {
        \PForms_Forms_Repository::save(
            'contact',
            array( 'config' => $this->valid_config_json() )
        );

        $result = \PForms_Forms_Repository::save(
            'contact',
            array( 'config' => $this->valid_config_json(), 'title' => 'Updated' )
        );

        $this->assertSame( 2, $result['connector_version'] );

        $result = \PForms_Forms_Repository::save(
            'contact',
            array( 'config' => $this->valid_config_json(), 'title' => 'Updated Again' )
        );

        $this->assertSame( 3, $result['connector_version'] );
    }

    public function test_save_preserves_created_timestamp_on_update() {
        $first = \PForms_Forms_Repository::save( 'contact', array( 'config' => $this->valid_config_json() ) );
        sleep( 1 );
        $second = \PForms_Forms_Repository::save( 'contact', array( 'config' => $this->valid_config_json() ) );

        $this->assertSame( $first['created'], $second['created'] );
        $this->assertGreaterThanOrEqual( $first['modified'], $second['modified'] );
    }

    public function test_save_accepts_connector_managed_by() {
        $result = \PForms_Forms_Repository::save(
            'quote_form',
            array(
                'config'     => $this->valid_config_json(),
                'managed_by' => 'connector:cowork',
            )
        );

        $this->assertSame( 'connector:cowork', $result['managed_by'] );
    }

    public function test_save_rejects_unknown_managed_by_value_falling_back_to_default() {
        $result = \PForms_Forms_Repository::save(
            'contact',
            array(
                'config'     => $this->valid_config_json(),
                'managed_by' => 'connector:someone-else',
            )
        );

        $this->assertSame(
            'admin',
            $result['managed_by'],
            'Unknown managed_by values are coerced to default admin — never trust caller input.'
        );
    }

    public function test_save_preserves_existing_managed_by_when_not_supplied() {
        \PForms_Forms_Repository::save(
            'cowork_form',
            array(
                'config'     => $this->valid_config_json(),
                'managed_by' => 'connector:cowork',
            )
        );

        // Admin edits via the admin UI — save() called without managed_by.
        $result = \PForms_Forms_Repository::save(
            'cowork_form',
            array( 'config' => $this->valid_config_json(), 'title' => 'Edited By Admin' )
        );

        $this->assertSame(
            'connector:cowork',
            $result['managed_by'],
            'Existing managed_by is preserved on update when caller does not override.'
        );
    }

    public function test_save_rejects_invalid_form_id() {
        $result = \PForms_Forms_Repository::save( 'Invalid ID With Spaces', array( 'config' => $this->valid_config_json() ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'invalid_id', $result->get_error_code() );
    }

    public function test_save_rejects_empty_form_id() {
        $result = \PForms_Forms_Repository::save( '', array( 'config' => $this->valid_config_json() ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'empty_id', $result->get_error_code() );
    }

    public function test_save_rejects_empty_config() {
        $result = \PForms_Forms_Repository::save( 'contact', array() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'empty_config', $result->get_error_code() );
    }

    public function test_save_rejects_invalid_json() {
        $result = \PForms_Forms_Repository::save(
            'contact',
            array( 'config' => '{not valid json' )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'invalid_json', $result->get_error_code() );
    }

    /**
     * A config missing the required `fields` array fails real schema validation.
     * This verifies the repository correctly surfaces validator errors as
     * WP_Errors with the `schema_error` code.
     */
    public function test_save_propagates_real_schema_errors() {
        $bad_config = json_encode( array( 'title' => 'No fields key here' ) );

        $result = \PForms_Forms_Repository::save(
            'contact',
            array( 'config' => $bad_config )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'schema_error', $result->get_error_code() );
    }

    public function test_webhook_secret_auto_generated_on_first_enable() {
        $result = \PForms_Forms_Repository::save(
            'contact',
            array(
                'config'          => $this->valid_config_json(),
                'webhook_enabled' => true,
                'webhook_url'     => 'https://hooks.example.com/abc',
            )
        );

        $this->assertNotEmpty( $result['webhook_secret'] );
        $this->assertSame( 32, strlen( $result['webhook_secret'] ) );
    }

    public function test_webhook_secret_preserved_on_update() {
        $initial = \PForms_Forms_Repository::save(
            'contact',
            array(
                'config'          => $this->valid_config_json(),
                'webhook_enabled' => true,
                'webhook_url'     => 'https://hooks.example.com/abc',
            )
        );

        $updated = \PForms_Forms_Repository::save(
            'contact',
            array(
                'config'          => $this->valid_config_json(),
                'webhook_enabled' => true,
                'webhook_url'     => 'https://hooks.example.com/abc',
            )
        );

        $this->assertSame( $initial['webhook_secret'], $updated['webhook_secret'] );
    }

    public function test_regenerate_webhook_secret_bumps_version() {
        \PForms_Forms_Repository::save(
            'contact',
            array(
                'config'          => $this->valid_config_json(),
                'webhook_enabled' => true,
                'webhook_url'     => 'https://hooks.example.com/abc',
            )
        );

        $before = \PForms_Forms_Repository::get( 'contact' );
        $new    = \PForms_Forms_Repository::regenerate_webhook_secret( 'contact' );
        $after  = \PForms_Forms_Repository::get( 'contact' );

        $this->assertNotFalse( $new );
        $this->assertIsString( $new );
        $this->assertSame( 32, strlen( $new ) );
        $this->assertGreaterThan( $before['connector_version'], $after['connector_version'] );
    }

    public function test_regenerate_webhook_secret_errors_on_missing_form() {
        $result = \PForms_Forms_Repository::regenerate_webhook_secret( 'no-such-form' );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    public function test_delete_removes_form() {
        \PForms_Forms_Repository::save( 'contact', array( 'config' => $this->valid_config_json() ) );
        $this->assertTrue( \PForms_Forms_Repository::exists( 'contact' ) );

        $result = \PForms_Forms_Repository::delete( 'contact' );

        $this->assertTrue( $result );
        $this->assertFalse( \PForms_Forms_Repository::exists( 'contact' ) );
        $this->assertNull( \PForms_Forms_Repository::get( 'contact' ) );
    }

    public function test_delete_returns_false_for_missing_form() {
        $this->assertFalse( \PForms_Forms_Repository::delete( 'no-such-form' ) );
    }

    /**
     * Critical backward-compat test: forms saved before Phase 1 have no
     * managed_by or connector_version keys. Reads through the repository
     * must backfill defaults without requiring a write.
     */
    public function test_get_normalizes_legacy_records() {
        $this->options['pforms_client_forms'] = array(
            'legacy' => array(
                'id'              => 'legacy',
                'title'           => 'Old Form',
                'config'          => '{"fields":[{"key":"x","type":"text"}]}',
                'custom_css'      => '',
                'webhook_enabled' => false,
                'webhook_url'     => '',
                'webhook_secret'  => '',
                'webhook_preset'  => 'custom',
                'created'         => 1700000000,
                'modified'        => 1700000000,
            ),
        );

        $record = \PForms_Forms_Repository::get( 'legacy' );

        $this->assertIsArray( $record );
        $this->assertSame( 'admin', $record['managed_by'], 'Backfilled managed_by default.' );
        $this->assertSame( 0, $record['connector_version'], 'Backfilled connector_version to 0.' );
        $this->assertSame( 'Old Form', $record['title'], 'Existing keys preserved verbatim.' );
    }

    public function test_get_all_normalizes_every_record() {
        $this->options['pforms_client_forms'] = array(
            'a' => array( 'id' => 'a', 'title' => 'A', 'config' => '{}' ),
            'b' => array( 'id' => 'b', 'title' => 'B', 'config' => '{}' ),
        );

        $forms = \PForms_Forms_Repository::get_all();

        $this->assertCount( 2, $forms );
        foreach ( $forms as $form ) {
            $this->assertArrayHasKey( 'managed_by', $form );
            $this->assertArrayHasKey( 'connector_version', $form );
        }
    }

    public function test_exists_returns_true_for_known_form() {
        \PForms_Forms_Repository::save( 'contact', array( 'config' => $this->valid_config_json() ) );
        $this->assertTrue( \PForms_Forms_Repository::exists( 'contact' ) );
    }

    public function test_exists_returns_false_for_unknown_form() {
        $this->assertFalse( \PForms_Forms_Repository::exists( 'no-such-form' ) );
    }
}
