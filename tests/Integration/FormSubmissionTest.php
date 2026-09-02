<?php
/**
 * Form Submission Integration Tests.
 *
 * Tests for form submission workflow.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for form submission.
 */
class FormSubmissionTest extends IntegrationTestCase {

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        // Register a test form.
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'contact', $config );

        // Clean up any existing entries.
        $this->clean_entries();
    }

    /**
     * Test valid submission creates entry.
     */
    public function test_valid_submission_creates_entry() {
        global $wpdb;

        $data = $this->create_submission_request( 'contact', array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'This is a test message.',
        ) );

        // Disable honeypot for this test.
        $data[ $this->get_honeypot_field_name( 'contact' ) ] = '';

        // Mock submission.
        $_POST = $data;

        $entry_repo = new \PForms_Entry();
        $entry_id   = $entry_repo->create( 'contact', array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'This is a test message.',
        ) );

        $this->assertIsInt( $entry_id );
        $this->assertGreaterThan( 0, $entry_id );

        // Verify entry exists in database.
        $entry = $entry_repo->get( $entry_id );

        $this->assertNotNull( $entry );
        $this->assertEquals( 'contact', $entry['form_id'] );
        $this->assertEquals( 'John Doe', $entry['fields']['name'] );
        $this->assertEquals( 'john@example.com', $entry['fields']['email'] );
    }

    /**
     * Test required field validation.
     */
    public function test_required_field_validation() {
        $validator   = new \PForms_Validator();
        $form_config = pforms_get_form( 'contact' );

        // Missing required name and message.
        $data = array(
            'name'    => '',
            'email'   => 'test@example.com',
            'phone'   => '',
            'message' => '',
        );

        $result = $validator->validate( $form_config, $data );

        $this->assertInstanceOf( 'WP_Error', $result );

        $error_data = $result->get_error_data();
        $this->assertArrayHasKey( 'field_errors', $error_data );
        $this->assertArrayHasKey( 'name', $error_data['field_errors'] );
        $this->assertArrayHasKey( 'message', $error_data['field_errors'] );
    }

    /**
     * Test email field validation.
     */
    public function test_email_field_validation() {
        $validator   = new \PForms_Validator();
        $form_config = pforms_get_form( 'contact' );

        // Invalid email.
        $data = array(
            'name'    => 'Test User',
            'email'   => 'not-valid-email',
            'phone'   => '',
            'message' => 'Test message.',
        );

        $result = $validator->validate( $form_config, $data );

        $this->assertInstanceOf( 'WP_Error', $result );

        $error_data = $result->get_error_data();
        $this->assertArrayHasKey( 'email', $error_data['field_errors'] );
    }

    /**
     * Test nonce verification.
     */
    public function test_nonce_verification() {
        $data = array(
            'pforms_form_id'       => 'contact',
            '_wpnonce'          => 'invalid_nonce',
            'name'    => 'Test',
            'email'   => 'test@example.com',
            'message' => 'Test message.',
        );

        $_POST = $data;

        // Verify nonce fails.
        $valid = wp_verify_nonce( $data['_wpnonce'], 'pforms_submit_contact' );

        $this->assertFalse( $valid );
    }

    /**
     * Test valid nonce passes.
     */
    public function test_valid_nonce_passes() {
        $nonce = wp_create_nonce( 'pforms_submit_contact' );

        $valid = wp_verify_nonce( $nonce, 'pforms_submit_contact' );

        $this->assertNotFalse( $valid );
    }

    /**
     * Test honeypot blocks bots.
     */
    public function test_honeypot_blocks_bots() {
        $honeypot   = new \PForms_Honeypot();
        $field_name = $honeypot->get_field_name( 'contact' );

        // Simulate bot filling honeypot.
        $_POST[ $field_name ] = 'spam content';

        $result = $honeypot->is_triggered( 'contact' );

        $this->assertTrue( $result );
    }

    /**
     * Test honeypot empty passes.
     */
    public function test_honeypot_empty_passes() {
        $honeypot   = new \PForms_Honeypot();
        $field_name = $honeypot->get_field_name( 'contact' );

        // Legitimate user leaves honeypot empty.
        $_POST[ $field_name ] = '';

        $result = $honeypot->is_triggered( 'contact' );

        $this->assertFalse( $result );
    }

    /**
     * Test timing check blocks fast submissions.
     */
    public function test_timing_check_blocks_fast_submissions() {
        $timing = new \PForms_Timing_Check();

        // Simulate submission within 1 second.
        $_POST['_pforms_timestamp'] = time() - 1;

        $settings = array(
            'min_submission_time' => 3,
        );

        $result = $timing->validate( 'contact', $settings );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertEquals( 'timing_check_failed', $result->get_error_code() );
    }

    /**
     * Test timing check passes for slow submissions.
     */
    public function test_timing_check_passes_slow_submissions() {
        $timing = new \PForms_Timing_Check();

        // Simulate submission after 5 seconds.
        $_POST['_pforms_timestamp'] = time() - 5;

        $settings = array(
            'min_submission_time' => 3,
        );

        $result = $timing->validate( 'contact', $settings );

        $this->assertTrue( $result );
    }

    /**
     * Test rate limiting.
     */
    public function test_rate_limiting() {
        $rate_limiter = new \PForms_Rate_Limiter();

        $settings = array(
            'max'    => 3,
            'window' => 60,
        );

        // Make 3 submissions (should pass).
        for ( $i = 0; $i < 3; $i++ ) {
            $result = $rate_limiter->validate( 'contact', $settings );
            $this->assertTrue( $result, "Submission {$i} should pass" );
        }

        // 4th submission should fail.
        $result = $rate_limiter->validate( 'contact', $settings );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertEquals( 'rate_limit_exceeded', $result->get_error_code() );
    }

    /**
     * Test sanitizer sanitizes input.
     */
    public function test_sanitizer_sanitizes_input() {
        $sanitizer   = new \PForms_Sanitizer();
        $form_config = pforms_get_form( 'contact' );

        $data = array(
            'name'    => '  John Doe  ',
            'email'   => 'JOHN@EXAMPLE.COM',
            'phone'   => '(555) 123-4567',
            'message' => '<script>alert("xss")</script>Hello',
        );

        $sanitized = $sanitizer->sanitize( $form_config, $data );

        // Name should be trimmed.
        $this->assertEquals( 'John Doe', $sanitized['name'] );

        // Email should be sanitized (WordPress sanitize_email preserves case).
        $this->assertEquals( 'JOHN@EXAMPLE.COM', $sanitized['email'] );

        // Message should have script tags removed.
        $this->assertStringNotContainsString( '<script>', $sanitized['message'] );
    }

    /**
     * Test duplicate submission detection.
     */
    public function test_duplicate_submission_detection() {
        $entry_repo = new \PForms_Entry();

        $data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'message' => 'Test message.',
        );

        // First submission.
        $is_duplicate = $entry_repo->is_duplicate( 'contact', $data );
        $this->assertFalse( $is_duplicate );

        // Same data submitted again.
        $is_duplicate = $entry_repo->is_duplicate( 'contact', $data );
        $this->assertTrue( $is_duplicate );
    }

    /**
     * Test entry with metadata is stored correctly.
     */
    public function test_entry_metadata_storage() {
        $entry_repo = new \PForms_Entry();

        $data = array(
            'name'     => 'Jane Doe',
            'email'    => 'jane@example.com',
            'phone'    => '555-987-6543',
            'message'  => 'Another test message.',
        );

        $entry_id = $entry_repo->create( 'contact', $data );
        $entry    = $entry_repo->get( $entry_id );

        $this->assertEquals( 'Jane Doe', $entry['fields']['name'] );
        $this->assertEquals( 'jane@example.com', $entry['fields']['email'] );
        $this->assertEquals( '555-987-6543', $entry['fields']['phone'] );
        $this->assertEquals( 'Another test message.', $entry['fields']['message'] );
    }

    /**
     * Test conditional field validation.
     */
    public function test_conditional_field_validation() {
        // Register conditional form.
        $config = $this->load_form_fixture( 'conditional-logic' );
        $this->register_form( 'conditional', $config );

        $validator   = new \PForms_Validator();
        $form_config = pforms_get_form( 'conditional' );

        // When issue_type is 'billing', order_number should be visible.
        $data = array(
            'name'         => 'Test User',
            'email'        => 'test@example.com',
            'issue_type'   => 'billing',
            'order_number' => '', // Empty but not required.
            'message'      => 'Test message.',
        );

        $result = $validator->validate( $form_config, $data );

        // Should pass (order_number is not required).
        $this->assertTrue( $result );
    }

    /**
     * Test hidden conditional field skips validation.
     */
    public function test_hidden_conditional_field_skips_validation() {
        // Register conditional form.
        $config = $this->load_form_fixture( 'conditional-logic' );
        $this->register_form( 'conditional2', $config );

        $validator   = new \PForms_Validator();
        $form_config = pforms_get_form( 'conditional2' );

        // When issue_type is 'other', browser should be hidden.
        $data = array(
            'name'          => 'Test User',
            'email'         => 'test@example.com',
            'issue_type'    => 'other',
            'other_details' => 'Some details',
            'browser'       => '', // Should be skipped.
            'message'       => 'Test message.',
        );

        $result = $validator->validate( $form_config, $data );

        $this->assertTrue( $result );
    }

    /**
     * Get honeypot field name for a form.
     *
     * @param string $form_id Form ID.
     * @return string Honeypot field name.
     */
    private function get_honeypot_field_name( $form_id ) {
        $honeypot = new \PForms_Honeypot();
        return $honeypot->get_field_name( $form_id );
    }
}
