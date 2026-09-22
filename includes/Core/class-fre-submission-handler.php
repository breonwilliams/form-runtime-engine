<?php
/**
 * Submission Handler for Promptless Forms.
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
class PForms_Submission_Handler {

    /**
     * Validator instance.
     *
     * @var PForms_Validator
     */
    private $validator;

    /**
     * Sanitizer instance.
     *
     * @var PForms_Sanitizer
     */
    private $sanitizer;

    /**
     * Upload handler instance.
     *
     * @var PForms_Upload_Handler
     */
    private $upload_handler;

    /**
     * Entry repository instance.
     *
     * @var PForms_Entry
     */
    private $entry_repo;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->validator      = new PForms_Validator();
        $this->sanitizer      = new PForms_Sanitizer();
        $this->upload_handler = new PForms_Upload_Handler();
        $this->entry_repo     = new PForms_Entry();
        $this->lock           = new PForms_Submission_Lock();
    }

    /**
     * Submission lock (idempotency + duplicate guards).
     *
     * @var PForms_Submission_Lock
     */
    private $lock;

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
        if ( ! defined( 'PForms_PROCESSING' ) ) {
            define( 'PForms_PROCESSING', true );
        }

        try {
            // Get form ID.
            $form_id = isset( $_POST['pforms_form_id'] )
                ? sanitize_key( $_POST['pforms_form_id'] )
                : '';

            if ( empty( $form_id ) ) {
                $this->send_error( 'invalid_form', __( 'Invalid form submission.', 'promptless-forms' ) );
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
            $form_config = pforms()->registry->get( $form_id );

            // Fix #16: Improved error logging when form config not found.
            if ( ! $form_config ) {
                $this->log_form_config_error( $form_id );
                $this->send_error( 'form_not_found', __( 'Form configuration not found.', 'promptless-forms' ) );
            }

            /**
             * Fires before submission processing begins.
             *
             * @param string $form_id     Form ID.
             * @param array  $form_config Form configuration.
             */
            do_action( 'pforms_before_submission_process', $form_id, $form_config );

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
            //
            // Only for file fields the visitor can see. A file chosen and then
            // hidden by a condition (e.g. "Design ready?" switched to "No, I
            // need help") used to be validated and stored anyway: a refused
            // file the visitor could no longer see or remove blocked the whole
            // form. The server is authoritative on visibility, as it is for
            // every other field (see PForms_Conditions).
            $upload_config = $this->without_hidden_file_fields( $form_config );
            $this->validate_file_uploads( $upload_config );

            // Step 7: Sanitize field values.
            $sanitized_data = $this->sanitizer->sanitize( $form_config, $_POST );

            // Step 7b: Strip orphan values from conditionally-hidden fields.
            // Frontend may keep stale values in DOM/state when a field's
            // visibility flips false; the server is authoritative, so we
            // remove those values here before storage so every downstream
            // consumer (email, webhook, sheet, CSV, admin) sees a clean payload.
            $sanitized_data = PForms_Conditions::strip_hidden_field_values( $form_config, $sanitized_data );

            // Step 8: Store entry (if enabled).
            $entry_id = null;
            if ( ! empty( $form_config['settings']['store_entries'] ) ) {
                $entry_id = $this->entry_repo->create( $form_id, $sanitized_data );

                if ( is_wp_error( $entry_id ) ) {
                    $this->send_error( 'database_error', __( 'An error occurred saving your submission.', 'promptless-forms' ) );
                }
            }

            // Step 9: Process file uploads.
            $uploaded_files = array();
            if ( $entry_id && $this->has_file_uploads( $upload_config ) ) {
                $uploaded_files = $this->upload_handler->process_uploads( $upload_config, $entry_id );

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

            // The submission is now durably received: the entry and its files
            // are stored. From here a repeat of the same content may truthfully
            // be told "we have your request".
            if ( $entry_id ) {
                $this->mark_duplicate_completed( $entry_id );
            }

            /**
             * Fires after a form submission has been fully processed —
             * sanitized, stored, files attached, but BEFORE the notification
             * email is sent. Distinct from `pforms_entry_created` which fires
             * inside the entry insert transaction before files exist.
             *
             * Webhook dispatch listens here so the payload can include
             * file_url for any uploaded files. Other listeners that need the
             * complete entry (e.g., CRM sync that also wants attachments)
             * should subscribe to this action instead of pforms_entry_created.
             *
             * @since 1.5.0
             *
             * @param int    $entry_id       Entry ID (0 if store_entries disabled).
             * @param string $form_id        Form ID.
             * @param array  $sanitized_data Sanitized field values.
             */
            if ( $entry_id ) {
                do_action( 'pforms_submission_complete', $entry_id, $form_id, $sanitized_data );
            }

            // Step 10: Send email notification.
            $notification_sent = false;
            if ( ! empty( $form_config['settings']['notification']['enabled'] ) ) {
                $email_handler     = new PForms_Email_Notification();
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
            $response = apply_filters( 'pforms_submission_response', $response, $entry_id, $sanitized_data, $form_config );

            // With entry storage off there was no entry to mark above; reaching
            // this point is the equivalent milestone.
            $this->mark_duplicate_completed( (int) $entry_id );

            // Fix #4: Store response for idempotency before sending.
            $this->store_idempotency_response( $response );

            wp_send_json_success( $response );

        } catch ( Exception $e ) {
            PForms_Logger::error( 'Submission Error: ' . $e->getMessage() );
            $this->send_error( 'processing_error', __( 'An error occurred processing your submission. Please try again.', 'promptless-forms' ) );
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
     *     `pforms_entry_created`, send email, or dispatch webhooks. Default false.
     *   - `skip_notifications` (bool): When true, creates the entry and fires
     *     `pforms_entry_created` (so external listeners like the webhook
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
            return new WP_Error( 'invalid_form_id', __( 'Form ID is required.', 'promptless-forms' ) );
        }

        // Look up the form config from the runtime registry. DB-stored forms
        // are already registered by PForms_Forms_Repository::register_all_with_runtime_registry()
        // on pforms_init, so both PHP-registered and DB-stored forms work here.
        $form_config = pforms()->registry->get( $form_id );
        if ( ! is_array( $form_config ) ) {
            return new WP_Error(
                'form_not_found',
                __( 'Form configuration not found.', 'promptless-forms' ),
                array( 'form_id' => $form_id )
            );
        }

        /**
         * Fires before programmatic submission processing begins.
         *
         * Mirrors `pforms_before_submission_process` from the AJAX path so external
         * listeners can react uniformly regardless of submission origin.
         *
         * @param string $form_id     Form ID.
         * @param array  $form_config Form configuration.
         * @param array  $options     Processing options.
         */
        do_action( 'pforms_before_submission_process', $form_id, $form_config, $options );

        // Translate clean field keys into the "pforms_field_{key}" form the
        // validator and sanitizer expect. Callers of process_submission use
        // clean keys per the connector contract (docs/CONNECTOR_SPEC.md §9.9)
        // because that is the natural shape for JSON APIs; the internal
        // prefix exists only to avoid collisions with WordPress POST params
        // on the AJAX path, which doesn't apply here.
        $prefixed_data = $this->prefix_field_keys( $data, $form_config );

        // Validate. No file-presence check: a programmatic submission (the
        // connector's test submit, an integration) has no uploads to carry.
        $validation = $this->validator->validate( $form_config, $prefixed_data, array( 'files' => false ) );
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
        $sanitized_data = PForms_Conditions::strip_hidden_field_values( $form_config, $sanitized_data );

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

            // PForms_Entry::create() fires `pforms_entry_created` internally after
            // the transaction commits — kept for backward-compat listeners.
            //
            // Fire `pforms_submission_complete` to mirror the AJAX path (where
            // it fires AFTER files are attached). The connector's programmatic
            // path doesn't process files in Phase 1, so there's nothing to
            // wait for and we can fire it immediately. This keeps webhook
            // dispatch consistent across both submission entry points.
            do_action( 'pforms_submission_complete', $entry_id, $form_id, $sanitized_data );
        }

        // Email notification: skip if explicitly disabled or if the caller asked to.
        $email_sent            = false;
        $notifications_enabled = ! empty( $form_config['settings']['notification']['enabled'] );
        if ( $notifications_enabled && empty( $options['skip_notifications'] ) && $entry_id ) {
            $email_handler = new PForms_Email_Notification();
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
     * Translate clean field keys into the internal `pforms_field_{key}` form.
     *
     * The connector API accepts clean keys (as documented in
     * docs/CONNECTOR_SPEC.md §9.9) because that is the natural shape for a
     * JSON payload. Internally the validator and sanitizer expect the
     * `pforms_field_*` prefix because the AJAX path receives data via $_POST
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

            $field_class = PForms_Autoloader::get_field_class( $field['type'] );
            if ( ! $field_class || ! class_exists( $field_class ) ) {
                // Fall back to the abstract's default naming convention.
                $prefixed[ 'pforms_field_' . sanitize_key( $clean_key ) ] = $data[ $clean_key ];
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

        if ( ! wp_verify_nonce( $nonce, 'pforms_submit_' . $form_id ) ) {
            wp_send_json_error( array(
                'code'           => 'nonce_expired',
                'message'        => __( 'Your session expired. The form has been refreshed.', 'promptless-forms' ),
                'new_nonce'      => wp_create_nonce( 'pforms_submit_' . $form_id ),
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
            $honeypot = new PForms_Honeypot();
            $result   = $honeypot->validate( $form_id );

            if ( is_wp_error( $result ) ) {
                // Silent fail for bots - return success but don't store.
                // Fix #4 follow-up: release the idempotency claim so a
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
            $timing = new PForms_Timing_Check();
            $result = $timing->validate( $form_id, $settings );

            if ( is_wp_error( $result ) ) {
                $this->send_error( $result->get_error_code(), $result->get_error_message() );
            }
        }

        // Rate limiting.
        if ( ! empty( $settings['rate_limit'] ) ) {
            $rate_limiter = new PForms_Rate_Limiter();

            // Check per-IP rate limit.
            $result = $rate_limiter->validate( $form_id, $settings['rate_limit'] );
            if ( is_wp_error( $result ) ) {
                $this->send_error( $result->get_error_code(), $result->get_error_message() );
            }

            // Check global rate limit (per-form).
            if ( $rate_limiter->is_global_exceeded( $form_id ) ) {
                $this->send_error( 'global_rate_limit', __( 'This form is receiving too many submissions. Please try again later.', 'promptless-forms' ) );
            }

            // Fix #3: Check global IP rate limit (across all forms).
            // This was implemented but never called - prevents single IP from
            // submitting too many forms across the entire site.
            if ( $rate_limiter->is_global_ip_exceeded() ) {
                $this->send_error( 'global_ip_limit', __( 'Too many submissions. Please try again later.', 'promptless-forms' ) );
            }
        }
    }

    /**
     * Check for duplicate submission.
     *
     * The same content sent twice within a minute (a reload and resubmit, a
     * second device) is caught here. Since 1.11.0 the answer depends on what
     * actually happened to the first copy:
     *
     *   - first copy RECEIVED  → the success message, truthfully: it arrived.
     *   - first copy still being processed, or failed without releasing its
     *     claim (a PHP fatal) → a visible "still processing" error. Never the
     *     success message: nothing may have been saved.
     *
     * Before 1.11.0 every match got the success message, and on sites with a
     * persistent object cache the claim of a FAILED attempt was never released
     * (see PForms_Submission_Lock), so a retry after an error was told
     * "Thanks" while nothing was saved.
     *
     * @param string $form_id     Form ID.
     * @param array  $form_config Form configuration.
     */
    private function check_duplicate_submission( $form_id, array $form_config ) {
        // Hash the submitted data (excluding nonce and timestamp). Unchanged
        // from 1.10.x so claims written across an upgrade are recognised.
        $data_to_hash = $_POST;
        unset( $data_to_hash['_wpnonce'], $data_to_hash['_pforms_timestamp'] );

        // Remove honeypot field.
        $honeypot = new PForms_Honeypot();
        unset( $data_to_hash[ $honeypot->get_field_name( $form_id ) ] );

        $key   = PForms_Submission_Lock::duplicate_key( $form_id, $data_to_hash );
        $claim = $this->lock->claim( $key, self::DUPLICATE_WINDOW );

        if ( true === $claim ) {
            // This request owns the window. Every non-success exit releases it
            // (send_error / send_validation_error), so a retry after a
            // recoverable error goes straight through.
            $this->current_duplicate_key = $key;
            return;
        }

        if ( PForms_Submission_Lock::STATE_COMPLETED === $claim['state'] ) {
            PForms_Logger::info( sprintf( 'Duplicate submission for form "%s" matched a received submission; not stored again.', $form_id ) );

            $response = array(
                'success' => true,
                'message' => $form_config['settings']['success_message'],
            );
            if ( ! empty( $form_config['settings']['redirect_url'] ) ) {
                $response['redirect'] = esc_url( $form_config['settings']['redirect_url'] );
            }

            // A retry of THIS attempt should get the same answer.
            $this->store_idempotency_response( $response );

            wp_send_json_success( $response );
        }

        $this->send_error( 'submission_processing', self::processing_message() );
    }

    /**
     * Record that the claimed duplicate window now belongs to a received
     * submission. Called once the entry and its files are stored.
     *
     * @param int $entry_id Entry ID (0 when entry storage is off).
     */
    private function mark_duplicate_completed( $entry_id ) {
        if ( empty( $this->current_duplicate_key ) || $this->duplicate_marked ) {
            return;
        }

        $this->lock->update( $this->current_duplicate_key, array(
            'state'    => PForms_Submission_Lock::STATE_COMPLETED,
            'entry_id' => (int) $entry_id,
        ) );
        $this->duplicate_marked = true;
    }

    /**
     * The form config with conditionally hidden file fields removed.
     *
     * Used for every upload step so a file attached to a field the visitor
     * has hidden is neither validated nor stored.
     *
     * @param array $form_config Form configuration.
     * @return array
     */
    private function without_hidden_file_fields( array $form_config ) {
        if ( empty( $form_config['fields'] ) || ! is_array( $form_config['fields'] ) ) {
            return $form_config;
        }

        $form_config['fields'] = array_values( array_filter(
            $form_config['fields'],
            function ( $field ) use ( $form_config ) {
                if ( ! isset( $field['type'] ) || 'file' !== $field['type'] ) {
                    return true;
                }
                return PForms_Conditions::field_is_visible( $field, $form_config, $_POST );
            }
        ) );

        return $form_config;
    }

    /**
     * The message shown when a submission is still being processed.
     *
     * @return string
     */
    private static function processing_message() {
        return __( 'Your first attempt is still being processed. Please wait a few seconds, then try again.', 'promptless-forms' );
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

            $file_field = new PForms_Field_File();
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
                $file_field = new PForms_Field_File();
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
            if ( strpos( $key, '_' ) === 0 || $key === 'pforms_form_id' ) {
                continue;
            }

            // Skip file fields.
            if ( strpos( $key, 'pforms_file_' ) === 0 ) {
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
     * Release this request's idempotency claim on a non-success exit.
     *
     * The claim is marked 'processing' at the start of handle_submission()
     * and completed only on success. Releasing it on every failure exit
     * lets a retry of the same attempt (the browser deliberately reuses the
     * submission UUID) go straight through instead of being told the
     * submission is still being processed.
     *
     * Safe to call more than once. A no-op when this request never claimed
     * the key (early nonce failure, or the claim belonged to another request).
     */
    private function clear_idempotency_token_on_exit() {
        if ( ! empty( $this->current_idempotency_key ) ) {
            $this->lock->release( $this->current_idempotency_key );
            $this->current_idempotency_key = '';
        }
    }

    /**
     * Release this request's duplicate-window claim on a non-success exit.
     *
     * The window must only persist for content that actually arrived.
     * While a claim from a failed attempt survived, a corrected retry within
     * the minute was treated as a duplicate — and until 1.11.0 answered with
     * the success message while nothing was stored.
     *
     * Safe to call more than once. A no-op when this request does not own
     * the claim: another request's window must never be deleted from here.
     */
    private function clear_duplicate_token_on_exit() {
        // Released through the lock (SQL), NOT delete_transient(): the claim
        // was written with SQL, and on a site with a persistent object cache
        // delete_transient() never reaches it. That mismatch is the 1.10.x
        // "Thanks, but nothing saved" defect.
        //
        // Once the entry is stored the window is kept even on a later error
        // (a notification step that throws, say): the submission DID arrive,
        // so a retry is answered truthfully instead of being stored twice.
        if ( ! empty( $this->current_duplicate_key ) && ! $this->duplicate_marked ) {
            $this->lock->release( $this->current_duplicate_key );
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
        $submission_id = isset( $_POST['_pforms_submission_id'] )
            ? sanitize_text_field( wp_unslash( $_POST['_pforms_submission_id'] ) )
            : '';

        if ( empty( $submission_id ) ) {
            // No idempotency token provided - proceed with normal duplicate detection.
            return false;
        }

        // Validate UUID format.
        if ( ! preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $submission_id ) ) {
            return false;
        }

        $key   = PForms_Submission_Lock::idempotency_key( $form_id, $submission_id );
        $claim = $this->lock->claim( $key, self::IDEMPOTENCY_PROCESSING_WINDOW );

        if ( true === $claim ) {
            // Store the key so we can complete or release it on exit.
            $this->current_idempotency_key = $key;
            return false;
        }

        if ( PForms_Submission_Lock::STATE_COMPLETED === $claim['state'] && is_array( $claim['response'] ) ) {
            // Already received - return the stored response.
            return $claim['response'];
        }

        // Still processing - tell the client to wait. Not ours to release.
        wp_send_json_error( array(
            'code'    => 'submission_processing',
            'message' => self::processing_message(),
        ) );
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

        // Kept for an hour so a retry of this attempt gets the same answer.
        $this->lock->update( $this->current_idempotency_key, array(
            'state'    => PForms_Submission_Lock::STATE_COMPLETED,
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
     * Duplicate-window key this request claimed, or '' when it owns none.
     *
     * Set by check_duplicate_submission() when the claim succeeds. Cleared by
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
     * Whether this request has already marked its duplicate claim completed.
     *
     * @var bool
     */
    private $duplicate_marked = false;

    /**
     * Seconds the same content is treated as a duplicate.
     */
    const DUPLICATE_WINDOW = 60;

    /**
     * Seconds a claimed submission id stays "processing" before another
     * request may take it over (covers a request that died mid-way).
     */
    const IDEMPOTENCY_PROCESSING_WINDOW = 300;

    /**
     * Log form configuration error with details (Fix #16).
     *
     * @param string $form_id Form ID that was not found.
     */
    private function log_form_config_error( $form_id ) {
        // Get list of registered forms for debugging.
        $registered_forms = array_keys( pforms()->registry->get_all() );

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

        PForms_Logger::error( sprintf(
            'Form Config Error: Form "%s" not found. Registered forms: [%s]. Referer: %s',
            $form_id,
            implode( ', ', $registered_forms ),
            $error_details['referer']
        ) );

        // Store error for admin review.
        $config_errors = get_option( 'pforms_form_config_errors', array() );
        $config_errors[] = $error_details;

        // Keep only last 50 errors.
        if ( count( $config_errors ) > 50 ) {
            $config_errors = array_slice( $config_errors, -50 );
        }

        // Use autoload=false to prevent loading on every request.
        // This option can grow large and is only needed in admin context.
        update_option( 'pforms_form_config_errors', $config_errors, false );

        /**
         * Fires when a form configuration error occurs.
         *
         * @param string $form_id       The form ID that was not found.
         * @param array  $error_details Error details array.
         */
        do_action( 'pforms_form_config_error', $form_id, $error_details );
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
        $key = 'pforms_nonce_refresh_' . md5( $ip );

        $count = get_transient( $key );
        if ( $count !== false && (int) $count >= 10 ) {
            wp_send_json_error( array( 'message' => __( 'Too many requests. Please try again later.', 'promptless-forms' ) ) );
        }

        // Increment counter.
        set_transient( $key, ( $count !== false ? (int) $count + 1 : 1 ), 300 );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'promptless-forms' ) ) );
        }

        // Validate form exists.
        if ( ! pforms()->registry->get( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Invalid form ID.', 'promptless-forms' ) ) );
        }

        // Fix #5: Verify that the requester had a previous (possibly expired) nonce.
        // This proves they legitimately loaded the form page, preventing CSRF attacks.
        $old_nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

        if ( ! empty( $old_nonce ) ) {
            // Check if nonce is valid or recently expired (within 2 nonce ticks = ~24 hours).
            $nonce_action = 'pforms_submit_' . $form_id;
            $valid = wp_verify_nonce( $old_nonce, $nonce_action );

            // wp_verify_nonce returns: 1 = valid (0-12 hrs), 2 = valid (12-24 hrs), false = invalid
            if ( $valid === false ) {
                // Check if it's a very recently expired nonce by checking the next tick back.
                // This handles edge cases around the 24-hour boundary.
                $nonce_tick = ceil( time() / ( DAY_IN_SECONDS / 2 ) );
                $expected_old = substr( wp_hash( ( $nonce_tick - 2 ) . '|' . $nonce_action . '|' . wp_get_session_token() . '|' . get_uid(), 'nonce' ), -12, 10 );

                // If not within grace period, reject.
                if ( ! hash_equals( $expected_old, $old_nonce ) ) {
                    wp_send_json_error( array( 'message' => __( 'Invalid request. Please reload the page.', 'promptless-forms' ) ) );
                }
            }
        }
        // Note: If no old nonce provided, we still allow it for backwards compatibility
        // but the rate limiting provides protection against abuse.

        wp_send_json_success( array(
            'nonce' => wp_create_nonce( 'pforms_submit_' . $form_id ),
        ) );
    }
}
