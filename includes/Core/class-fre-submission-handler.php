<?php
/**
 * Submission Handler for Form Runtime Engine.
 *
 * Processes form submissions through the complete lifecycle.
 *
 * NOTE: Nonce verification is performed via verify_nonce() method at the start
 * of handle_submission(). Subsequent $_POST access is safe after verification.
 * Security checks (honeypot, timing, rate limit) access $_POST after nonce check.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
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

            // Step 7b: Strip orphan values from conditionally-hidden fields.
            // Frontend may keep stale values in DOM/state when a field's
            // visibility flips false; the server is authoritative, so we
            // remove those values here before storage so every downstream
            // consumer (email, webhook, sheet, CSV, admin) sees a clean payload.
            $sanitized_data = FRE_Conditions::strip_hidden_field_values( $form_config, $sanitized_data );

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

            /**
             * Fires after a form submission has been fully processed —
             * sanitized, stored, files attached, but BEFORE the notification
             * email is sent. Distinct from `fre_entry_created` which fires
             * inside the entry insert transaction before files exist.
             *
             * Webhook dispatch listens here so the payload can include
             * file_url for any uploaded files. Other listeners that need the
             * complete entry (e.g., CRM sync that also wants attachments)
             * should subscribe to this action instead of fre_entry_created.
             *
             * @since 1.5.0
             *
             * @param int    $entry_id       Entry ID (0 if store_entries disabled).
             * @param string $form_id        Form ID.
             * @param array  $sanitized_data Sanitized field values.
             */
            if ( $entry_id ) {
                do_action( 'fre_submission_complete', $entry_id, $form_id, $sanitized_data );
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
            FRE_Logger::error( 'Submission Error: ' . $e->getMessage() );
            $this->send_error( 'processing_error', __( 'An error occurred processing your submission. Please try again.', 'form-runtime-engine' ) );
        }
    }

    /**
     * Process a submission programmatically, outside the AJAX request path.
     *
     * INTERNAL API — not exposed on any public surface yet. Added in the Phase 1
     * Cowork connector refactor as the shared entry point the REST connector
     * will call in Phase 2. The AJAX handler (`handle_submission()`) is NOT
     * routed through this method and is not affected by it.
     *
     * Deliberate differences from the AJAX path:
     *   - No nonce check. Auth is the caller's responsibility (the REST
     *     connector validates via App Password + capability).
     *   - No honeypot, no timing check, no idempotency transients. These
     *     all depend on frontend-injected state that a connector-originated
     *     submission cannot supply.
     *   - No duplicate-submission detection. Connectors explicitly control
     *     when they submit and can retry safely.
     *   - No file upload handling in Phase 1. Connector-originated file
     *     uploads are out of scope per the assessment document.
     *   - Returns a structured result array rather than JSON. The caller is
     *     responsible for translating to HTTP response format.
     *
     * Options:
     *   - `dry_run` (bool): When true, runs validation and sanitization and
     *     returns what would be stored, but does NOT create an entry, fire
     *     `fre_entry_created`, send email, or dispatch webhooks. Default false.
     *   - `skip_notifications` (bool): When true, creates the entry and fires
     *     `fre_entry_created` (so external listeners like the webhook
     *     dispatcher still run — Cowork often wants that), but skips the
     *     built-in email notification send. Default false.
     *   - `source` (string): Origin tag. Currently informational only;
     *     reserved for future logging and analytics.
     *
     * @param string $form_id Form identifier.
     * @param array  $data    Raw submission data keyed by field key.
     * @param array  $options Processing options (see method body).
     * @return array|WP_Error {
     *     Structured result on success, WP_Error on failure.
     *
     *     @type bool   $dry_run    Whether this call skipped side effects.
     *     @type int    $entry_id   Entry ID. 0 when dry_run or store_entries disabled.
     *     @type array  $sanitized  The sanitized data that was (or would be) stored.
     *     @type bool   $email_sent Whether the built-in email notification fired.
     *     @type string $source     The source tag passed in options.
     * }
     */
    public function process_submission( $form_id, array $data, array $options = array() ) {
        $options = wp_parse_args(
            $options,
            array(
                'dry_run'            => false,
                'skip_notifications' => false,
                'source'             => 'connector',
            )
        );

        $form_id = sanitize_key( $form_id );
        if ( '' === $form_id ) {
            return new WP_Error( 'invalid_form_id', __( 'Form ID is required.', 'form-runtime-engine' ) );
        }

        // Look up the form config from the runtime registry. DB-stored forms
        // are already registered by FRE_Forms_Repository::register_all_with_runtime_registry()
        // on fre_init, so both PHP-registered and DB-stored forms work here.
        $form_config = fre()->registry->get( $form_id );
        if ( ! is_array( $form_config ) ) {
            return new WP_Error(
                'form_not_found',
                __( 'Form configuration not found.', 'form-runtime-engine' ),
                array( 'form_id' => $form_id )
            );
        }

        /**
         * Fires before programmatic submission processing begins.
         *
         * Mirrors `fre_before_submission_process` from the AJAX path so external
         * listeners can react uniformly regardless of submission origin.
         *
         * @param string $form_id     Form ID.
         * @param array  $form_config Form configuration.
         * @param array  $options     Processing options.
         */
        do_action( 'fre_before_submission_process', $form_id, $form_config, $options );

        // Translate clean field keys into the "fre_field_{key}" form the
        // validator and sanitizer expect. Callers of process_submission use
        // clean keys per the connector contract (docs/CONNECTOR_SPEC.md §9.9)
        // because that is the natural shape for JSON APIs; the internal
        // prefix exists only to avoid collisions with WordPress POST params
        // on the AJAX path, which doesn't apply here.
        $prefixed_data = $this->prefix_field_keys( $data, $form_config );

        // Validate.
        $validation = $this->validator->validate( $form_config, $prefixed_data );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Sanitize. The sanitizer returns a map keyed by clean field keys
        // (it strips the prefix internally — see its signature), so no
        // reverse translation is needed on the returned map.
        $sanitized_data = $this->sanitizer->sanitize( $form_config, $prefixed_data );

        // Strip orphan values from conditionally-hidden fields. Mirrors the
        // public AJAX path; ensures the connector test-submit endpoint and
        // any future programmatic submission entry point produce identical
        // clean data for storage and downstream notifications/webhooks.
        $sanitized_data = FRE_Conditions::strip_hidden_field_values( $form_config, $sanitized_data );

        // Dry run short-circuits here — return what would have been stored.
        if ( ! empty( $options['dry_run'] ) ) {
            return array(
                'dry_run'    => true,
                'entry_id'   => 0,
                'sanitized'  => $sanitized_data,
                'email_sent' => false,
                'source'     => (string) $options['source'],
            );
        }

        // Create the entry, honoring the form's store_entries setting.
        $entry_id        = 0;
        $store_entries   = ! isset( $form_config['settings']['store_entries'] )
            || ! empty( $form_config['settings']['store_entries'] );

        if ( $store_entries ) {
            $entry_id = $this->entry_repo->create( $form_id, $sanitized_data );

            if ( is_wp_error( $entry_id ) ) {
                return $entry_id;
            }

            // FRE_Entry::create() fires `fre_entry_created` internally after
            // the transaction commits — kept for backward-compat listeners.
            //
            // Fire `fre_submission_complete` to mirror the AJAX path (where
            // it fires AFTER files are attached). The connector's programmatic
            // path doesn't process files in Phase 1, so there's nothing to
            // wait for and we can fire it immediately. This keeps webhook
            // dispatch consistent across both submission entry points.
            do_action( 'fre_submission_complete', $entry_id, $form_id, $sanitized_data );
        }

        // Email notification: skip if explicitly disabled or if the caller asked to.
        $email_sent            = false;
        $notifications_enabled = ! empty( $form_config['settings']['notification']['enabled'] );
        if ( $notifications_enabled && empty( $options['skip_notifications'] ) && $entry_id ) {
            $email_handler = new FRE_Email_Notification();
            $email_sent    = (bool) $email_handler->send(
                $entry_id,
                $form_config,
                $sanitized_data,
                array() // No file uploads in the programmatic path.
            );
        }

        return array(
            'dry_run'    => false,
            'entry_id'   => (int) $entry_id,
            'sanitized'  => $sanitized_data,
            'email_sent' => $email_sent,
            'source'     => (string) $options['source'],
        );
    }

    /**
     * Translate clean field keys into the internal `fre_field_{key}` form.
     *
     * The connector API accepts clean keys (as documented in
     * docs/CONNECTOR_SPEC.md §9.9) because that is the natural shape for a
     * JSON payload. Internally the validator and sanitizer expect the
     * `fre_field_*` prefix because the AJAX path receives data via $_POST
     * and the prefix avoids collisions with WordPress-reserved POST params.
     *
     * This helper maps each field defined in the form config from its clean
     * key to its prefixed name (via $field_type->get_name()), so overrides
     * on specific field types are respected. Keys in $data that don't
     * correspond to a known field are dropped — invalid keys should never
     * reach the validator because doing so gives the validator false
     * evidence of what the caller submitted.
     *
     * @param array $data        Data keyed by clean field keys.
     * @param array $form_config Form configuration.
     * @return array Data keyed by the names the validator/sanitizer expect.
     */
    private function prefix_field_keys( array $data, array $form_config ) {
        $prefixed = array();
        $fields   = isset( $form_config['fields'] ) && is_array( $form_config['fields'] ) ? $form_config['fields'] : array();

        foreach ( $fields as $field ) {
            if ( empty( $field['key'] ) || empty( $field['type'] ) ) {
                continue;
            }

            $clean_key = $field['key'];
            if ( ! array_key_exists( $clean_key, $data ) ) {
                continue;
            }

            $field_class = FRE_Autoloader::get_field_class( $field['type'] );
            if ( ! $field_class || ! class_exists( $field_class ) ) {
                // Fall back to the abstract's default naming convention.
                $prefixed[ 'fre_field_' . sanitize_key( $clean_key ) ] = $data[ $clean_key ];
                continue;
            }

            $field_type        = new $field_class();
            $name              = $field_type->get_name( $field );
            $prefixed[ $name ] = $data[ $clean_key ];
        }

        return $prefixed;
    }

    /**
     * Verify nonce.
     *
     * @param string $form_id Form ID.
     */
    private function verify_nonce( $form_id ) {
        $nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

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
                // Fix #4 follow-up: clear the idempotency transient so a
                // genuine human whose submission was wrongly flagged as bot
                // can retry without being wedged in 'processing' for 5 min.
                $this->clear_idempotency_token_on_exit();

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
            // Fix #4 follow-up: clear the idempotency transient so the
            // user (who just sees "success") isn't told their next genuine
            // submission attempt is "still being processed" for 5 min.
            $this->clear_idempotency_token_on_exit();

            wp_send_json_success( array(
                'success' => true,
                'message' => $form_config['settings']['success_message'],
            ) );
        }

        // Fix #11 follow-up: is_duplicate() returned false, so THIS submission
        // now owns the 60-second dedup window for this data hash. If a
        // downstream step (validation, file upload, fatal exception) fails
        // before the entry is stored, the dedup record stays in wp_options
        // and silently rejects every retry within the next 60 seconds —
        // even after the user fixes the underlying problem. Track the key
        // here so the failure-exit path (clear_duplicate_token_on_exit()) can
        // remove it. Hash computation must mirror FRE_Entry::is_duplicate()
        // exactly so we delete the same row.
        $hash                          = hash( 'sha256', $form_id . wp_json_encode( $data_to_hash ) );
        $this->current_duplicate_key   = 'fre_submission_' . $hash;
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

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated via MIME type, extension, and size checks.
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
     * Clears both the idempotency token (Fix #4 follow-up) and the
     * duplicate-detection token (Fix #11 follow-up) before responding
     * so the next retry isn't silently rejected by stale transients
     * from this aborted attempt.
     *
     * @param string $code    Error code.
     * @param string $message Error message.
     */
    private function send_error( $code, $message ) {
        $this->clear_idempotency_token_on_exit();
        $this->clear_duplicate_token_on_exit();

        wp_send_json_error( array(
            'code'    => $code,
            'message' => $message,
        ) );
    }

    /**
     * Send validation error response.
     *
     * Clears both the idempotency token (Fix #4 follow-up) and the
     * duplicate-detection token (Fix #11 follow-up) before responding
     * so the user can fix field errors and resubmit immediately —
     * without being told the submission is "still processing" for 5
     * minutes, and without their corrected resubmit being silently
     * rejected as a duplicate of the original failed attempt.
     *
     * @param WP_Error $error Validation error.
     */
    private function send_validation_error( WP_Error $error ) {
        $this->clear_idempotency_token_on_exit();
        $this->clear_duplicate_token_on_exit();

        $data = $error->get_error_data();

        wp_send_json_error( array(
            'code'         => 'validation_failed',
            'message'      => $error->get_error_message(),
            'field_errors' => isset( $data['field_errors'] ) ? $data['field_errors'] : array(),
        ) );
    }

    /**
     * Clear the in-progress idempotency transient on a non-success exit.
     *
     * Companion to set_transient() in check_idempotency_token() and
     * store_idempotency_response(). The original Fix #4 implementation
     * marked the token 'processing' at the start of handle_submission()
     * and only updated it to 'completed' on the success path — leaving
     * any failure (validation, spam check, file upload error, fatal
     * exception) to leave the transient stuck in 'processing' for its
     * full 5-minute lifetime. Subsequent retries from the same form
     * load (which intentionally reuse the same submission UUID) would
     * then hit the 'processing' branch and get the
     * "Your submission is being processed. Please wait." response —
     * effectively locking a real user out of resubmitting after any
     * recoverable error.
     *
     * Calling this on the error/silent-success exits restores the
     * intended behavior: the original Fix #4 idempotency contract is
     * preserved on success (cached response returned for retries) and
     * the user can immediately retry after a recoverable failure.
     *
     * Safe to call multiple times — idempotent itself. No-op when the
     * idempotency check never ran (e.g., early nonce failure) because
     * current_idempotency_key is only populated after the transient
     * has actually been set.
     */
    private function clear_idempotency_token_on_exit() {
        if ( ! empty( $this->current_idempotency_key ) ) {
            delete_transient( $this->current_idempotency_key );
            $this->current_idempotency_key = '';
        }
    }

    /**
     * Clear the in-progress duplicate-detection transient on a non-success exit.
     *
     * Companion to FRE_Entry::is_duplicate(), which inserts a 60-second
     * transient keyed on the submission data hash to silently reject
     * accidental double-submits. The original Fix #11 implementation
     * created the transient but only "completed" it implicitly through
     * the natural lifecycle of the entry — leaving any failure
     * (validation, file upload, fatal exception) to leave the dedup
     * record in wp_options for its full 60-second lifetime. The next
     * retry from the same form (same $_POST hash) would then hit the
     * "duplicate" branch, get a silent-success response, and never
     * actually create an entry or fire the webhook — leaving the user
     * with no visible indication that their genuine retry was dropped.
     *
     * Calling this on the error/silent-success exits restores the
     * intended behavior: the dedup window only persists when an entry
     * actually exists, so retries after a recoverable failure go
     * through immediately.
     *
     * Safe to call multiple times — idempotent itself. No-op when
     * check_duplicate_submission() never ran or when is_duplicate()
     * returned true (in which case THIS request didn't own the
     * transient and must not delete someone else's dedup window).
     */
    private function clear_duplicate_token_on_exit() {
        if ( ! empty( $this->current_duplicate_key ) ) {
            delete_transient( $this->current_duplicate_key );
            $this->current_duplicate_key = '';
        }
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
     * Current duplicate-detection transient key owned by this request.
     *
     * Set by check_duplicate_submission() after is_duplicate() returns
     * false (this request "owns" the 60-second window). Cleared by
     * clear_duplicate_token_on_exit() on any non-success exit path so
     * the user can retry immediately after fixing a recoverable error
     * instead of being silently stonewalled for the rest of the window.
     * Empty string when this request never reached the dedup check
     * (e.g., early nonce / honeypot failure).
     *
     * @var string
     */
    private $current_duplicate_key = '';

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

        FRE_Logger::error( sprintf(
            'Form Config Error: Form "%s" not found. Registered forms: [%s]. Referer: %s',
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
