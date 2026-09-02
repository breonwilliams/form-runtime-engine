<?php
/**
 * Email Notification Integration Tests.
 *
 * Tests for email notification workflow.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for email notifications.
 */
class EmailNotificationTest extends IntegrationTestCase {

    /**
     * Captured emails for testing.
     *
     * @var array
     */
    private $captured_emails = array();

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        $this->captured_emails = array();

        // Hook into wp_mail to capture emails.
        add_filter( 'wp_mail', array( $this, 'capture_email' ), 10, 1 );

        // Prevent actual email sending.
        add_filter( 'pre_wp_mail', '__return_true' );

        // Register a test form.
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'contact', $config );
    }

    /**
     * Tear down after each test.
     */
    public function tear_down() {
        remove_filter( 'wp_mail', array( $this, 'capture_email' ) );
        remove_filter( 'pre_wp_mail', '__return_true' );

        parent::tear_down();
    }

    /**
     * Capture email for testing.
     *
     * @param array $args Email arguments.
     * @return array
     */
    public function capture_email( $args ) {
        $this->captured_emails[] = $args;
        return $args;
    }

    /**
     * Test notification sent on submission.
     */
    public function test_notification_sent_on_submission() {
        $email_handler = new \PForms_Email_Notification();
        $form_config   = pforms_get_form( 'contact' );

        $entry_data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'Test message.',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        // Email should have been captured.
        $this->assertNotEmpty( $this->captured_emails );
    }

    /**
     * Test template variables are replaced.
     */
    public function test_template_variables_replaced() {
        $email_handler = new \PForms_Email_Notification();

        // Get admin email for comparison.
        $admin_email = get_option( 'admin_email' );
        $site_name   = get_option( 'blogname' );

        $form_config = array(
            'title'    => 'Test Form',
            'fields'   => array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
            ),
            'settings' => array(
                'notification' => array(
                    'enabled'    => true,
                    'to'         => '{admin_email}',
                    'subject'    => 'New submission from {site_name}',
                    'from_name'  => '{site_name}',
                    'from_email' => '{admin_email}',
                    'reply_to'   => '{field:email}',
                ),
            ),
        );

        $entry_data = array(
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertNotEmpty( $this->captured_emails );

        $email = $this->captured_emails[0];

        // Check recipient (can be array or string).
        $to = is_array( $email['to'] ) ? $email['to'][0] : $email['to'];
        $this->assertEquals( $admin_email, $to );

        // Check subject has site name.
        $this->assertStringContainsString( $site_name, $email['subject'] );
    }

    /**
     * Test reply-to header set correctly.
     */
    public function test_reply_to_set_correctly() {
        $email_handler = new \PForms_Email_Notification();

        $form_config = array(
            'title'    => 'Test Form',
            'fields'   => array(
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
            ),
            'settings' => array(
                'notification' => array(
                    'enabled'  => true,
                    'to'       => get_option( 'admin_email' ),
                    'subject'  => 'Test',
                    'reply_to' => '{field:email}',
                ),
            ),
        );

        $entry_data = array(
            'email' => 'submitter@example.com',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertNotEmpty( $this->captured_emails );

        $email   = $this->captured_emails[0];
        $headers = $email['headers'];

        // Check Reply-To header.
        $has_reply_to = false;
        if ( is_array( $headers ) ) {
            foreach ( $headers as $header ) {
                if ( stripos( $header, 'Reply-To: submitter@example.com' ) !== false ) {
                    $has_reply_to = true;
                    break;
                }
            }
        } elseif ( is_string( $headers ) ) {
            $has_reply_to = stripos( $headers, 'Reply-To: submitter@example.com' ) !== false;
        }

        $this->assertTrue( $has_reply_to, 'Reply-To header should be set to submitter email' );
    }

    /**
     * Test notification disabled skips email.
     */
    public function test_notification_disabled_skips_email() {
        $email_handler = new \PForms_Email_Notification();

        $form_config = array(
            'title'    => 'Test Form',
            'fields'   => array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
            ),
            'settings' => array(
                'notification' => array(
                    'enabled' => false,
                ),
            ),
        );

        $entry_data = array( 'name' => 'Test' );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        // Email should not have been captured.
        $this->assertEmpty( $this->captured_emails );
    }

    /**
     * Test email body contains field values.
     */
    public function test_email_body_contains_field_values() {
        $email_handler = new \PForms_Email_Notification();
        $form_config   = pforms_get_form( 'contact' );

        $entry_data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'This is my test message.',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertNotEmpty( $this->captured_emails );

        $email = $this->captured_emails[0];
        $body  = $email['message'];

        // Check body contains field values.
        $this->assertStringContainsString( 'John Doe', $body );
        $this->assertStringContainsString( 'john@example.com', $body );
        $this->assertStringContainsString( '555-123-4567', $body );
        $this->assertStringContainsString( 'This is my test message.', $body );
    }

    /**
     * Test multiple recipients.
     */
    public function test_multiple_recipients() {
        $email_handler = new \PForms_Email_Notification();

        $form_config = array(
            'title'    => 'Test Form',
            'fields'   => array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
            ),
            'settings' => array(
                'notification' => array(
                    'enabled' => true,
                    'to'      => 'admin@example.com, support@example.com',
                    'subject' => 'Test',
                ),
            ),
        );

        $entry_data = array( 'name' => 'Test' );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertNotEmpty( $this->captured_emails );

        // Check both recipients are included.
        $email = $this->captured_emails[0];
        $to    = $email['to'];

        // $to can be array or string depending on wp_mail implementation.
        if ( is_array( $to ) ) {
            $to_string = implode( ', ', $to );
        } else {
            $to_string = $to;
        }

        $this->assertStringContainsString( 'admin@example.com', $to_string );
        $this->assertStringContainsString( 'support@example.com', $to_string );
    }

    /**
     * Test pforms_notification_body filter.
     */
    public function test_notification_body_filter() {
        $filter_called = false;

        add_filter( 'pforms_notification_body', function( $body, $form_config, $entry_data, $entry_id ) use ( &$filter_called ) {
            $filter_called = true;
            return $body . "\n\nCustom footer added by filter.";
        }, 10, 4 );

        $email_handler = new \PForms_Email_Notification();
        $form_config   = pforms_get_form( 'contact' );

        $entry_data = array(
            'name'    => 'Test',
            'email'   => 'test@example.com',
            'message' => 'Test message.',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertTrue( $filter_called, 'pforms_notification_body filter should have been called' );

        $email = $this->captured_emails[0];
        $this->assertStringContainsString( 'Custom footer added by filter', $email['message'] );
    }

    /**
     * Test notification sent action fires.
     */
    public function test_notification_sent_action_fires() {
        $action_fired = false;

        add_action( 'pforms_notification_sent', function( $sent, $entry_id, $form_config, $entry_data ) use ( &$action_fired ) {
            $action_fired = true;
            $this->assertEquals( 1, $entry_id );
        }, 10, 4 );

        $email_handler = new \PForms_Email_Notification();
        $form_config   = pforms_get_form( 'contact' );

        $entry_data = array(
            'name'    => 'Test',
            'email'   => 'test@example.com',
            'message' => 'Test message.',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $this->assertTrue( $action_fired, 'pforms_notification_sent action should have fired' );
    }

    /**
     * Test HTML content is escaped in email body.
     */
    public function test_html_escaped_in_email_body() {
        $email_handler = new \PForms_Email_Notification();
        $form_config   = pforms_get_form( 'contact' );

        $entry_data = array(
            'name'    => '<script>alert("xss")</script>John',
            'email'   => 'john@example.com',
            'message' => 'Test message.',
        );

        $result = $email_handler->send( 1, $form_config, $entry_data, array() );

        $email = $this->captured_emails[0];
        $body  = $email['message'];

        // Script tags should be escaped or stripped.
        $this->assertStringNotContainsString( '<script>', $body );
    }
}
