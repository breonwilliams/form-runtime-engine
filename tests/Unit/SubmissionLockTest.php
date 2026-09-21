<?php
/**
 * Tests for PForms_Submission_Lock and the submission handler's duplicate and
 * idempotency guards.
 *
 * The defect these pin (found 2026-09-21 on a 725 Print Lab quote form): the
 * duplicate guard wrote its row with SQL but cleared it with
 * delete_transient(). On a host with a persistent object cache that clear
 * never reached the row, so a retry after ANY error was taken for a duplicate
 * and answered "Thanks — we have your request" while nothing was saved. On
 * screen: a red error and a green success message together.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;
use FRE\Tests\Unit\Mocks\FakeOptionsWpdb;

/**
 * Submission lock and guard behaviour.
 */
class SubmissionLockTest extends UnitTestCase {

    /**
     * @var FakeOptionsWpdb
     */
    private $db;

    /**
     * Payload of the last wp_send_json_* call.
     *
     * @var array|null
     */
    private $sent;

    protected function set_up() {
        parent::set_up();

        if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
            define( 'HOUR_IN_SECONDS', 3600 );
        }

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-lock.php';

        $this->db        = new FakeOptionsWpdb();
        $GLOBALS['wpdb'] = $this->db;

        Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
        Functions\when( 'wp_rand' )->justReturn( 2 ); // Never the 1-in-50 sweep unless a test asks.
        Functions\when( 'wp_generate_uuid4' )->alias( function () {
            return bin2hex( random_bytes( 16 ) );
        } );
        Functions\when( 'is_serialized' )->alias( function ( $data ) {
            return is_string( $data ) && preg_match( '/^[aOs]:\d+:/', $data );
        } );
    }

    protected function tear_down() {
        unset( $GLOBALS['wpdb'] );
        $_POST = array();
        parent::tear_down();
    }

    // ── The lock ────────────────────────────────────────────────────────

    public function test_first_claim_wins_and_second_sees_processing() {
        $lock = new \PForms_Submission_Lock();

        $this->assertTrue( $lock->claim( 'pforms_submission_abc', 60 ) );

        $second = $lock->claim( 'pforms_submission_abc', 60 );
        $this->assertIsArray( $second );
        $this->assertSame( \PForms_Submission_Lock::STATE_PROCESSING, $second['state'] );
    }

    public function test_claim_writes_the_expiry_row_core_sweeps_by() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_submission_abc', 60 );

        $this->assertArrayHasKey( '_transient_timeout_pforms_submission_abc', $this->db->rows );
        $this->assertEqualsWithDelta( time() + 60, (int) $this->db->rows['_transient_timeout_pforms_submission_abc'], 2 );
    }

    public function test_release_frees_the_key_for_the_next_attempt() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_submission_abc', 60 );
        $lock->release( 'pforms_submission_abc' );

        $this->assertSame( array(), $this->db->rows );
        $this->assertTrue( $lock->claim( 'pforms_submission_abc', 60 ) );
    }

    public function test_completed_record_is_reported_with_its_entry() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_submission_abc', 60 );
        $lock->update( 'pforms_submission_abc', array( 'state' => 'completed', 'entry_id' => 42 ) );

        $seen = $lock->claim( 'pforms_submission_abc', 60 );
        $this->assertSame( 'completed', $seen['state'] );
        $this->assertSame( 42, $seen['entry_id'] );
    }

    public function test_update_without_ttl_keeps_the_original_expiry() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_submission_abc', 60 );
        $before = $this->db->rows['_transient_timeout_pforms_submission_abc'];

        $lock->update( 'pforms_submission_abc', array( 'state' => 'completed' ) );

        $this->assertSame( $before, $this->db->rows['_transient_timeout_pforms_submission_abc'] );
    }

    public function test_expired_processing_claim_is_taken_over() {
        // A request that died mid-way leaves "processing" behind. Once its
        // window passes, the next attempt must get through — which depends on
        // the takeover writing a DIFFERENT value (MySQL counts changed rows).
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_idempotent_x', 300 );
        $this->age( 'pforms_idempotent_x', 301 );

        $this->assertTrue( $lock->claim( 'pforms_idempotent_x', 300 ) );
    }

    public function test_unexpired_claim_is_not_taken_over() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_idempotent_x', 300 );

        $this->assertIsArray( $lock->claim( 'pforms_idempotent_x', 300 ) );
    }

    public function test_legacy_1_10_duplicate_row_is_understood() {
        // 1.10.x stored the expiry time as the bare value, plus a timeout row.
        $this->db->rows['_transient_pforms_submission_old']         = (string) ( time() + 30 );
        $this->db->rows['_transient_timeout_pforms_submission_old'] = (string) ( time() + 30 );
        $lock = new \PForms_Submission_Lock();

        $this->assertSame( 'processing', $lock->claim( 'pforms_submission_old', 60 )['state'] );

        $this->db->rows['_transient_timeout_pforms_submission_old'] = (string) ( time() - 1 );
        $this->assertTrue( $lock->claim( 'pforms_submission_old', 60 ) );
    }

    public function test_legacy_1_10_idempotency_row_returns_its_response() {
        $response = array( 'success' => true, 'message' => 'Thanks' );
        $this->db->rows['_transient_pforms_idempotent_old']         = serialize( array( 'status' => 'completed', 'response' => $response ) );
        $this->db->rows['_transient_timeout_pforms_idempotent_old'] = (string) ( time() + 600 );
        $lock = new \PForms_Submission_Lock();

        $seen = $lock->claim( 'pforms_idempotent_old', 300 );
        $this->assertSame( 'completed', $seen['state'] );
        $this->assertSame( $response, $seen['response'] );
    }

    public function test_cleanup_removes_expired_pairs_only() {
        $lock = new \PForms_Submission_Lock();
        $lock->claim( 'pforms_submission_live', 60 );
        $lock->claim( 'pforms_submission_dead', 60 );
        $this->age( 'pforms_submission_dead', 61 );
        $this->db->rows['_transient_something_else']         = 'x';
        $this->db->rows['_transient_timeout_something_else'] = '1';

        $lock->cleanup_expired();

        $this->assertArrayHasKey( '_transient_pforms_submission_live', $this->db->rows );
        $this->assertArrayNotHasKey( '_transient_pforms_submission_dead', $this->db->rows );
        $this->assertArrayNotHasKey( '_transient_timeout_pforms_submission_dead', $this->db->rows );
        $this->assertArrayHasKey( '_transient_something_else', $this->db->rows, 'Only guard rows are swept.' );
    }

    public function test_keys_match_the_1_10_derivation() {
        $this->assertSame(
            'pforms_submission_' . hash( 'sha256', 'quote' . json_encode( array( 'a' => 'b' ) ) ),
            \PForms_Submission_Lock::duplicate_key( 'quote', array( 'a' => 'b' ) )
        );
        $this->assertSame(
            'pforms_idempotent_' . hash( 'sha256', 'quote_abc' ),
            \PForms_Submission_Lock::idempotency_key( 'quote', 'abc' )
        );
    }

    public function test_lock_never_uses_the_transient_api() {
        $source = file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-lock.php' );
        $code   = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $source );

        $this->assertDoesNotMatchRegularExpression( '/\b(get|set|delete)_transient\s*\(/', $code );
    }

    public function test_handler_does_not_clear_guards_through_the_transient_api() {
        // The 1.10.x defect in one line: delete_transient() on a row written
        // with SQL. On a host with a persistent object cache it never reaches
        // the row. The only transient left in the handler is the nonce-refresh
        // rate limit, which is written and read through the same API.
        $source = file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-handler.php' );
        $code   = preg_replace( '#/\*.*?\*/|//[^\n]*#s', '', $source );

        $this->assertDoesNotMatchRegularExpression( '/delete_transient\s*\(/', $code );
        $this->assertSame( 1, preg_match_all( '/\bset_transient\s*\(/', $code ), 'Only the nonce-refresh rate limit may use set_transient().' );
    }

    // ── The handler's guards ────────────────────────────────────────────

    public function test_retry_after_a_failure_is_processed_not_answered_with_success() {
        $handler = $this->handler();
        $this->post();

        $this->call( $handler, 'check_duplicate_submission', array( 'quote', $this->config() ) );
        $this->assertNull( $this->sent, 'The first attempt claims the window.' );

        // The first attempt fails (e.g. a refused upload).
        $this->expect_exit( function () use ( $handler ) {
            $this->call( $handler, 'send_error', array( 'dangerous_content', 'File refused.' ) );
        } );
        $this->assertFalse( $this->sent['success'] );

        // Same content, fresh request: must go through, not be told "Thanks".
        $retry      = $this->handler();
        $this->sent = null;
        $this->call( $retry, 'check_duplicate_submission', array( 'quote', $this->config() ) );
        $this->assertNull( $this->sent, 'The retry owns the window and is processed normally.' );
    }

    public function test_duplicate_of_a_received_submission_gets_the_success_message() {
        $first = $this->handler();
        $this->post();
        $this->call( $first, 'check_duplicate_submission', array( 'quote', $this->config() ) );
        $this->call( $first, 'mark_duplicate_completed', array( 17 ) );

        $this->expect_exit( function () {
            $this->call( $this->handler(), 'check_duplicate_submission', array( 'quote', $this->config() ) );
        } );

        $this->assertTrue( $this->sent['success'] );
        $this->assertSame( 'Thanks — we have your request.', $this->sent['data']['message'] );
    }

    public function test_error_after_the_entry_is_stored_keeps_the_window() {
        // The entry exists, so a retry must be told it arrived — not stored twice.
        $first = $this->handler();
        $this->post();
        $this->call( $first, 'check_duplicate_submission', array( 'quote', $this->config() ) );
        $this->call( $first, 'mark_duplicate_completed', array( 21 ) );
        $this->expect_exit( function () use ( $first ) {
            $this->call( $first, 'send_error', array( 'processing_error', 'Notification failed.' ) );
        } );

        $this->expect_exit( function () {
            $this->call( $this->handler(), 'check_duplicate_submission', array( 'quote', $this->config() ) );
        } );
        $this->assertTrue( $this->sent['success'] );
    }

    public function test_duplicate_of_a_submission_in_flight_is_told_to_wait() {
        $first = $this->handler();
        $this->post();
        $this->call( $first, 'check_duplicate_submission', array( 'quote', $this->config() ) );

        $this->expect_exit( function () {
            $this->call( $this->handler(), 'check_duplicate_submission', array( 'quote', $this->config() ) );
        } );

        $this->assertFalse( $this->sent['success'], 'Never "Thanks" while nothing may have been saved.' );
        $this->assertSame( 'submission_processing', $this->sent['data']['code'] );
    }

    public function test_second_request_with_the_same_submission_id_waits_while_first_runs() {
        $this->post();
        $this->call( $this->handler(), 'check_idempotency_token', array( 'quote' ) );

        $this->expect_exit( function () {
            $this->call( $this->handler(), 'check_idempotency_token', array( 'quote' ) );
        } );

        $this->assertSame( 'submission_processing', $this->sent['data']['code'] );
    }

    public function test_retry_of_a_completed_submission_id_gets_the_stored_response() {
        $this->post();
        $first = $this->handler();
        $this->call( $first, 'check_idempotency_token', array( 'quote' ) );
        $this->call( $first, 'store_idempotency_response', array( array( 'success' => true, 'message' => 'Stored' ) ) );

        $again = $this->call( $this->handler(), 'check_idempotency_token', array( 'quote' ) );
        $this->assertSame( 'Stored', $again['message'] );
    }

    public function test_hidden_file_fields_are_left_out_of_the_upload_steps() {
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-conditions.php';
        $config = array( 'fields' => array(
            array( 'key' => 'design_ready', 'type' => 'radio', 'options' => array( 'ready', 'need_help' ) ),
            array( 'key' => 'design_file', 'type' => 'file', 'conditions' => array(
                'rules' => array( array( 'field' => 'design_ready', 'operator' => 'equals', 'value' => 'ready' ) ),
                'logic' => 'and',
            ) ),
        ) );

        $_POST = array( 'pforms_field_design_ready' => 'need_help' );
        $kept  = $this->call( $this->handler(), 'without_hidden_file_fields', array( $config ) );
        $this->assertSame( array( 'design_ready' ), array_column( $kept['fields'], 'key' ) );

        $_POST = array( 'pforms_field_design_ready' => 'ready' );
        $kept  = $this->call( $this->handler(), 'without_hidden_file_fields', array( $config ) );
        $this->assertSame( array( 'design_ready', 'design_file' ), array_column( $kept['fields'], 'key' ) );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    /**
     * Age a claim so it counts as expired.
     */
    private function age( $key, $seconds ) {
        $name   = '_transient_' . $key;
        $record = json_decode( $this->db->rows[ $name ], true );
        $record['exp'] = time() - $seconds;
        $this->db->rows[ $name ] = json_encode( $record );
        $this->db->rows[ '_transient_timeout_' . $key ] = (string) $record['exp'];
    }

    private function handler() {
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-sanitizer.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-honeypot.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-submission-handler.php';

        $send = function ( $success ) {
            return function ( $data = null ) use ( $success ) {
                $this->sent = array( 'success' => $success, 'data' => $data );
                throw new \RuntimeException( 'wp_send_json' );
            };
        };
        // The honeypot's field name is an HMAC over a stored secret.
        Functions\when( 'get_option' )->justReturn( 'test-honeypot-secret' );
        Functions\when( 'wp_send_json_success' )->alias( $send( true ) );
        Functions\when( 'wp_send_json_error' )->alias( $send( false ) );

        return new \PForms_Submission_Handler();
    }

    private function post() {
        $_POST = array(
            'pforms_form_id'         => 'quote',
            '_wpnonce'               => 'n',
            '_pforms_submission_id'  => '4f7f8366-6dea-49b1-9c49-ade8768e165f',
            'pforms_field_full_name' => 'Jordan',
        );
    }

    private function config() {
        return array( 'settings' => array( 'success_message' => 'Thanks — we have your request.' ) );
    }

    private function call( $object, $method, array $args ) {
        $ref = new \ReflectionMethod( $object, $method );
        if ( PHP_VERSION_ID < 80100 ) {
            $ref->setAccessible( true );
        }
        return $ref->invokeArgs( $object, $args );
    }

    private function expect_exit( callable $fn ) {
        try {
            $fn();
            $this->fail( 'Expected the handler to send a JSON response.' );
        } catch ( \RuntimeException $e ) {
            $this->assertSame( 'wp_send_json', $e->getMessage() );
        }
    }
}
