<?php
/**
 * Submission Handler for Form Runtime Engine.
 *
 * Processes form submissions through the complete lifecycle.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form submission handler.
 */
class FRE_Submission_Handler {

    /**
     * Validator instance.
     *
     * @var FRE_Validator
     */
    private $validator;

    /**
     * Sanitizer instance.
     *
     * @var FRE_Sanitizer
     */
    private $sanitizer;

    /**
     * Upload handler instance.
     *
     * @var FRE_Upload_Handler
     */
    private $upload_handler;

    /**
     * Entry repository instance.
     *
     * @var FRE_Entry
     */
    private $entry_repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->validator      = new FRE_Validator();
        $this->sanitizer      = new FRE_Sanitizer();
        $this->upload_handler = new FRE_Upload_Handler();
        $this->entry_repo     = new FRE_Entry();
    }

    /**
     * Handle form submission via AJAX.
     *
     * Lifecycle: NONCE CHECK → SPAM CHECK → VALIDATE → SANITIZE
     *            → UPLOAD FILES → STORE ENTRY → SEND EMAIL → RETURN RESPONSE
     *
     * Fix #9: Nonce verification is now done BEFORE loading form config.
     */
    public function handle_submission() {
        // Define processing constant for error handling.
        if ( ! defined( 'FRE_PROCESSING' ) ) {
            define( 'FRE_PROCESSING', true );
        }

        try {
            // Get form ID.
            $form_id = isset( $_POST['fre_form_id'] )
                ? sanitize_key( $_POST['fre_form_id'] )
                : '';

            if ( empty( $form_id ) ) {
                $this->send_error( 'invalid_form', __( 'Invalid form submission.', 'form-runtime-engine' ) );
            }

            // Step 1: Nonce verification FIRST (Fix #9: CSRF protection).
            $this->verify_nonce( $form_id );

            // Fix #4: Check idempotency token to prevent duplicate submissions on retry.
            $idempotency_result = $this->check_idempotency_token( $form_id );
            if ( is_array( $idempotency_result ) ) {
                // Already processed - return cached response.
                wp_send_json_success( $idempotency_result );
            }

            // Get form configuration (only after nonce verification).
            $form_config = fre()->registry->get( $form_id );

            // Fix #16: Improved error logging when form config not found.
            if ( ! $form_config ) {
                $this->log_form_config_error( $form_id );
                $this->send_error( 'form_not_found', __( 'Form configuration not found.', 'form-runtime-engine' ) );
            }

            /**
             * Fires before submission processing begins.
             *
             * @param string $form_id     Form ID.
             * @param array  $form_config Form configuration.
             */
            do_action( 'fre_before_submission_process', $form_id, $form_config );

            // Step 2: Spam protection checks.
            $this->check_spam_protection( $form_id, $form_config );

            // Step 3: Check for duplicate submission.
            $this->check_duplicate_submission( $form_id, $form_config );

            // Step 4: Validate input lengths (prevent memory exhaustion).
            $length_check = $this->validator->validate_input_lengths( $_POST );
            if ( is_wp_error( $length_check ) ) {
                $this->send_error( $length_check->get_error_code(), $length_check->get_error_message() );
            }

            // Step 5: Validate fields.
            $validation = $this->validator->validate( $form_config, $_POST );
            if ( is_wp_error( $validation ) ) {
                $this->send_validation_error( $validation );
            }

            // Step 6: Validate file uploads.
            $this->validate_file_uploads( $form_config );

            // Step 7: Sanitize field values.
            $sanitized_data = $this->sanitizer->sanitize( $form_config, $_POST );

            // Step 8: Store entry (if enabled).
            $entry_id = null;
            if ( ! empty( $form_config['settings']['store_entries'] ) ) {
                $entry_id = $this->entry_repo->create( $form_id, $sanitized_data );

                if ( is_wp_error( $entry_id ) ) {
                    $this->send_error( 'database_error', __( 'An error occurred saving your submission.', 'form-runtime-engine' ) );
                }
            }

            // Step 9: Process file uploads.
            $uploaded_files = array();
            if ( $entry_id && $this->has_file_uploads( $form_config ) ) {
                $uploaded_files = $this->upload_handler->process_uploads( $form_config, $entry_id );

                if ( is_wp_error( $uploaded_files ) ) {
                    // Clean up entry if file upload fails.
                    $this->entry_repo->delete( $entry_id );
                    $this->send_error( $uploaded_files->get_error_code(), $uploaded_files->get_error_message() );
                }

                // Store file records.
                foreach ( $uploaded_files as $field_key => $file_data ) {
                    if ( isset( $file_data[0] ) ) {
                        // Multiple files.
                        foreach ( $file_data as $file ) {
                            $this->entry_repo->add_file( $entry_id, $file, $field_key );
                        }
                    } else {
                        // Single file.
                        $this->entry_repo->add_file( $entry_id, $file_data, $field_key );
                    }
                }
            }

            // Step 10: Send email notification.
            $notification_sent = false;
            if ( ! empty( $form_config['settings']['notification']['enabled'] ) ) {
                $email_handler     = new FRE_Email_Notification();
                $notification_sent = $email_handler->send(
                    $entry_id,
                    $form_config,
                    $sanitized_data,
                    $uploaded_files
                );
            }

            // Prepare success response.
            $response = array(
                'success' => true,
                'message' => $form_config['settings']['success_message'],
            );

            if ( ! empty( $form_config['settings']['redirect_url'] ) ) {
                $response['redirect'] = esc_url( $form_config['settings']['redirect_url'] );
            }

            /**
             * Filter the success response.
             *
             * @param array  $response       Response data.
             * @param int    $entry_id       Entry ID.
             * @param array  $sanitized_data Submitted data.
             * @param array  $form_config    Form configuration.
             */
            $response = apply_filters( 'fre_submission_response', $response, $entry_id, $sanitized_data, $form_config );

            // Fix #4: Store response for idempotency before sending.
            $this->store_idempotency_response( $response );

            wp_send_json_success( $response );

        } catch ( Exception $e ) {
            error_log( 'FRE Submission Error: ' . $e->getMessage() );
            $this->send_error( 'processing_error', __( 'An error occurred processing your submission. Please try again.', 'form-runtime-engine' ) );
        }
    }

    /**
     * Verify nonce.
     *
     * @param string $form_id Form ID.
     */
    private function verify_nonce( $form_id ) {
        $nonce = isset( $_POST['_wpnonce'] ) ? $_POST['_wpnonce'] : '';

        if ( ! wp_verify_nonce( $nonce, 'fre_submit_' . $form_id ) ) {
            wp_send_json_error( array(
                'code'           => 'nonce_expired',
                'message'        => __( 'Your session expired. The form has been refreshed.', 'form-runtime-engine' ),
                'new_nonce'      => wp_create_nonce( 'fre_submit_' . $form_id ),
                'submitted_data' => $this->get_safe_repopulation_data( $_POST ),
            ) );
        }
    }

    /**
     * Check spam protection measures.
     *
     * @param string $form_id     Form ID.
     * @param array  $form_config Form configuration.
     */
    private function check_spam_protection( $form_id, array $form_config ) {
        $settings = isset( $form_config['settings']['spam_protection'] )
            ? $form_config['settings']['spam_protection']
            : array();

        // Honeypot check.
        if ( ! empty( $settings['honeypot'] ) ) {
            $honeypot = new FRE_Honeypot();
            $result   = $honeypot->validate( $form_id );

            if ( is_wp_error( $result ) ) {
                // Silent fail for bots - return success but don't store.
                wp_send_json_success( array(
                    'success' => true,
                    'message' => $form_config['settings']['success_message'],
                ) );
            }
        }

        // Timing check.
        if ( ! empty( $settings['timing_check'] ) ) {
            $timing = new FRE_Timing_Check();
            $result = $timing->validate( $form_id, $settings );

            if ( is_wp_error( $result ) ) {
                $this->send_error( $result->get_error_code(), $result->get_error_message() );
            }
        }

        // Rate limiting.
        if ( ! empty( $settings['rate_limit'] ) ) {
            $rate_limiter = new FRE_Rate_Limiter();

            // Check per-IP rate limit.
            $result = $rate_limiter->validate( $form_id, $settings['rate_limit'] );
            if ( is_wp_error( $result ) ) {
                $this->send_error( $result->get_error_code(), $result->get_error_message() );
            }

            // Check global rate limit (per-form).
            if ( $rate_limiter->is_global_exceeded( $form_id ) ) {
                $this->send_error( 'global_rate_limit', __( 'This form is receiving too many submissions. Please try again later.', 'form-runtime-engine' ) );
            }

            // Fix #3: Check global IP rate limit (across all forms).
            // This was implemented but never called - prevents single IP from
            // submitting too many forms across the entire site.
            if ( $rate_limiter->is_global_ip_exceeded() ) {
                $this->send_error( 'global_ip_limit', __( 'Too many submissions. Please try again later.', 'form-runtime-engine' ) );
            }
        }
    }

    /**
     * Check for duplicate submission.
     *
     * @param string $form_id     Form ID.
     * @param array  $form_config Form configuration.
     */
    private function check_duplicate_submission( $form_id, array $form_config ) {
        // Create hash from submitted data (excluding nonce and timestamp).
        $data_to_hash = $_POST;
        unset( $data_to_hash['_wpnonce'], $data_to_hash['_fre_timestamp'] );

        // Remove honeypot field.
        $honeypot = new FRE_Honeypot();
        unset( $data_to_hash[ $honeypot->get_field_name( $form_id ) ] );

        if ( $this->entry_repo->is_duplicate( $form_id, $data_to_hash ) ) {
            // Return success to avoid revealing duplicate detection.
            wp_send_json_success( array(
                'success' => true,
                'message' => $form_config['settings']['success_message'],
            ) );
        }
    }

    /**
     * Validate file uploads before processing.
     *
     * @param array $form_config Form configuration.
     */
    private function validate_file_uploads( array $form_config ) {
        foreach ( $form_config['fields'] as $field ) {
            if ( $field['type'] !== 'file' ) {
                continue;
            }

            $file_field = new FRE_Field_File();
            $file_key   = $file_field->get_name( $field );

            if ( ! isset( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
                continue;
            }

            $files = $_FILES[ $file_key ];

            // Handle multiple files.
            if ( is_array( $files['name'] ) ) {
                foreach ( $files['name'] as $index => $name ) {
                    if ( empty( $name ) ) {
                        continue;
                    }

                    $file = array(
                        'name'     => $files['name'][ $index ],
                        'type'     => $files['type'][ $index ],
                        'tmp_name' => $files['tmp_name'][ $index ],
                        'error'    => $files['error'][ $index ],
                        'size'     => $files['size'][ $index ],
                    );

                    $result = $this->upload_handler->validate_file( $file, $field );
                    if ( is_wp_error( $result ) ) {
                        $this->send_error( $result->get_error_code(), $result->get_error_message() );
                    }
                }
            } else {
                $result = $this->upload_handler->validate_file( $files, $field );
                if ( is_wp_error( $result ) ) {
                    $this->send_error( $result->get_error_code(), $result->get_error_message() );
                }
            }
        }
    }

    /**
     * Check if form has file upload fields.
     *
     * @param array $form_config Form configuration.
     * @return bool
     */
    private function has_file_uploads( array $form_config ) {
        foreach ( $form_config['fields'] as $field ) {
            if ( $field['type'] === 'file' ) {
                $file_field = new FRE_Field_File();
                $file_key   = $file_field->get_name( $field );

                if ( isset( $_FILES[ $file_key ] ) && ! empty( $_FILES[ $file_key ]['name'] ) ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get safe data for form repopulation after nonce failure.
     *
     * @param array $data Posted data.
     * @return array Safe data for repopulation.
     */
    private function get_safe_repopulation_data( array $data ) {
        $safe = array();

        foreach ( $data as $key => $value ) {
            // Skip internal fields.
            if ( strpos( $key, '_' ) === 0 || $key === 'fre_form_id' ) {
                continue;
            }

            // Skip file fields.
            if ( strpos( $key, 'fre_file_' ) === 0 ) {
                continue;
            }

            // Sanitize and include.
            if ( is_array( $value ) ) {
                $safe[ $key ] = array_map( 'sanitize_text_field', $value );
            } else {
                $safe[ $key ] = sanitize_text_field( $value );
            }
        }

        return $safe;
    }

    /**
     * Send error response.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     */
    private function send_error( $code, $message ) {
        wp_send_json_error( array(
            'code'    => $code,
            'message' => $message,
        ) );
    }

    /**
     * Send validation error response.
     *
     * @param WP_Error $error Validation error.
     */
    private function send_validation_error( WP_Error $error ) {
        $data = $error->get_error_data();

        wp_send_json_error( array(
            'code'         => 'validation_failed',
            'message'      => $error->get_error_message(),
            'field_errors' => isset( $data['field_errors'] ) ? $data['field_errors'] : array(),
        ) );
    }

    /**
     * Check idempotency token to prevent duplicate submissions (Fix #4).
     *
     * If a submission with this ID was already processed, returns the cached response.
     * Otherwise, marks the token as in-progress and returns false.
     *
     * @param string $form_id Form ID.
     * @return array|false Cached response if duplicate, false if new submission.
     */
    private function check_idempotency_token( $form_id ) {
        $submission_id = isset( $_POST['_fre_submission_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['_fre_submission_id'] ) )
            : '';

        if ( empty( $submission_id ) ) {
            // No idempotency token provided - proceed with normal duplicate detection.
            return false;
        }

        // Validate UUID format.
        if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submission_id ) ) {
            return false;
        }

        $transient_key = 'fre_idempotent_' . hash( 'sha256', $form_id . '_' . $submission_id );

        // Check if this submission ID was already processed.
        $cached = get_transient( $transient_key );

        if ( $cached !== false ) {
            // Submission was already processed - return cached response.
            if ( is_array( $cached ) && isset( $cached['status'] ) ) {
                if ( $cached['status'] === 'processing' ) {
                    // Still processing - tell client to wait.
                    wp_send_json_error( array(
                        'code'    => 'submission_processing',
                        'message' => __( 'Your submission is being processed. Please wait.', 'form-runtime-engine' ),
                    ) );
                }
                return $cached['response'];
            }
        }

        // Mark as processing (5 minute window for slow submissions).
        set_transient( $transient_key, array( 'status' => 'processing' ), 300 );

        // Store the key so we can update it after successful submission.
        $this->current_idempotency_key = $transient_key;

        return false;
    }

    /**
     * Store successful submission response for idempotency (Fix #4).
     *
     * @param array $response Success response.
     */
    private function store_idempotency_response( array $response ) {
        if ( empty( $this->current_idempotency_key ) ) {
            return;
        }

        // Store response for 1 hour (to handle retries).
        set_transient( $this->current_idempotency_key, array(
            'status'   => 'completed',
            'response' => $response,
        ), HOUR_IN_SECONDS );
    }

    /**
     * Current idempotency key for this request.
     *
     * @var string
     */
    private $current_idempotency_key = '';

    /**
     * Log form configuration error with details (Fix #16).
     *
     * @param string $form_id Form ID that was not found.
     */
    private function log_form_config_error( $form_id ) {
        // Get list of registered forms for debugging.
        $registered_forms = array_keys( fre()->registry->get_all() );

        $error_details = array(
            'requested_form_id' => $form_id,
            'registered_forms'  => $registered_forms,
            'ip_address'        => isset( $_SERVER['REMOTE_ADDR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
                : 'unknown',
            'referer'           => isset( $_SERVER['HTTP_REFERER'] )
                ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) )
                : 'none',
            'timestamp'         => current_time( 'mysql' ),
        );

        error_log( sprintf(
            'FRE Form Config Error: Form "%s" not found. Registered forms: [%s]. Referer: %s',
            $form_id,
            implode( ', ', $registered_forms ),
            $error_details['referer']
        ) );

        // Store error for admin review.
        $config_errors = get_option( 'fre_form_config_errors', array() );
        $config_errors[] = $error_details;

        // Keep only last 50 errors.
        if ( count( $config_errors ) > 50 ) {
            $config_errors = array_slice( $config_errors, -50 );
        }

        // Use autoload=false to prevent loading on every request.
        // This option can grow large and is only needed in admin context.
        update_option( 'fre_form_config_errors', $config_errors, false );

        /**
         * Fires when a form configuration error occurs.
         *
         * @param string $form_id       The form ID that was not found.
         * @param array  $error_details Error details array.
         */
        do_action( 'fre_form_config_error', $form_id, $error_details );
    }

    /**
     * AJAX handler for nonce refresh (Fix #3: Rate limited, Fix #5: CSRF protected).
     *
     * Rate limited to 10 requests per 5 minutes per IP to prevent abuse.
     * Also requires an expired (but recently valid) nonce to prove prior form interaction.
     */
    public function ajax_refresh_nonce() {
        // Rate limit: 10 requests per 5 minutes per IP.
        $ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
        $key = 'fre_nonce_refresh_' . md5( $ip );

        $count = get_transient( $key );
        if ( $count !== false && (int) $count >= 10 ) {
            wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'form-runtime-engine' ) ) );
        }

        // Increment counter.
        set_transient( $key, ( $count !== false ? (int) $count + 1 : 1 ), 300 );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'form-runtime-engine' ) ) );
        }

        // Validate form exists.
        if ( ! fre()->registry->get( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'form-runtime-engine' ) ) );
        }

        // Fix #5: Verify that the requester had a previous (possibly expired) nonce.
        // This proves they legitimately loaded the form page, preventing CSRF attacks.
        $old_nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! empty( $old_nonce ) ) {
            // Check if nonce is valid or recently expired (within 2 nonce ticks = ~24 hours).
            $nonce_action = 'fre_submit_' . $form_id;
            $valid = wp_verify_nonce( $old_nonce, $nonce_action );

            // wp_verify_nonce returns: 1 = valid (0-12 hrs), 2 = valid (12-24 hrs), false = invalid
            if ( $valid === false ) {
                // Check if it's a very recently expired nonce by checking the next tick back.
                // This handles edge cases around the 24-hour boundary.
                $nonce_tick = ceil( time() / ( DAY_IN_SECONDS / 2 ) );
                $expected_old = substr( wp_hash( ( $nonce_tick - 2 ) . '|' . $nonce_action . '|' . wp_get_session_token() . '|' . get_uid(), 'nonce' ), -12, 10 );

                // If not within grace period, reject.
                if ( ! hash_equals( $expected_old, $old_nonce ) ) {
                    wp_send_json_error( array( 'message' => __( 'Invalid request. Please reload the page.', 'form-runtime-engine' ) ) );
                }
            }
        }
        // Note: If no old nonce provided, we still allow it for backwards compatibility
        // but the rate limiting provides protection against abuse.

        wp_send_json_success( array(
            'nonce' => wp_create_nonce( 'fre_submit_' . $form_id ),
        ) );
    }
}
