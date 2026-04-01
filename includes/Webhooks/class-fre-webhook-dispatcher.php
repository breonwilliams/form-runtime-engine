<?php
/**
 * Webhook Dispatcher for Form Runtime Engine.
 *
 * Sends form submission data to configured webhook endpoints.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook dispatcher class.
 */
class FRE_Webhook_Dispatcher {

    /**
     * HTTP request timeout in seconds.
     *
     * @var int
     */
    const TIMEOUT = 15;

    /**
     * Initialize the webhook dispatcher hooks.
     */
    public static function init() {
        add_action( 'fre_entry_created', array( __CLASS__, 'dispatch' ), 10, 3 );
    }

    /**
     * Dispatch webhook for a form submission.
     *
     * @param int    $entry_id Entry ID.
     * @param string $form_id  Form ID.
     * @param array  $data     Entry field data.
     */
    public static function dispatch( $entry_id, $form_id, $data ) {
        // Get form data from database.
        $form_data = FRE_Forms_Manager::get_form( $form_id );

        // Check if webhook is enabled for this form.
        if ( ! self::is_webhook_enabled( $form_data ) ) {
            return;
        }

        $webhook_url = isset( $form_data['webhook_url'] ) ? $form_data['webhook_url'] : '';

        // Validate URL before sending.
        if ( empty( $webhook_url ) ) {
            return;
        }

        // Re-validate URL at dispatch time (defense against DNS rebinding attacks).
        // URL was validated at save time, but DNS could have changed since then.
        $validation = FRE_Webhook_Validator::validate( $webhook_url );
        if ( is_wp_error( $validation ) ) {
            FRE_Logger::warning( sprintf(
                'Webhook blocked [form: %s, entry: %d]: %s',
                $form_id,
                $entry_id,
                $validation->get_error_message()
            ) );
            return;
        }

        // Build the payload.
        $payload = self::build_payload( $entry_id, $form_id, $data, $form_data );

        // Send the webhook (non-blocking).
        self::send( $webhook_url, $payload, $entry_id, $form_id );
    }

    /**
     * Check if webhook is enabled for a form.
     *
     * @param array|null $form_data Form data from database.
     * @return bool True if webhook is enabled.
     */
    private static function is_webhook_enabled( $form_data ) {
        if ( empty( $form_data ) ) {
            return false;
        }

        // Check webhook_enabled flag.
        if ( ! isset( $form_data['webhook_enabled'] ) || ! $form_data['webhook_enabled'] ) {
            return false;
        }

        // Check webhook_url exists.
        if ( empty( $form_data['webhook_url'] ) ) {
            return false;
        }

        return true;
    }

    /**
     * Build the webhook payload.
     *
     * @param int    $entry_id  Entry ID.
     * @param string $form_id   Form ID.
     * @param array  $data      Entry field data.
     * @param array  $form_data Form configuration data.
     * @return array Webhook payload.
     */
    private static function build_payload( $entry_id, $form_id, $data, $form_data ) {
        // Get form config for title.
        $form_config = array();
        if ( ! empty( $form_data['config'] ) ) {
            $form_config = json_decode( $form_data['config'], true );
        }

        $form_title = '';
        if ( ! empty( $form_data['title'] ) ) {
            $form_title = $form_data['title'];
        } elseif ( ! empty( $form_config['title'] ) ) {
            $form_title = $form_config['title'];
        }

        // Get entry files.
        $entry_handler = new FRE_Entry();
        $files         = $entry_handler->get_files( $entry_id );

        // Format files for payload.
        $files_payload = array();
        foreach ( $files as $file ) {
            $files_payload[] = array(
                'field_key' => $file['field_key'],
                'file_name' => $file['file_name'],
                'file_size' => (int) $file['file_size'],
                'mime_type' => $file['mime_type'],
            );
        }

        // Build timestamp in ISO 8601 format.
        $timestamp = gmdate( 'c' );

        // Build the payload.
        $payload = array(
            'event'     => 'form_submission',
            'timestamp' => $timestamp,
            'form'      => array(
                'id'    => $form_id,
                'title' => $form_title,
            ),
            'entry'     => array(
                'id'           => $entry_id,
                'submitted_at' => $timestamp,
            ),
            'data'      => self::sanitize_data_for_payload( $data ),
            'files'     => $files_payload,
            'site'      => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
            ),
        );

