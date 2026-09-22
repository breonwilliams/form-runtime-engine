<?php
/**
 * Tests for the 1.11.0 clean-up: values stored without WordPress's slashes,
 * a honeypot that autofill leaves alone (and that no longer discards what it
 * flags), notification attachments within a size budget, and file links in
 * the retry email.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;
use FRE\Tests\Unit\Mocks\FakeOptionsWpdb;

/**
 * 1.11.0 clean-up.
 */
class FormsCleanupTest extends UnitTestCase {

    /**
     * Temp files to remove.
     *
     * @var string[]
     */
    private $temp = array();

    protected function set_up() {
        parent::set_up();

        if ( ! defined( 'MB_IN_BYTES' ) ) {
            define( 'MB_IN_BYTES', 1048576 );
        }
        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }

        $GLOBALS['wpdb'] = new FakeOptionsWpdb();
        Functions\when( 'get_option' )->justReturn( 'test-honeypot-secret' );
        Functions\when( 'esc_html__' )->returnArg( 1 );
    }

    protected function tear_down() {
        foreach ( $this->temp as $path ) {
            @unlink( $path );
        }
        $_POST = array();
        unset( $GLOBALS['wpdb'] );
        parent::tear_down();
    }

    // ── Slashes ─────────────────────────────────────────────────────────

    public function test_submitted_values_are_unslashed() {
        // WordPress adds slashes to $_POST. Stored as-is, "O'Brien" became
        // "O\'Brien" in Entries, emails and Drive folder names.
        $_POST = array(
            'pforms_field_full_name' => "Zo\u{eb} O\\'Brien & Sons",
            'pforms_field_tags'      => array( "don\\'t", 'ok' ),
        );

        $post = $this->call( $this->handler(), 'posted_data', array() );

        $this->assertSame( "Zo\u{eb} O'Brien & Sons", $post['pforms_field_full_name'] );
        $this->assertSame( array( "don't", 'ok' ), $post['pforms_field_tags'] );
    }

    public function test_validation_and_storage_read_the_unslashed_values() {
        $code = $this->code( 'includes/Core/class-fre-submission-handler.php' );

        $this->assertStringContainsString( '->validate_input_lengths( $post )', $code );
        $this->assertStringContainsString( '->validate( $form_config, $post )', $code );
        $this->assertStringContainsString( '->sanitize( $form_config, $post )', $code );
        $this->assertDoesNotMatchRegularExpression( '/->(validate|sanitize|validate_input_lengths)\(\s*(\$form_config,\s*)?\$_POST/', $code );
    }

    // ── Honeypot ────────────────────────────────────────────────────────

    public function test_honeypot_name_does_not_look_like_a_real_field() {
        $name = ( new \PForms_Honeypot() )->get_field_name( 'quote' );

        $this->assertMatchesRegularExpression( '/^_pforms_hp_[0-9a-f]{8}$/', $name );
        $this->assertDoesNotMatchRegularExpression( '/web|url|site|mail|phone|name/i', $name );
    }

    public function test_rendered_honeypot_gives_autofill_nothing_to_match() {
        $renderer = new \PForms_Renderer();
        $html     = $this->call( $renderer, 'render_honeypot', array( 'quote' ) );

        $this->assertStringContainsString( 'name="_pforms_hp_', $html );
        $this->assertDoesNotMatchRegularExpression( '/website|url/i', $html );
        foreach ( array( 'autocomplete="off"', 'data-1p-ignore', 'data-lpignore="true"', 'data-bwignore', 'tabindex="-1"', 'aria-hidden="true"' ) as $attribute ) {
            $this->assertStringContainsString( $attribute, $html );
        }
    }

    public function test_cached_pages_with_the_old_honeypot_name_still_work() {
        $honeypot = new \PForms_Honeypot();
        $legacy   = $honeypot->get_legacy_field_name( 'quote' );

        $_POST = array( $legacy => '' );
        $this->assertTrue( $honeypot->validate( 'quote' ), 'An empty legacy honeypot is a normal visitor.' );

        $_POST = array( $legacy => 'filled' );
        $this->assertInstanceOf( 'WP_Error', $honeypot->validate( 'quote' ), 'A filled legacy honeypot is still caught.' );
    }

    public function test_filled_current_honeypot_is_caught() {
        $honeypot = new \PForms_Honeypot();
        $_POST    = array( $honeypot->get_field_name( 'quote' ) => 'https://spam.example' );

        $this->assertTrue( $honeypot->is_triggered( 'quote' ) );
    }

    public function test_flagged_submissions_are_kept_not_discarded() {
        $code = $this->code( 'includes/Core/class-fre-submission-handler.php' );

        $this->assertMatchesRegularExpression( '/honeypot->validate\(.*?store_as_spam\(/s', $code );
        $this->assertStringContainsString( "array( 'is_spam' => 1 )", $code );

        // A spam entry must not reach the notification, webhook or FlowMint.
        preg_match( '/private function store_as_spam\(.*?\n    }\n/s', $code, $method );
        $this->assertNotEmpty( $method );
        $this->assertStringNotContainsString( 'pforms_submission_complete', $method[0] );
        $this->assertStringNotContainsString( 'PForms_Email_Notification', $method[0] );
    }

    // ── Email ───────────────────────────────────────────────────────────

    public function test_attachments_within_budget_are_attached() {
        $small = $this->file( 1024 );
        $this->assertSame( array( $small ), $this->attachments( array( 'design_file' => array( 'file_path' => $small ) ) ) );
    }

    public function test_attachments_over_budget_are_left_as_links() {
        Functions\when( 'apply_filters' )->alias( function ( $hook, $value ) {
            return 'pforms_notification_attachment_budget' === $hook ? 2048 : $value;
        } );
        $a = $this->file( 1500 );
        $b = $this->file( 1500 );

        $this->assertSame( array(), $this->attachments( array( 'design_file' => array( 'file_path' => $a ), 'tax_doc' => array( 'file_path' => $b ) ) ) );
    }

    public function test_retry_email_rebuilds_file_links_from_the_entry() {
        Functions\when( 'wp_get_attachment_url' )->alias( function ( $id ) {
            return 'https://example.test/uploads/' . $id . '.jpg';
        } );
        $mailer = new \PForms_Email_Notification();
        $entry  = array( 'files' => array(
            array( 'field_key' => 'design_file', 'attachment_id' => 7, 'file_name' => 'logo.jpg', 'file_path' => '/x/7.jpg', 'file_size' => 123 ),
            array( 'field_key' => 'design_file', 'attachment_id' => 0, 'file_name' => 'gone.jpg', 'file_path' => '/x/0.jpg', 'file_size' => 1 ),
        ) );

        $files = $this->call( $mailer, 'files_from_entry', array( $entry ) );

        $this->assertSame( array( 'design_file' => array( array(
            'file_name' => 'logo.jpg',
            'file_url'  => 'https://example.test/uploads/7.jpg',
            'file_path' => '/x/7.jpg',
            'file_size' => 123,
        ) ) ), $files );
    }

    public function test_retry_passes_files_to_the_body() {
        $code = $this->code( 'includes/Notifications/class-fre-email-notification.php' );
        preg_match( '/public static function process_retry\(.*?\n    }\n/s', $code, $method );

        $this->assertStringContainsString( 'files_from_entry( $entry )', $method[0] );
        $this->assertStringNotContainsString( 'build_email_body( $form_config, $entry_data, array() )', $method[0] );
    }

    // ── Latent fatal ────────────────────────────────────────────────────

    public function test_nonce_refresh_calls_no_undefined_function() {
        $this->assertStringNotContainsString( 'get_uid()', $this->code( 'includes/Core/class-fre-submission-handler.php' ) );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function handler() {
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-handler.php';
        return new \PForms_Submission_Handler();
    }

    private function attachments( array $files ) {
        return $this->call( new \PForms_Email_Notification(), 'get_attachments', array( $files ) );
    }

    private function file( $bytes ) {
        $path = tempnam( sys_get_temp_dir(), 'fre_att_' );
        file_put_contents( $path, str_repeat( 'x', $bytes ) );
        $this->temp[] = $path;
        return $path;
    }

    private function code( $relative ) {
        return preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', file_get_contents( FRE_TEST_PLUGIN_DIR . $relative ) );
    }

    private function call( $object, $method, array $args ) {
        $ref = new \ReflectionMethod( $object, $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $ref->setAccessible( true );
        }
        return $ref->invokeArgs( $object, $args );
    }
}
