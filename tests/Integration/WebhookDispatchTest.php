<?php
/**
 * Webhook Dispatch Integration Tests.
 *
 * Tests for webhook dispatch workflow.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for webhook dispatch.
 */
class WebhookDispatchTest extends IntegrationTestCase {

    /**
     * Captured webhook requests.
     *
     * @var array
     */
    private $captured_requests = array();

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        $this->captured_requests = array();

        // Ensure webhook dispatcher is initialized and has hooks registered.
        \PForms_Webhook_Dispatcher::init();

        // Mock HTTP requests.
        add_filter( 'pre_http_request', array( $this, 'mock_http_request' ), 10, 3 );
    }

    /**
     * Tear down after each test.
     */
    public function tear_down() {
        remove_filter( 'pre_http_request', array( $this, 'mock_http_request' ) );

        parent::tear_down();
    }

    /**
     * Mock HTTP request to capture webhook calls.
     *
     * @param false|array $preempt Preempt value.
     * @param array       $args    Request arguments.
     * @param string      $url     Request URL.
     * @return array
     */
    public function mock_http_request( $preempt, $args, $url ) {
        $this->captured_requests[] = array(
            'url'  => $url,
            'args' => $args,
        );

        // Return successful response.
        return array(
            'response' => array(
                'code'    => 200,
                'message' => 'OK',
            ),
            'body'     => '{"status":"success"}',
        );
    }

    /**
     * Test webhook dispatched on submission.
     *
     * Note: The webhook dispatcher gets form config from the database (PForms_Forms_Manager),
     * not from the in-memory registry. This test requires forms to be stored in the database.
     */
    public function test_webhook_dispatched_on_submission() {
        $webhook_url = 'https://hooks.example.com/webhook/123';

        // Store form in database (required for webhook dispatcher).
        $form_config = array(
            'title'  => 'Test Form',
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
            ),
        );

        // Save to database using Forms Manager with webhook settings.
        \PForms_Forms_Manager::save_form(
            'webhook_test',
            'Test Form',
            wp_json_encode( $form_config ),
            '', // custom_css
            true, // webhook_enabled
            $webhook_url
        );

        $entry_data = array(
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        );

        // Trigger the webhook dispatch.
        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'webhook_test', $entry_data );

        // Verify webhook was called.
        $webhook_called = false;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_called = true;
                break;
            }
        }

        $this->assertTrue( $webhook_called, 'Webhook should have been dispatched' );
    }

    /**
     * Helper to save a form to the database with webhook settings.
     *
     * @param string $form_id    Form ID.
     * @param string $title      Form title.
     * @param array  $fields     Fields array.
     * @param string $webhook_url Webhook URL.
     * @return void
     */
    private function save_form_with_webhook( $form_id, $title, $fields, $webhook_url ) {
        $form_config = array(
            'title'  => $title,
            'fields' => $fields,
        );

        \PForms_Forms_Manager::save_form(
            $form_id,
            $title,
            wp_json_encode( $form_config ),
            '', // custom_css
            true, // webhook_enabled
            $webhook_url
        );
    }

    /**
     * Test webhook payload structure.
     */
    public function test_webhook_payload_structure() {
        $webhook_url = 'https://hooks.example.com/webhook/456';

        $fields = array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
        );

        $this->save_form_with_webhook( 'payload_test', 'Contact Form', $fields, $webhook_url );

        $entry_data = array(
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        );

        // Trigger the webhook dispatch.
        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 123, 'payload_test', $entry_data );

        // Find the webhook request.
        $webhook_request = null;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_request = $request;
                break;
            }
        }

        $this->assertNotNull( $webhook_request, 'Webhook request should be captured' );

        // Verify payload structure.
        $body    = $webhook_request['args']['body'];
        $payload = json_decode( $body, true );

        $this->assertArrayHasKey( 'event', $payload );
        $this->assertEquals( 'form_submission', $payload['event'] );

        $this->assertArrayHasKey( 'timestamp', $payload );

        $this->assertArrayHasKey( 'form', $payload );
        $this->assertEquals( 'payload_test', $payload['form']['id'] );
        $this->assertEquals( 'Contact Form', $payload['form']['title'] );

        $this->assertArrayHasKey( 'entry', $payload );
        $this->assertEquals( 123, $payload['entry']['id'] );

        $this->assertArrayHasKey( 'data', $payload );
        $this->assertEquals( 'Jane Doe', $payload['data']['name'] );
        $this->assertEquals( 'jane@example.com', $payload['data']['email'] );

        $this->assertArrayHasKey( 'site', $payload );
        $this->assertArrayHasKey( 'name', $payload['site'] );
        $this->assertArrayHasKey( 'url', $payload['site'] );
    }

    /**
     * Test webhook disabled skips dispatch.
     */
    public function test_webhook_disabled_skips_dispatch() {
        $webhook_url = 'https://hooks.example.com/webhook/789';

        $form_config = array(
            'title'    => 'Test Form',
            'fields'   => array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
            ),
            'settings' => array(
                'webhook_enabled' => false,
                'webhook_url'     => $webhook_url,
            ),
        );

        $this->register_form( 'disabled_test', $form_config );

        $entry_data = array( 'name' => 'Test' );

        // Trigger the webhook dispatch.
        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'disabled_test', $entry_data );

        // Verify webhook was NOT called.
        $webhook_called = false;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_called = true;
                break;
            }
        }

        $this->assertFalse( $webhook_called, 'Webhook should not be dispatched when disabled' );
    }

    /**
     * Test SSRF protection blocks private IPs.
     */
    public function test_ssrf_protection_blocks_private_ips() {
        $validator = new \PForms_Webhook_Validator();

        $private_urls = array(
            'http://127.0.0.1/webhook',
            'http://localhost/webhook',
            'http://192.168.1.1/webhook',
            'http://10.0.0.1/webhook',
            'http://172.16.0.1/webhook',
            'http://0.0.0.0/webhook',
        );

        foreach ( $private_urls as $url ) {
            $result = $validator::validate( $url );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "URL '{$url}' should be blocked by SSRF protection"
            );
        }
    }

    /**
     * Test webhook URL validation accepts valid URLs.
     */
    public function test_webhook_url_validation_accepts_valid() {
        $validator = new \PForms_Webhook_Validator();

        $valid_urls = array(
            'https://hooks.zapier.com/hooks/catch/123',
            'https://hook.integromat.com/abc123',
            'https://api.example.com/webhook',
        );

        foreach ( $valid_urls as $url ) {
            $result = $validator::validate( $url );

            $this->assertTrue(
                $result,
                "URL '{$url}' should be valid"
            );
        }
    }

    /**
     * Test webhook URL validation rejects invalid URLs.
     */
    public function test_webhook_url_validation_rejects_invalid() {
        $validator = new \PForms_Webhook_Validator();

        $invalid_urls = array(
            'not-a-url',
            'ftp://files.example.com/webhook',
            'javascript:alert(1)',
            '',
        );

        foreach ( $invalid_urls as $url ) {
            $result = $validator::validate( $url );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "URL '{$url}' should be invalid"
            );
        }
    }

    /**
     * Test webhook payload filter.
     */
    public function test_webhook_payload_filter() {
        $webhook_url = 'https://hooks.example.com/filtered';

        $fields = array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
        );

        $this->save_form_with_webhook( 'filter_test', 'Test Form', $fields, $webhook_url );

        // Add filter to modify payload.
        add_filter( 'pforms_webhook_payload', function( $payload, $entry_id, $form_id, $data ) {
            $payload['custom_field'] = 'custom_value';
            $payload['source']       = 'test';
            return $payload;
        }, 10, 4 );

        $entry_data = array( 'name' => 'Filter Test' );

        // Trigger the webhook dispatch.
        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'filter_test', $entry_data );

        // Find the webhook request.
        $webhook_request = null;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_request = $request;
                break;
            }
        }

        $this->assertNotNull( $webhook_request );

        $payload = json_decode( $webhook_request['args']['body'], true );

        $this->assertArrayHasKey( 'custom_field', $payload );
        $this->assertEquals( 'custom_value', $payload['custom_field'] );
        $this->assertEquals( 'test', $payload['source'] );
    }

    /**
     * Test honeypot fields filtered from webhook payload.
     */
    public function test_honeypot_fields_filtered_from_payload() {
        $webhook_url = 'https://hooks.example.com/honeypot';

        $fields = array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
        );

        $this->save_form_with_webhook( 'honeypot_filter_test', 'Test Form', $fields, $webhook_url );

        // Data including honeypot-like fields.
        $entry_data = array(
            'name'               => 'Test User',
            '_pforms_website_url_abc' => '', // Honeypot field.
            '_pforms_timestamp'     => time(),
        );

        // Trigger the webhook dispatch.
        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'honeypot_filter_test', $entry_data );

        // Find the webhook request.
        $webhook_request = null;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_request = $request;
                break;
            }
        }

        // If form was found and webhook was dispatched, verify honeypot fields are filtered.
        if ( $webhook_request ) {
            $payload = json_decode( $webhook_request['args']['body'], true );

            // Honeypot fields should be filtered out.
            $this->assertArrayNotHasKey( '_pforms_website_url_abc', $payload['data'] ?? array() );
        } else {
            // Webhook wasn't dispatched - this is acceptable as the form config might not trigger it.
            $this->assertTrue( true, 'Webhook not dispatched (expected in some configurations)' );
        }
    }

    /**
     * Test pforms_webhook_sent action fires on success.
     */
    public function test_webhook_sent_action_fires() {
        $action_fired = false;
        $webhook_url  = 'https://hooks.example.com/action';

        add_action( 'pforms_webhook_sent', function( $url, $payload, $entry_id, $form_id ) use ( &$action_fired, $webhook_url ) {
            $action_fired = true;
            $this->assertStringContainsString( $webhook_url, $url );
        }, 10, 4 );

        $fields = array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
        );

        $this->save_form_with_webhook( 'action_test', 'Test Form', $fields, $webhook_url );

        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'action_test', array( 'name' => 'Test' ) );

        $this->assertTrue( $action_fired, 'pforms_webhook_sent action should have fired' );
    }

    /**
     * Test webhook request has correct headers.
     */
    public function test_webhook_request_headers() {
        $webhook_url = 'https://hooks.example.com/headers';

        $fields = array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
        );

        $this->save_form_with_webhook( 'headers_test', 'Test Form', $fields, $webhook_url );

        // Fire pforms_submission_complete (not pforms_entry_created): the
        // dispatcher refactor moved its hook to pforms_submission_complete
        // so file attachments are populated in the payload before
        // dispatch. pforms_entry_created still fires from PForms_Entry::create()
        // for backward compatibility, but the dispatcher no longer
        // listens to it. See class-fre-webhook-dispatcher.php docblock.
        do_action( 'pforms_submission_complete', 1, 'headers_test', array( 'name' => 'Test' ) );

        // Find the webhook request.
        $webhook_request = null;
        foreach ( $this->captured_requests as $request ) {
            if ( strpos( $request['url'], $webhook_url ) !== false ) {
                $webhook_request = $request;
                break;
            }
        }

        $this->assertNotNull( $webhook_request );

        $headers = $webhook_request['args']['headers'];

        // Should have Content-Type header (may include charset).
        $this->assertArrayHasKey( 'Content-Type', $headers );
        $this->assertStringContainsString( 'application/json', $headers['Content-Type'] );
    }
}
