<?php
/**
 * Twilio Webhook Handler for Form Runtime Engine.
 *
 * Registers REST API endpoints that receive Twilio webhooks for incoming calls,
 * call status updates, incoming SMS, and SMS delivery status. Processes missed
 * calls by sending auto-reply SMS and creating FRE lead entries.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles all Twilio webhook interactions.
 */
class FRE_Twilio_Handler {

    /**
     * REST API namespace.
     *
     * @var string
     */
    const NAMESPACE = 'fre-twilio/v1';

    /**
     * Call statuses that indicate a missed call.
     *
     * @var array
     */
    const MISSED_CALL_STATUSES = array( 'no-answer', 'busy', 'failed' );

    /**
     * Default dial timeout in seconds.
     *
     * @var int
     */
    const DEFAULT_DIAL_TIMEOUT = 20;

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Clients table name.
     *
     * @var string
     */
    private $clients_table;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->clients_table = $wpdb->prefix . 'fre_twilio_clients';
    }

    /**
     * Initialize the handler.
     *
     * Registers REST API routes.
     */
    public function init() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
        add_filter( 'rest_pre_serve_request', array( $this, 'serve_twiml_response' ), 10, 4 );
    }

    /**
     * Intercept REST responses that contain TwiML and output raw XML.
     *
     * WordPress REST API always JSON-encodes response data, which corrupts
     * TwiML XML. This filter detects TwiML responses (by Content-Type header)
     * and sends the raw XML directly, bypassing JSON encoding.
     *
     * @param bool             $served  Whether the request has already been served.
     * @param WP_HTTP_Response $result  Result to send to the client.
     * @param WP_REST_Request  $request Request used to generate the response.
     * @param WP_REST_Server   $server  Server instance.
     * @return bool True if the request was served, false otherwise.
     */
    public function serve_twiml_response( $served, $result, $request, $server ) {
        // Only intercept our own namespace.
        $route = $request->get_route();
        if ( strpos( $route, '/' . self::NAMESPACE . '/' ) !== 0 ) {
            return $served;
        }

        // Check if this is a TwiML response (Content-Type: text/xml).
        $headers = $result->get_headers();
        if ( empty( $headers['Content-Type'] ) || strpos( $headers['Content-Type'], 'text/xml' ) === false ) {
            return $served;
        }

        // Send headers.
        $server->send_headers( $result->get_headers() );

        // Output raw TwiML directly, bypassing JSON encoding.
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- TwiML is pre-escaped XML.
        echo $result->get_data();

        return true;
    }

    /**
     * Register REST API routes.
     */
    public function register_routes() {
        // Incoming voice call — returns TwiML to dial the owner.
        register_rest_route(
            self::NAMESPACE,
            '/incoming-call',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_incoming_call' ),
                'permission_callback' => '__return_true', // Validated via Twilio signature.
            )
        );

        // Call status — processes the result after Dial attempt.
        register_rest_route(
            self::NAMESPACE,
            '/call-status',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_call_status' ),
                'permission_callback' => '__return_true',
            )
        );

        // Incoming SMS — customer text replies.
        register_rest_route(
            self::NAMESPACE,
            '/incoming-sms',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_incoming_sms' ),
                'permission_callback' => '__return_true',
            )
        );

        // SMS delivery status callback.
        register_rest_route(
            self::NAMESPACE,
            '/sms-status',
            array(
                'methods'             => 'POST',
                'callback'            => array( $this, 'handle_sms_status' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Handle an incoming voice call.
     *
     * Looks up the client by the Twilio number called, then returns
     * TwiML that dials the business owner's phone. If the owner
     * doesn't answer, Twilio will POST to the call-status endpoint.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response TwiML response.
     */
    public function handle_incoming_call( WP_REST_Request $request ) {
        // Validate Twilio signature.
        $auth_check = $this->validate_request();
        if ( is_wp_error( $auth_check ) ) {
            return $this->error_response( $auth_check );
        }

        // Extract call data from POST params.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $to_number   = isset( $_POST['To'] ) ? sanitize_text_field( wp_unslash( $_POST['To'] ) ) : '';
        $from_number = isset( $_POST['From'] ) ? sanitize_text_field( wp_unslash( $_POST['From'] ) ) : '';
        $call_sid    = isset( $_POST['CallSid'] ) ? sanitize_text_field( wp_unslash( $_POST['CallSid'] ) ) : '';

        // Look up the client by Twilio number.
        $client = $this->get_client_by_number( $to_number );

        if ( ! $client ) {
            FRE_Logger::error( 'Twilio: Incoming call to unregistered number: ' . $to_number );
            return $this->twiml_response( '<Response><Say>Sorry, this number is not configured.</Say><Hangup/></Response>' );
        }

        if ( ! $client['is_active'] ) {
            FRE_Logger::error( 'Twilio: Incoming call to inactive client: ' . $client['client_name'] );
            return $this->twiml_response( '<Response><Hangup/></Response>' );
        }

        $owner_phone  = $client['owner_phone'];
        $dial_timeout = apply_filters( 'fre_twilio_dial_timeout', self::DEFAULT_DIAL_TIMEOUT, $client );

        // Build the call-status action URL with caller info in query params.
        $action_url = add_query_arg(
            array(
                'caller'   => rawurlencode( $from_number ),
                'call_sid' => rawurlencode( $call_sid ),
            ),
            rest_url( self::NAMESPACE . '/call-status' )
        );

        // Return TwiML to dial the owner's phone.
        $twiml = sprintf(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response>' .
            '<Dial timeout="%d" action="%s" method="POST">' .
            '<Number>%s</Number>' .
            '</Dial>' .
            '</Response>',
            $dial_timeout,
            esc_url( $action_url ),
            esc_html( $owner_phone )
        );

        FRE_Logger::info(
            sprintf( 'Twilio: Incoming call from %s to %s (%s), forwarding to %s', $from_number, $to_number, $client['client_name'], $owner_phone )
        );

        return $this->twiml_response( $twiml );
    }

    /**
     * Handle call status after Dial attempt.
     *
     * If the call was not answered (no-answer, busy, failed), triggers
     * the text-back sequence: auto-reply SMS to caller, notification
     * SMS to owner, and creates an FRE lead entry.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response TwiML response.
     */
    public function handle_call_status( WP_REST_Request $request ) {
        // Validate Twilio signature.
        $auth_check = $this->validate_request();
        if ( is_wp_error( $auth_check ) ) {
            return $this->error_response( $auth_check );
        }

        // Extract status data.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $dial_status = isset( $_POST['DialCallStatus'] ) ? sanitize_text_field( wp_unslash( $_POST['DialCallStatus'] ) ) : '';
        $to_number   = isset( $_POST['To'] ) ? sanitize_text_field( wp_unslash( $_POST['To'] ) ) : '';

        // Get caller info from query params (passed from incoming-call action URL).
        $caller_number = isset( $_GET['caller'] ) ? sanitize_text_field( wp_unslash( $_GET['caller'] ) ) : '';
        $call_sid      = isset( $_GET['call_sid'] ) ? sanitize_text_field( wp_unslash( $_GET['call_sid'] ) ) : '';

        // Look up the client.
        $client = $this->get_client_by_number( $to_number );

        if ( ! $client ) {
            return $this->twiml_response( '<Response><Hangup/></Response>' );
        }

        // Check if this is a missed call.
        if ( ! in_array( $dial_status, self::MISSED_CALL_STATUSES, true ) ) {
            // Call was answered — log if needed but no text-back.
            FRE_Logger::info(
                sprintf( 'Twilio: Call answered for %s (status: %s)', $client['client_name'], $dial_status )
            );
            return $this->twiml_response( '<Response><Hangup/></Response>' );
        }

        // === MISSED CALL — Trigger text-back sequence ===

        FRE_Logger::info(
            sprintf( 'Twilio: Missed call from %s for %s (status: %s) — triggering text-back', $caller_number, $client['client_name'], $dial_status )
        );

        // 1. Create the FRE lead entry.
        $entry_id = $this->create_lead_entry( $client, $caller_number, $call_sid, $dial_status );

        // 2. Get the Twilio client for sending SMS.
        $twilio_client = FRE_Twilio_Client::from_settings();
        if ( is_wp_error( $twilio_client ) ) {
            FRE_Logger::error( 'Twilio: Cannot send text-back — ' . $twilio_client->get_error_message() );
            return $this->twiml_response( '<Response><Hangup/></Response>' );
        }

        $sms_sender = new FRE_SMS_Sender( $twilio_client );

        // 3. Send auto-reply SMS to the caller.
        $auto_reply = $this->build_auto_reply( $client );
        $sms_sender->send( $caller_number, $client['twilio_number'], $auto_reply, $entry_id );

        // 4. Send notification SMS to the business owner.
        $owner_msg = sprintf(
            'Missed call from %s. Text-back sent automatically. — %s via FlowMint',
            $caller_number,
            $client['client_name']
        );
        $sms_sender->send( $client['owner_phone'], $client['twilio_number'], $owner_msg, $entry_id );

        // 5. Send email notification if configured.
        $this->send_email_notification( $client, $caller_number, $entry_id );

        return $this->twiml_response( '<Response><Hangup/></Response>' );
    }

    /**
     * Handle an incoming SMS message.
     *
     * When a customer texts the Twilio number (typically replying to
     * the auto-text), log the message and forward it to the business owner.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response TwiML response.
     */
    public function handle_incoming_sms( WP_REST_Request $request ) {
        // Validate Twilio signature.
        $auth_check = $this->validate_request();
        if ( is_wp_error( $auth_check ) ) {
            return $this->error_response( $auth_check );
        }

        // Extract SMS data.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $from_number = isset( $_POST['From'] ) ? sanitize_text_field( wp_unslash( $_POST['From'] ) ) : '';
        $to_number   = isset( $_POST['To'] ) ? sanitize_text_field( wp_unslash( $_POST['To'] ) ) : '';
        $body        = isset( $_POST['Body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['Body'] ) ) : '';
        $message_sid = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';

        // Look up the client.
        $client = $this->get_client_by_number( $to_number );

        if ( ! $client ) {
            FRE_Logger::error( 'Twilio: Incoming SMS to unregistered number: ' . $to_number );
            return $this->twiml_response( '<Response/>' );
        }

        FRE_Logger::info(
            sprintf( 'Twilio: Incoming SMS from %s to %s (%s): %s', $from_number, $to_number, $client['client_name'], substr( $body, 0, 50 ) )
        );

        // Find or create an entry for this caller.
        $entry_id = $this->find_or_create_entry( $client, $from_number );

        // Log the inbound message.
        $twilio_client = FRE_Twilio_Client::from_settings();
        if ( ! is_wp_error( $twilio_client ) ) {
            $sms_sender = new FRE_SMS_Sender( $twilio_client );
            $sms_sender->log_inbound( $entry_id, $body, $message_sid );

            // Forward the message to the business owner.
            $forward_msg = sprintf(
                'Reply from %s: "%s" — %s via FlowMint',
                $from_number,
                $body,
                $client['client_name']
            );
            $sms_sender->send( $client['owner_phone'], $client['twilio_number'], $forward_msg, $entry_id );
        }

        // Return empty TwiML (no auto-reply in MVP).
        return $this->twiml_response( '<Response/>' );
    }

    /**
     * Handle SMS delivery status callback.
     *
     * Updates the message status in the messages table when Twilio
     * reports delivery confirmation or failure.
     *
     * @param WP_REST_Request $request The incoming request.
     * @return WP_REST_Response
     */
    public function handle_sms_status( WP_REST_Request $request ) {
        // Validate Twilio signature.
        $auth_check = $this->validate_request();
        if ( is_wp_error( $auth_check ) ) {
            return $this->error_response( $auth_check );
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $message_sid    = isset( $_POST['MessageSid'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageSid'] ) ) : '';
        $message_status = isset( $_POST['MessageStatus'] ) ? sanitize_text_field( wp_unslash( $_POST['MessageStatus'] ) ) : '';

        if ( ! empty( $message_sid ) && ! empty( $message_status ) ) {
            $twilio_client = FRE_Twilio_Client::from_settings();
            if ( ! is_wp_error( $twilio_client ) ) {
                $sms_sender = new FRE_SMS_Sender( $twilio_client );
                $sms_sender->update_status( $message_sid, $message_status );
            }
        }

        return new WP_REST_Response( null, 200 );
    }

    // ──────────────────────────────────────────────────────────────
    // Private helper methods
    // ──────────────────────────────────────────────────────────────

    /**
     * Validate the current request using Twilio signature.
     *
     * @return bool|WP_Error True if valid, WP_Error on failure.
     */
    private function validate_request() {
        $twilio_client = FRE_Twilio_Client::from_settings();

        if ( is_wp_error( $twilio_client ) ) {
            return $twilio_client;
        }

        return FRE_Twilio_Validator::validate_current_request( $twilio_client->get_auth_token() );
    }

    /**
     * Look up a client by their Twilio phone number.
     *
     * @param string $twilio_number The Twilio number (E.164 format).
     * @return array|null Client record or null if not found.
     */
    private function get_client_by_number( $twilio_number ) {
        if ( empty( $twilio_number ) ) {
            return null;
        }

        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->clients_table} WHERE twilio_number = %s",
                $twilio_number
            ),
            ARRAY_A
        );
    }

    /**
     * Create an FRE lead entry for a missed call.
     *
     * Creates the entry in the standard fre_entries table with source
     * metadata, so it appears in the same lead list as form submissions.
     * The fre_entry_created action fires automatically, triggering
     * webhook dispatch to Google Sheets.
     *
     * @param array  $client       Client configuration record.
     * @param string $caller_phone Caller's phone number.
     * @param string $call_sid     Twilio Call SID.
     * @param string $call_status  Twilio call status (no-answer, busy, etc).
     * @return int|false Entry ID on success, false on failure.
     */
    private function create_lead_entry( $client, $caller_phone, $call_sid, $call_status ) {
        $entry_repo = new FRE_Entry();

        $data = array(
            'phone'        => sanitize_text_field( $caller_phone ),
            '_source_type' => 'missed_call',
            '_call_sid'    => sanitize_text_field( $call_sid ),
            '_call_status' => sanitize_text_field( $call_status ),
            '_client_name' => sanitize_text_field( $client['client_name'] ),
        );

        try {
            $entry_id = $entry_repo->create( $client['form_id'], $data );

            if ( is_wp_error( $entry_id ) ) {
                FRE_Logger::error( 'Failed to create lead entry: ' . $entry_id->get_error_message() );
                return false;
            }

            FRE_Logger::info(
                sprintf( 'Twilio: Created lead entry #%d for %s (caller: %s)', $entry_id, $client['client_name'], $caller_phone )
            );

            return $entry_id;

        } catch ( Exception $e ) {
            FRE_Logger::error( 'Failed to create lead entry: ' . $e->getMessage() );
            return false;
        }
    }

    /**
     * Find an existing entry for a caller, or create a new one.
     *
     * Used when an inbound SMS arrives — tries to find a recent entry
     * for this phone number to associate the message with.
     *
     * @param array  $client      Client configuration record.
     * @param string $from_number Caller's phone number.
     * @return int Entry ID.
     */
    private function find_or_create_entry( $client, $from_number ) {
        // Look for a recent entry from this phone number for this client.
        $entry_meta_table = $this->wpdb->prefix . 'fre_entry_meta';
        $entries_table    = $this->wpdb->prefix . 'fre_entries';

        $entry_id = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT e.id FROM {$entries_table} e
                 INNER JOIN {$entry_meta_table} em ON e.id = em.entry_id
                 WHERE e.form_id = %s
                 AND em.field_key = 'phone'
                 AND em.field_value = %s
                 AND e.created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                 ORDER BY e.created_at DESC
                 LIMIT 1",
                $client['form_id'],
                $from_number
            )
        );

        if ( $entry_id ) {
            return (int) $entry_id;
        }

        // No recent entry found — create a new one from the SMS.
        $entry_repo = new FRE_Entry();

        try {
            $new_entry_id = $entry_repo->create(
                $client['form_id'],
                array(
                    'phone'        => sanitize_text_field( $from_number ),
                    '_source_type' => 'sms_inbound',
                    '_client_name' => sanitize_text_field( $client['client_name'] ),
                )
            );

            if ( is_wp_error( $new_entry_id ) ) {
                return 0;
            }

            return $new_entry_id;

        } catch ( Exception $e ) {
            FRE_Logger::error( 'Failed to create SMS lead entry: ' . $e->getMessage() );
            return 0;
        }
    }

    /**
     * Build the auto-reply message for a missed call.
     *
     * Replaces template variables in the client's auto-reply template.
     *
     * @param array $client Client configuration record.
     * @return string The auto-reply message text.
     */
    private function build_auto_reply( $client ) {
        $template = $client['auto_reply_template'];

        // Replace template variables.
        $replacements = array(
            '{business_name}' => $client['client_name'],
            '{client_name}'   => $client['client_name'],
        );

        /**
         * Filter the auto-reply template variables.
         *
         * @param array $replacements Template variable replacements.
         * @param array $client       Client configuration.
         */
        $replacements = apply_filters( 'fre_twilio_auto_reply_vars', $replacements, $client );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Send email notification for a missed call.
     *
     * Uses the same email notification pattern as FRE form submissions.
     *
     * @param array  $client       Client configuration.
     * @param string $caller_phone Caller's phone number.
     * @param int    $entry_id     FRE entry ID.
     */
    private function send_email_notification( $client, $caller_phone, $entry_id ) {
        if ( empty( $client['owner_email'] ) ) {
            return;
        }

        $subject = sprintf(
            'Missed Call from %s — %s',
            $caller_phone,
            $client['client_name']
        );

        $message = sprintf(
            "You missed a call from %s.\n\n" .
            "An automatic text message has been sent to the caller on behalf of %s.\n\n" .
            "Please follow up as soon as possible.\n\n" .
            "— FlowMint Lead System",
            $caller_phone,
            $client['client_name']
        );

        wp_mail(
            sanitize_email( $client['owner_email'] ),
            $subject,
            $message,
            array( 'Content-Type: text/plain; charset=UTF-8' )
        );
    }

    /**
     * Return a TwiML response.
     *
     * @param string $twiml TwiML XML string.
     * @return WP_REST_Response
     */
    private function twiml_response( $twiml ) {
        $response = new WP_REST_Response( $twiml, 200 );
        $response->header( 'Content-Type', 'text/xml; charset=utf-8' );
        return $response;
    }

    /**
     * Return an error response as TwiML.
     *
     * Twilio always expects a TwiML response, even when authentication
     * fails. Returning JSON would cause a "Document parse failure" (12100).
     *
     * @param WP_Error $error The error.
     * @return WP_REST_Response TwiML error response.
     */
    private function error_response( WP_Error $error ) {
        FRE_Logger::error( 'Twilio webhook error: ' . $error->get_error_message() );

        return $this->twiml_response(
            '<?xml version="1.0" encoding="UTF-8"?>' .
            '<Response><Say>An error occurred. Please try again later.</Say><Hangup/></Response>'
        );
    }
}