        /**
         * Filter the webhook payload before sending.
         *
         * @param array  $payload  The webhook payload.
         * @param int    $entry_id Entry ID.
         * @param string $form_id  Form ID.
         * @param array  $data     Original entry data.
         */
        return apply_filters( 'fre_webhook_payload', $payload, $entry_id, $form_id, $data );
    }

    /**
     * Sanitize data for webhook payload.
     *
     * Ensures sensitive fields are not accidentally exposed.
     *
     * @param array $data Entry field data.
     * @return array Sanitized data.
     */
    private static function sanitize_data_for_payload( $data ) {
        $sanitized = array();

        foreach ( $data as $key => $value ) {
            // Skip internal fields.
            if ( strpos( $key, '_fre_' ) === 0 ) {
                continue;
            }

            // Skip honeypot fields.
            if ( $key === 'fre_hp_field' || $key === 'fre_timestamp' ) {
                continue;
            }

            // Handle arrays (e.g., checkbox groups).
            if ( is_array( $value ) ) {
                $sanitized[ $key ] = array_map( 'strval', $value );
            } else {
                $sanitized[ $key ] = (string) $value;
            }
        }

        return $sanitized;
    }

    /**
     * Send the webhook request.
     *
     * Uses wp_remote_post with blocking set to false for non-blocking behavior.
     *
     * @param string $url      Webhook URL.
     * @param array  $payload  Payload data.
     * @param int    $entry_id Entry ID for logging.
     * @param string $form_id  Form ID for logging.
     */
    private static function send( $url, $payload, $entry_id, $form_id ) {
        $args = array(
            'method'      => 'POST',
            'timeout'     => self::TIMEOUT,
            'redirection' => 0, // Disable redirects to prevent redirect-based SSRF attacks.
            'httpversion' => '1.1',
            'blocking'    => false, // Non-blocking request.
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'FormRuntimeEngine/' . FRE_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
                'X-FRE-Event'  => 'form_submission',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        /**
         * Filter webhook request arguments.
         *
         * @param array  $args     Request arguments for wp_remote_post.
         * @param string $url      Webhook URL.
         * @param array  $payload  Payload data.
         * @param int    $entry_id Entry ID.
         * @param string $form_id  Form ID.
         */
        $args = apply_filters( 'fre_webhook_request_args', $args, $url, $payload, $entry_id, $form_id );

        // Send the request.
        $response = wp_remote_post( $url, $args );

        // Log errors.
        if ( is_wp_error( $response ) ) {
            FRE_Logger::error( sprintf(
                'Webhook Error [form: %s, entry: %d]: %s',
                $form_id,
                $entry_id,
                $response->get_error_message()
            ) );

            /**
             * Fires when a webhook request fails.
             *
             * @param WP_Error $response  The error response.
             * @param string   $url       Webhook URL.
             * @param array    $payload   Payload data.
             * @param int      $entry_id  Entry ID.
             * @param string   $form_id   Form ID.
             */
            do_action( 'fre_webhook_failed', $response, $url, $payload, $entry_id, $form_id );
        } else {
            /**
             * Fires after a webhook request is sent.
             *
             * Note: With blocking=false, we don't know the actual response.
             *
             * @param string $url       Webhook URL.
             * @param array  $payload   Payload data.
             * @param int    $entry_id  Entry ID.
             * @param string $form_id   Form ID.
             */
            do_action( 'fre_webhook_sent', $url, $payload, $entry_id, $form_id );
        }
    }

    /**
     * Test a webhook URL by sending a test payload.
     *
     * This method sends a blocking request to verify the webhook works.
     *
     * @param string $url     Webhook URL to test.
     * @param string $form_id Form ID for context.
     * @return true|WP_Error True on success, WP_Error on failure.
     */
    public static function test( $url, $form_id = 'test' ) {
        // Validate URL first.
        $validation = FRE_Webhook_Validator::validate( $url );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Build test payload.
        $payload = array(
            'event'     => 'webhook_test',
            'timestamp' => gmdate( 'c' ),
            'form'      => array(
                'id'    => $form_id,
                'title' => 'Webhook Test',
            ),
            'message'   => 'This is a test webhook from Form Runtime Engine.',
            'site'      => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
            ),
        );

        $args = array(
            'method'      => 'POST',
            'timeout'     => self::TIMEOUT,
            'redirection' => 0, // Disable redirects to prevent redirect-based SSRF attacks.
            'httpversion' => '1.1',
            'blocking'    => true, // Blocking for test to get response.
            'headers'     => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'User-Agent'   => 'FormRuntimeEngine/' . FRE_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
                'X-FRE-Event'  => 'webhook_test',
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        $response = wp_remote_post( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );

        // Accept 2xx and some 3xx responses as success.
        if ( $response_code >= 200 && $response_code < 400 ) {
            return true;
        }

        return new WP_Error(
            'webhook_failed',
            sprintf(
                /* translators: %d: HTTP response code */
                __( 'Webhook returned HTTP status %d.', 'form-runtime-engine' ),
                $response_code
            )
        );
    }
}
