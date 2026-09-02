<?php
/**
 * Base Integration Test Case.
 *
 * Extends WordPress test framework WP_UnitTestCase.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Base integration test case with WordPress loaded.
 */
abstract class IntegrationTestCase extends \WP_UnitTestCase {

    /**
     * Plugin instance.
     *
     * @var \Form_Runtime_Engine
     */
    protected $plugin;

    /**
     * Set up before class - runs once before all tests in this class.
     * Creates database tables needed for integration tests.
     */
    public static function set_up_before_class() {
        parent::set_up_before_class();

        // Run database migrations to create tables.
        $migrator = new \PForms_Migrator();
        $migrator->run_migrations();
    }

    /**
     * Tear down after class - runs once after all tests in this class.
     */
    public static function tear_down_after_class() {
        parent::tear_down_after_class();

        // Optionally drop tables after tests (comment out to inspect DB).
        // $migrator = new \PForms_Migrator();
        // $migrator->drop_tables();
    }

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Get plugin instance.
        $this->plugin = pforms();

        // Clear form registry between tests.
        $this->clear_forms();

        // Clear rate limiting transients.
        $this->clear_rate_limits();
    }

    /**
     * Tear down after each test.
     */
    public function tear_down() {
        // Clear forms.
        $this->clear_forms();

        parent::tear_down();
    }

    /**
     * Clear all registered forms.
     */
    protected function clear_forms() {
        $registry = $this->plugin->registry;
        $forms    = $registry->get_all();

        foreach ( array_keys( $forms ) as $form_id ) {
            $registry->unregister( $form_id );
        }
    }

    /**
     * Clear rate limiting transients.
     */
    protected function clear_rate_limits() {
        global $wpdb;

        $wpdb->query(
            "DELETE FROM {$wpdb->options}
             WHERE option_name LIKE '%pforms_rate_%'
             OR option_name LIKE '%pforms_submission_%'
             OR option_name LIKE '%pforms_idempotent_%'"
        );
    }

    /**
     * Load a form fixture.
     *
     * @param string $fixture_name Fixture name without extension.
     * @return array Form configuration.
     */
    protected function load_form_fixture( $fixture_name ) {
        $path = FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/forms/' . $fixture_name . '.php';

        if ( ! file_exists( $path ) ) {
            throw new \RuntimeException( "Fixture not found: {$path}" );
        }

        return include $path;
    }

    /**
     * Load submission data fixture.
     *
     * @param string $fixture_key Fixture key.
     * @return array Submission data.
     */
    protected function load_submission_fixture( $fixture_key ) {
        $path = FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/submissions/valid-submission.php';

        if ( ! file_exists( $path ) ) {
            throw new \RuntimeException( "Fixture not found: {$path}" );
        }

        $fixtures = include $path;

        if ( ! isset( $fixtures[ $fixture_key ] ) ) {
            throw new \RuntimeException( "Fixture key not found: {$fixture_key}" );
        }

        return $fixtures[ $fixture_key ];
    }

    /**
     * Register a test form.
     *
     * @param string $form_id Form ID.
     * @param array  $config  Form configuration.
     * @return bool Success.
     */
    protected function register_form( $form_id, array $config ) {
        return pforms_register_form( $form_id, $config );
    }

    /**
     * Create a mock POST request for form submission.
     *
     * @param string $form_id Form ID.
     * @param array  $data    Form data.
     * @return array POST data with nonce.
     */
    protected function create_submission_request( $form_id, array $data ) {
        // Add form ID.
        $data['pforms_form_id'] = $form_id;

        // Add nonce.
        $data['_wpnonce'] = wp_create_nonce( 'pforms_submit_' . $form_id );

        // Add timestamp for timing check.
        $data['_pforms_timestamp'] = time() - 5; // 5 seconds ago.

        return $data;
    }

    /**
     * Simulate an AJAX request.
     *
     * Sets up the AJAX environment for testing.
     *
     * @param string $action AJAX action.
     * @param array  $data   POST data.
     */
    protected function do_ajax( $action, array $data = array() ) {
        // Set up globals.
        $_POST    = $data;
        $_REQUEST = $data;

        // Set AJAX constant.
        if ( ! defined( 'DOING_AJAX' ) ) {
            define( 'DOING_AJAX', true );
        }

        // Capture output.
        ob_start();

        try {
            do_action( 'wp_ajax_' . $action );
        } catch ( \WPDieException $e ) {
            // Expected when wp_send_json_* is called.
        }

        $output = ob_get_clean();

        return json_decode( $output, true );
    }

    /**
     * Assert that a form exists in the registry.
     *
     * @param string $form_id Form ID.
     * @param string $message Optional message.
     */
    protected function assertFormExists( $form_id, $message = '' ) {
        $this->assertTrue(
            $this->plugin->registry->exists( $form_id ),
            $message ?: "Form '{$form_id}' should exist in registry"
        );
    }

    /**
     * Assert that a form does not exist in the registry.
     *
     * @param string $form_id Form ID.
     * @param string $message Optional message.
     */
    protected function assertFormNotExists( $form_id, $message = '' ) {
        $this->assertFalse(
            $this->plugin->registry->exists( $form_id ),
            $message ?: "Form '{$form_id}' should not exist in registry"
        );
    }

    /**
     * Get the entries table name.
     *
     * @return string
     */
    protected function get_entries_table() {
        global $wpdb;
        return $wpdb->prefix . 'fre_entries';
    }

    /**
     * Get the entry meta table name.
     *
     * @return string
     */
    protected function get_entry_meta_table() {
        global $wpdb;
        return $wpdb->prefix . 'fre_entry_meta';
    }

    /**
     * Clean up test entries.
     */
    protected function clean_entries() {
        global $wpdb;

        // Suppress errors if tables don't exist yet.
        $wpdb->suppress_errors( true );
        $wpdb->query( "TRUNCATE TABLE {$this->get_entries_table()}" );
        $wpdb->query( "TRUNCATE TABLE {$this->get_entry_meta_table()}" );
        $wpdb->suppress_errors( false );
    }

    /**
     * Get the entry files table name.
     *
     * @return string
     */
    protected function get_entry_files_table() {
        global $wpdb;
        return $wpdb->prefix . 'fre_entry_files';
    }
}
