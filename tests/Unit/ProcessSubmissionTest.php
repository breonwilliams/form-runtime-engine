<?php
/**
 * Unit tests for PForms_Submission_Handler::process_submission() — early-exit paths only.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the programmatic submission entry point added in Phase 1 of the
 * Cowork connector work.
 *
 * Scope note: This file only covers the paths that exit BEFORE reaching
 * PForms_Entry::create() — invalid inputs, unknown forms, validation failures,
 * and dry_run. Paths that create entries, send emails, or dispatch webhooks
 * require the full DB-backed WordPress test harness and are covered in the
 * integration suite (tests/Integration/FormSubmissionTest.php).
 *
 * Keeping this split clean means these tests run fast and without a database,
 * while integration tests exercise the full pipeline end-to-end.
 */
class ProcessSubmissionTest extends UnitTestCase {

    /**
     * Runtime registry stand-in — a simple object with a get() method that
     * the Submission_Handler calls via pforms()->registry->get(). We control
     * what form configs it returns.
     *
     * @var object|null
     */
    private $fake_registry;

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-sanitizer.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-handler.php';

        // Anonymous registry object with a get() method. Tests set its forms
        // array directly on the instance.
        $this->fake_registry = new class {
            public $forms = array();
            public function get( $form_id ) {
                return $this->forms[ $form_id ] ?? null;
            }
        };

        // Stand-up a fake pforms() accessor that returns a plugin object holding
        // our registry. We set this via global so the pforms() function — which
        // is defined in the main plugin file — is not loaded here. Instead
        // we redefine it on demand.
        $registry_for_closure = $this->fake_registry;

        if ( ! function_exists( 'pforms' ) ) {
            $GLOBALS['__fre_test_plugin'] = new class( $registry_for_closure ) {
                public $registry;
                public function __construct( $registry ) { $this->registry = $registry; }
            };
            eval( 'function pforms() { return $GLOBALS["__fre_test_plugin"]; }' );
        } else {
            // pforms() was already defined by a prior test — mutate the plugin's registry.
            $GLOBALS['__fre_test_plugin']->registry = $registry_for_closure;
        }
    }

    public function test_empty_form_id_returns_wp_error() {
        $handler = new \PForms_Submission_Handler();
        $result  = $handler->process_submission( '', array() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'invalid_form_id', $result->get_error_code() );
    }

    public function test_unknown_form_returns_wp_error() {
        $handler = new \PForms_Submission_Handler();
        $result  = $handler->process_submission(
            'no-such-form',
            array( 'email' => 'user@example.com' )
        );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'form_not_found', $result->get_error_code() );
    }

    /**
     * Dry-run path: validation runs, sanitization runs, but PForms_Entry::create
     * is NEVER reached — so we can unit-test it even though the full create
     * path needs the DB.
     */
    public function test_dry_run_returns_sanitized_without_touching_entry_layer() {
        $this->fake_registry->forms['contact'] = array(
            'title'  => 'Contact',
            'fields' => array(
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
                array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
            ),
            'settings' => array(
                'store_entries' => true,
                'notification'  => array( 'enabled' => true ),
                'success_message' => 'Thanks!',
            ),
        );

        $handler = new \PForms_Submission_Handler();
        // Use clean field keys per the connector contract (CONNECTOR_SPEC.md
        // §9.9). process_submission translates these to the internal
        // `*` form the validator and sanitizer expect.
        $result = $handler->process_submission(
            'contact',
            array(
                'email'   => 'user@example.com',
                'message' => 'Hello there',
            ),
            array( 'dry_run' => true )
        );

        $this->assertIsArray( $result );
        $this->assertTrue( $result['dry_run'], 'Return value reports dry_run.' );
        $this->assertSame( 0, $result['entry_id'], 'No entry id because we never called create().' );
        $this->assertFalse( $result['email_sent'] );
        $this->assertIsArray( $result['sanitized'] );
    }

    /**
     * Validation error propagates as WP_Error — we exit before any DB write.
     *
     * A submission missing a required field causes PForms_Validator::validate()
     * to return a WP_Error; process_submission surfaces it to the caller.
     */
    public function test_validation_errors_propagate_before_entry_creation() {
        $this->fake_registry->forms['contact'] = array(
            'title'  => 'Contact',
            'fields' => array(
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
                array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message', 'required' => true ),
            ),
            'settings' => array(
                'store_entries' => true,
                'success_message' => 'Thanks!',
            ),
        );

        $handler = new \PForms_Submission_Handler();
        $result  = $handler->process_submission(
            'contact',
            array(
                // 'email' deliberately missing (required field).
                'message' => 'Body content',
            )
        );

        $this->assertInstanceOf(
            'WP_Error',
            $result,
            'Validation failure returns WP_Error, preventing any downstream side effects.'
        );
    }
}
