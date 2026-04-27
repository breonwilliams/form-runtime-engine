<?php
/**
 * Webhook Dispatcher for Form Runtime Engine.
 *
 * Sends form submission data to configured webhook endpoints.
 * Includes delivery logging, retry mechanism, and status tracking.
 *
 * Retry pattern mirrors FRE_Email_Notification for consistency.
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
     * HTTP request timeout in seconds for initial delivery.
     *
     * @var int
     */
    const TIMEOUT = 5;

    /**
     * HTTP request timeout in seconds for retries (more lenient).
     *
     * @var int
     */
    const RETRY_TIMEOUT = 15;

    /**
     * Maximum retry attempts.
     *
     * @var int
     */
    const MAX_RETRIES = 3;

    /**
     * Retry delays in seconds (exponential backoff).
     * Mirrors the email notification pattern: 1 min, 5 min, 30 min.
     *
     * @var array
     */
    const RETRY_DELAYS = array( 60, 300, 1800 );

    /**
     * Initialize the webhook dispatcher hooks.
     */
    public static function init() {
        add_action( 'fre_entry_created', array( __CLASS__, 'dispatch' ), 10, 3 );

        // Retry processing hooks (mirrors FRE_Email_Notification pattern).
        add_action( 'fre_retry_webhook', array( __CLASS__, 'process_retry' ), 10, 1 );
        add_action( 'fre_process_webhook_queue', array( __CLASS__, 'process_queue' ) );

        // Schedule hourly queue processing if not already scheduled.
        if ( ! wp_next_scheduled( 'fre_process_webhook_queue' ) ) {
            wp_schedule_event( time(), 'hourly', 'fre_process_webhook_queue' );
        }

        // Schedule daily log pruning.
        if ( ! wp_next_scheduled( 'fre_prune_webhook_log' ) ) {
            wp_schedule_event( time(), 'daily', 'fre_prune_webhook_log' );
        }
        add_action( 'fre_prune_webhook_log', array( __CLASS__, 'prune_log' ) );
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

        // Create log entry.
        $log = new FRE_Webhook_Log();
        $log_id = false;

        if ( $log->table_exists() ) {
            $log_id = $log->create( $entry_id, $form_id, $webhook_url );
        }

        // Get webhook secret for HMAC signing (if configured).
        $webhook_secret = isset( $form_data['webhook_secret'] ) ? $form_data['webhook_secret'] : '';

        // Send the webhook (blocking so we can log the result).
        self::send( $webhook_url, $payload, $entry_id, $form_id, $log_id, 0, $webhook_secret );
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

        // Webhook preset (google_sheets|zapier|make|custom) — drives the
        // smart default for option-label resolution in sanitize_data_for_payload.
        $webhook_preset = isset( $form_data['webhook_preset'] ) ? $form_data['webhook_preset'] : 'custom';

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
            'data'      => self::sanitize_data_for_payload( $data, $form_config, $webhook_preset ),
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
     * Strips internal/honeypot fields, then optionally resolves option values
     * (e.g. "home_services") to their human-readable labels (e.g.
     * "Home services (HVAC, plumbing, roofing, etc.)") for select / radio /
     * checkbox-with-options fields.
     *
     * Resolution decision (in priority order):
     *   1. Explicit per-form `settings.webhook_resolve_option_labels` boolean
     *      setting takes precedence when present.
     *   2. Otherwise, fall back to a preset-aware default — the
     *      "google_sheets" preset enables label resolution by default
     *      (the destination is typically a human-reviewed lead tracker
     *      where labels are easier to scan), while "zapier", "make", and
     *      "custom" default to raw values (those typically feed
     *      machine-readable integrations that prefer stable identifiers
     *      that don't break when option labels are renamed).
     *   3. The `fre_webhook_resolve_option_labels` filter lets sites override
     *      the resolved decision programmatically (e.g. to apply different
     *      logic per form).
     *
     * Storage and the admin entries table always continue to hold raw
     * values — only the outbound webhook payload changes.
     *
     * @param array  $data           Entry field data (raw stored values).
     * @param array  $form_config    Parsed form configuration (fields + settings).
     * @param string $webhook_preset The webhook preset for this form.
     * @return array Sanitized data, possibly with labels resolved.
     */
    private static function sanitize_data_for_payload( $data, $form_config = array(), $webhook_preset = 'custom' ) {
        $sanitized = array();

        // Step 1: determine whether to resolve labels.
        $explicit_setting = isset( $form_config['settings']['webhook_resolve_option_labels'] )
            ? (bool) $form_config['settings']['webhook_resolve_option_labels']
            : null;

        if ( $explicit_setting !== null ) {
            $resolve_labels = $explicit_setting;
        } else {
            // Smart default: human-readable destinations get labels by default,
            // machine destinations get raw values by default.
            $resolve_labels = ( $webhook_preset === 'google_sheets' );
        }

        /**
         * Filter whether to resolve option values to labels in the webhook payload.
         *
         * Use this to override the default resolution logic globally or per form.
         *
         * @param bool   $resolve_labels Whether to resolve labels.
         * @param array  $form_config    Parsed form configuration.
         * @param string $webhook_preset The webhook preset (google_sheets|zapier|make|custom).
         * @param array  $data           Raw entry data being processed.
         */
        $resolve_labels = (bool) apply_filters(
            'fre_webhook_resolve_option_labels',
            $resolve_labels,
            $form_config,
            $webhook_preset,
            $data
        );

        // Step 2: build a fast field-key -> field-config lookup map for label resolution.
        $field_map = array();
        if ( $resolve_labels && ! empty( $form_config['fields'] ) && is_array( $form_config['fields'] ) ) {
            foreach ( $form_config['fields'] as $field ) {
                if ( ! empty( $field['key'] ) ) {
                    $field_map[ $field['key'] ] = $field;
                }
            }
        }

        // Step 3: process each field.
        foreach ( $data as $key => $value ) {
            // Skip internal fields.
            if ( strpos( $key, '_fre_' ) === 0 ) {
                continue;
            }

            // Skip honeypot fields.
            if ( $key === 'fre_hp_field' || $key === 'fre_timestamp' ) {
                continue;
            }

            // Resolve to human-readable label when configured AND we have
            // field config for this key. resolve_display_value handles
            // select / radio / checkbox-with-options (option-label lookup),
            // single checkbox (Yes/No), and falls through to plain
            // stringification for other field types.
            if ( $resolve_labels && isset( $field_map[ $key ] ) ) {
                $sanitized[ $key ] = FRE_Field_Type_Abstract::resolve_display_value( $value, $field_map[ $key ] );
                continue;
            }

            // Default: stringify (preserves existing raw-value behavior for
            // unknown fields and for label-resolution-disabled forms).
            if ( is_array( $value ) ) {
                $sanitized[ $key ] = array_map( 'strval', $value );
            } else {
                $sanitized[ $key ] = (string) $value;
            }
        }

        return $sanitized;
    }

    /**
     * Generate HMAC-SHA256 signature for a webhook payload.
     *
     * The signature allows the receiving endpoint to verify that the request
     * genuinely came from this WordPress site and hasn't been tampered with.
     *
     * @param string $body   The JSON-encoded request body.
     * @param string $secret The webhook secret key.
     * @return string The signature in "sha256={hex_hash}" format.
     */
    public static function generate_signature( $body, $secret ) {
        $hash = hash_hmac( 'sha256', $body, $secret );
        return 'sha256=' . $hash;
    }

    /**
     * Send the webhook request.
     *
     * Uses a blocking request to capture the response for logging and retry decisions.
     * Timeout is kept short (5s) so the user's form submission is not noticeably delayed.
     *
     * @param string   $url      Webhook URL.
     * @param array    $payload  Payload data.
     * @param int      $entry_id Entry ID for logging.
     * @param string   $form_id  Form ID for logging.
     * @param int|false $log_id  Webhook log entry ID, or false if logging unavailable.
     * @param int      $timeout  Request timeout override (used for retries).
     * @param string   $secret   Webhook secret for HMAC signing (empty = no signing).
     */
    private static function send( $url, $payload, $entry_id, $form_id, $log_id = false, $timeout = 0, $secret = '' ) {
        $timeout = $timeout > 0 ? $timeout : self::TIMEOUT;

        $args = array(
            'method'      => 'POST',
            'timeout'     => $timeout,
            'redirection' => 0, // Disable redirects to prevent redirect-based SSRF attacks.
            'httpversion' => '1.1',
            'blocking'    => true, // Blocking to capture response for logging.
            'headers'     => array(
                'Content-Type'    => 'application/json; charset=utf-8',
                'User-Agent'      => 'FormRuntimeEngine/' . FRE_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
                'X-FRE-Event'     => 'form_submission',
                'X-FRE-Timestamp' => (string) time(),
            ),
            'body'        => wp_json_encode( $payload ),
            'data_format' => 'body',
        );

        // Add HMAC signature header if a secret is configured.
        if ( ! empty( $secret ) ) {
            $args['headers']['X-FRE-Signature'] = self::generate_signature( $args['body'], $secret );
        }

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

        // Process the response.
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();

            FRE_Logger::error( sprintf(
                'Webhook Error [form: %s, entry: %d]: %s',
                $form_id,
                $entry_id,
                $error_message
            ) );

            // Log the failure.
            if ( $log_id ) {
                $log = new FRE_Webhook_Log();
                $log->record_attempt( $log_id, FRE_Webhook_Log::STATUS_FAILED, 0, '', $error_message );
                self::maybe_schedule_retry( $log_id );
            }

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
            $response_code = wp_remote_retrieve_response_code( $response );
            $response_body = wp_remote_retrieve_body( $response );

            if ( $response_code >= 200 && $response_code < 400 ) {
                // Success.
                if ( $log_id ) {
                    $log = new FRE_Webhook_Log();
                    $log->record_attempt( $log_id, FRE_Webhook_Log::STATUS_SUCCESS, $response_code, $response_body );
                }

                /**
                 * Fires after a webhook request is sent successfully.
                 *
                 * @param string $url       Webhook URL.
                 * @param array  $payload   Payload data.
                 * @param int    $entry_id  Entry ID.
                 * @param string $form_id   Form ID.
                 */
                do_action( 'fre_webhook_sent', $url, $payload, $entry_id, $form_id );
            } else {
                // HTTP error response.
                $error_message = sprintf( 'HTTP %d response', $response_code );

                FRE_Logger::error( sprintf(
                    'Webhook HTTP Error [form: %s, entry: %d]: %s',
                    $form_id,
                    $entry_id,
                    $error_message
                ) );

                if ( $log_id ) {
                    $log = new FRE_Webhook_Log();
                    $log->record_attempt( $log_id, FRE_Webhook_Log::STATUS_FAILED, $response_code, $response_body, $error_message );
                    self::maybe_schedule_retry( $log_id );
                }

                do_action( 'fre_webhook_failed', $response, $url, $payload, $entry_id, $form_id );
            }
        }
    }

    /**
     * Schedule a retry if the log entry hasn't exceeded max attempts.
     *
     * @param int $log_id Webhook log entry ID.
     */
    private static function maybe_schedule_retry( $log_id ) {
        $log      = new FRE_Webhook_Log();
        $log_entry = $log->get( $log_id );

        if ( ! $log_entry ) {
            return;
        }

        $attempts = (int) $log_entry['attempts'];

        if ( $attempts >= self::MAX_RETRIES ) {
            // Max retries reached — mark as permanently failed.
            $log->record_attempt(
                $log_id,
                FRE_Webhook_Log::STATUS_FAILED,
                (int) $log_entry['response_code'],
                '',
                'Max retries exceeded'
            );

            /**
             * Fires when a webhook has permanently failed after all retry attempts.
             *
             * @param int    $log_id   Webhook log entry ID.
             * @param int    $entry_id Form entry ID.
             * @param string $form_id  Form ID.
             */
            do_action( 'fre_webhook_permanently_failed', $log_id, (int) $log_entry['entry_id'], $log_entry['form_id'] );

            FRE_Logger::error( sprintf(
                'Webhook permanently failed [form: %s, entry: %d, log: %d] after %d attempts',
                $log_entry['form_id'],
                $log_entry['entry_id'],
                $log_id,
                $attempts
            ) );

            return;
        }

        // Calculate delay based on attempt number (0-indexed into RETRY_DELAYS).
        $delay_index = min( $attempts - 1, count( self::RETRY_DELAYS ) - 1 );
        $delay       = self::RETRY_DELAYS[ max( 0, $delay_index ) ];
        $next_retry  = gmdate( 'Y-m-d H:i:s', time() + $delay );

        // Update log status to retrying.
        $log->schedule_retry( $log_id, $next_retry );

        // Schedule the cron event.
        wp_schedule_single_event(
            time() + $delay,
            'fre_retry_webhook',
            array( $log_id )
        );

        FRE_Logger::info( sprintf(
            'Scheduled webhook retry for log %d (entry %d), attempt %d, delay %d seconds',
            $log_id,
            $log_entry['entry_id'],
            $attempts + 1,
            $delay
        ) );
    }

    /**
     * Process a scheduled webhook retry.
     *
     * Called by WP-Cron via the fre_retry_webhook hook.
     *
     * @param int $log_id Webhook log entry ID.
     */
    public static function process_retry( $log_id ) {
        $log       = new FRE_Webhook_Log();
        $log_entry = $log->get( $log_id );

        if ( ! $log_entry ) {
            FRE_Logger::error( "Webhook retry failed - log entry {$log_id} not found" );
            return;
        }

        // Already succeeded (possibly via manual retry or race condition).
        if ( $log_entry['status'] === FRE_Webhook_Log::STATUS_SUCCESS ) {
            return;
        }

        // Re-validate URL at retry time.
        $validation = FRE_Webhook_Validator::validate( $log_entry['webhook_url'] );
        if ( is_wp_error( $validation ) ) {
            $log->record_attempt( $log_id, FRE_Webhook_Log::STATUS_FAILED, 0, '', 'URL validation failed on retry: ' . $validation->get_error_message() );
            return;
        }

        // Reconstruct the payload from the stored entry.
        $entry_handler = new FRE_Entry();
        $entry         = $entry_handler->get( (int) $log_entry['entry_id'] );

        if ( ! $entry ) {
            $log->record_attempt( $log_id, FRE_Webhook_Log::STATUS_FAILED, 0, '', 'Entry not found for retry' );
            return;
        }

        $form_data = FRE_Forms_Manager::get_form( $log_entry['form_id'] );
        $payload   = self::build_payload(
            (int) $log_entry['entry_id'],
            $log_entry['form_id'],
            $entry['fields'] ?? array(),
            $form_data ?: array()
        );

        // Get webhook secret for HMAC signing (if configured).
        $webhook_secret = ! empty( $form_data['webhook_secret'] ) ? $form_data['webhook_secret'] : '';

        // Send with longer timeout for retries.
        self::send(
            $log_entry['webhook_url'],
            $payload,
            (int) $log_entry['entry_id'],
            $log_entry['form_id'],
            $log_id,
            self::RETRY_TIMEOUT,
            $webhook_secret
        );
    }

    /**
     * Process webhook retry queue.
     *
     * Called hourly by WP-Cron to catch any retries that were missed
     * (e.g., if a single event didn't fire). Mirrors the email queue pattern.
     */
    public static function process_queue() {
        $log     = new FRE_Webhook_Log();

        if ( ! $log->table_exists() ) {
            return;
        }

        $due_retries = $log->get_due_retries( 20 );

        foreach ( $due_retries as $log_entry ) {
            // Check if a single event is already scheduled for this log entry.
            if ( wp_next_scheduled( 'fre_retry_webhook', array( (int) $log_entry['id'] ) ) ) {
                continue;
            }

            // Process immediately since it's overdue.
            self::process_retry( (int) $log_entry['id'] );
        }
    }

    /**
     * Prune old log entries.
     *
     * Called daily by WP-Cron to prevent the log table from growing indefinitely.
     * Keeps entries for 30 days.
     */
    public static function prune_log() {
        $log = new FRE_Webhook_Log();

        if ( ! $log->table_exists() ) {
            return;
        }

        /**
         * Filter the number of days to retain webhook log entries.
         *
         * @param int $days Number of days. Default 30.
         */
        $days = apply_filters( 'fre_webhook_log_retention_days', 30 );

        $deleted = $log->prune( $days );

        if ( $deleted > 0 ) {
            FRE_Logger::info( sprintf( 'Pruned %d webhook log entries older than %d days', $deleted, $days ) );
        }
    }

    /**
     * Test a webhook URL by sending a test payload.
     *
     * Returns rich response data so the admin UI can display detailed results.
     *
     * @param string $url     Webhook URL to test.
     * @param string $form_id Form ID for context.
     * @param string $secret  Webhook secret for HMAC signing (empty = no signing).
     * @return array {
     *     @type bool        $success       Whether the test succeeded.
     *     @type int         $response_code HTTP response code (0 if connection failed).
     *     @type string      $response_body Response body (truncated to 2KB).
     *     @type float       $elapsed_ms    Request duration in milliseconds.
     *     @type string|null $error         Error message if failed, null if succeeded.
     * }
     */
    public static function test( $url, $form_id = 'test', $secret = '' ) {
        $result = array(
            'success'       => false,
            'response_code' => 0,
            'response_body' => '',
            'elapsed_ms'    => 0,
            'error'         => null,
        );

        // Validate URL first.
        $validation = FRE_Webhook_Validator::validate( $url );
        if ( is_wp_error( $validation ) ) {
            $result['error'] = $validation->get_error_message();
            return $result;
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

        $body = wp_json_encode( $payload );

        $args = array(
            'method'      => 'POST',
            'timeout'     => self::RETRY_TIMEOUT,
            'redirection' => 0,
            'httpversion' => '1.1',
            'blocking'    => true,
            'headers'     => array(
                'Content-Type'    => 'application/json; charset=utf-8',
                'User-Agent'      => 'FormRuntimeEngine/' . FRE_VERSION . ' (WordPress/' . get_bloginfo( 'version' ) . ')',
                'X-FRE-Event'     => 'webhook_test',
                'X-FRE-Timestamp' => (string) time(),
            ),
            'body'        => $body,
            'data_format' => 'body',
        );

        // Add HMAC signature header if a secret is configured.
        if ( ! empty( $secret ) ) {
            $args['headers']['X-FRE-Signature'] = self::generate_signature( $body, $secret );
        }

        $start    = microtime( true );
        $response = wp_remote_post( $url, $args );
        $elapsed  = ( microtime( true ) - $start ) * 1000;

        $result['elapsed_ms'] = round( $elapsed, 1 );

        if ( is_wp_error( $response ) ) {
            $result['error'] = $response->get_error_message();
            return $result;
        }

        $result['response_code'] = wp_remote_retrieve_response_code( $response );
        $result['response_body'] = substr( wp_remote_retrieve_body( $response ), 0, 2048 );

        if ( $result['response_code'] >= 200 && $result['response_code'] < 400 ) {
            $result['success'] = true;
        } else {
            $result['error'] = sprintf(
                /* translators: %d: HTTP response code */
                __( 'Webhook returned HTTP status %d.', 'form-runtime-engine' ),
                $result['response_code']
            );
        }

        return $result;
    }

    /**
     * Generate a preview of the webhook payload for a given form.
     *
     * Creates a sample payload with placeholder data so admins can see
     * exactly what will be sent to their webhook endpoint.
     *
     * @param string $form_id Form ID.
     * @return array|WP_Error Preview payload array, or WP_Error if form not found.
     */
    public static function preview_payload( $form_id ) {
        $form_data = FRE_Forms_Manager::get_form( $form_id );

        if ( empty( $form_data ) ) {
            return new WP_Error( 'form_not_found', __( 'Form not found.', 'form-runtime-engine' ) );
        }

        // Parse the form config to get field definitions.
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

        // Build sample data from form fields.
        $sample_data  = array();
        $sample_files = array();
        $fields       = isset( $form_config['fields'] ) ? $form_config['fields'] : array();

        foreach ( $fields as $field ) {
            $key  = isset( $field['key'] ) ? $field['key'] : '';
            $type = isset( $field['type'] ) ? $field['type'] : 'text';

            if ( empty( $key ) ) {
                continue;
            }

            // Skip non-data field types.
            if ( in_array( $type, array( 'section', 'message', 'hidden' ), true ) ) {
                continue;
            }

            // Generate sample values based on field type.
            switch ( $type ) {
                case 'email':
                    $sample_data[ $key ] = 'jane@example.com';
                    break;
                case 'tel':
                    $sample_data[ $key ] = '(555) 123-4567';
                    break;
                case 'textarea':
                    $sample_data[ $key ] = 'Sample text entry for ' . $key;
                    break;
                case 'select':
                case 'radio':
                    if ( ! empty( $field['options'][0] ) ) {
                        $opt = $field['options'][0];
                        $sample_data[ $key ] = is_array( $opt ) ? ( $opt['value'] ?? '' ) : $opt;
                    } else {
                        $sample_data[ $key ] = 'option_1';
                    }
                    break;
                case 'checkbox':
                    if ( ! empty( $field['options'] ) ) {
                        // Checkbox group — show first two values.
                        $vals = array();
                        foreach ( array_slice( $field['options'], 0, 2 ) as $opt ) {
                            $vals[] = is_array( $opt ) ? ( $opt['value'] ?? '' ) : $opt;
                        }
                        $sample_data[ $key ] = $vals;
                    } else {
                        $sample_data[ $key ] = '1';
                    }
                    break;
                case 'date':
                    $sample_data[ $key ] = gmdate( 'Y-m-d' );
                    break;
                case 'address':
                    $sample_data[ $key ] = '123 Main St, Springfield, IL 62701';
                    break;
                case 'file':
                    $sample_files[] = array(
                        'field_key' => $key,
                        'file_name' => 'sample-document.pdf',
                        'file_size' => 102400,
                        'mime_type' => 'application/pdf',
                    );
                    break;
                default:
                    $sample_data[ $key ] = 'Sample ' . $key;
                    break;
            }
        }

        // If no fields found, provide a generic example.
        if ( empty( $sample_data ) && empty( $sample_files ) ) {
            $sample_data = array(
                'name'    => 'Jane Doe',
                'email'   => 'jane@example.com',
                'message' => 'This is a sample submission.',
            );
        }

        $timestamp = gmdate( 'c' );

        return array(
            'event'     => 'form_submission',
            'timestamp' => $timestamp,
            'form'      => array(
                'id'    => $form_id,
                'title' => $form_title,
            ),
            'entry'     => array(
                'id'           => 0,
                'submitted_at' => $timestamp,
            ),
            'data'      => $sample_data,
            'files'     => $sample_files,
            'site'      => array(
                'name' => get_bloginfo( 'name' ),
                'url'  => home_url(),
            ),
        );
    }
}
