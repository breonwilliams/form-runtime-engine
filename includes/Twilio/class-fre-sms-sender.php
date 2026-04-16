<?php
/**
 * SMS Sender for Form Runtime Engine Twilio integration.
 *
 * Sends outbound SMS via the Twilio API and logs all messages
 * to the fre_twilio_messages table for conversation tracking.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles outbound SMS sending and message logging.
 */
class FRE_SMS_Sender {

    /**
     * Default hourly SMS limit per client.
     *
     * @var int
     */
    const DEFAULT_HOURLY_LIMIT = 50;

    /**
     * Default daily global SMS limit.
     *
     * @var int
     */
    const DEFAULT_DAILY_LIMIT = 500;

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Messages table name.
     *
     * @var string
     */
    private $messages_table;

    /**
     * Twilio API client.
     *
     * @var FRE_Twilio_Client
     */
    private $client;

    /**
     * Constructor.
     *
     * @param FRE_Twilio_Client $client Twilio API client instance.
     */
    public function __construct( FRE_Twilio_Client $client ) {
        global $wpdb;
        $this->wpdb           = $wpdb;
        $this->messages_table = $wpdb->prefix . 'fre_twilio_messages';
        $this->client         = $client;
    }

    /**
     * Send an SMS and log it.
     *
     * @param string $to       Recipient phone number (E.164).
     * @param string $from     Sender Twilio phone number (E.164).
     * @param string $body     Message text.
     * @param int    $entry_id Associated FRE entry ID (0 if no entry yet).
     * @return array|WP_Error Twilio response data or WP_Error on failure.
     */
    public function send( $to, $from, $body, $entry_id = 0 ) {
        // Rate limit check.
        $rate_check = $this->check_rate_limit( $from );
        if ( is_wp_error( $rate_check ) ) {
            return $rate_check;
        }

        // Build status callback URL.
        $status_callback = rest_url( 'fre-twilio/v1/sms-status' );

        // Send via Twilio API.
        $response = $this->client->send_sms( $to, $from, $body, $status_callback );

        // Log the message regardless of success/failure.
        $message_sid = '';
        $status      = 'failed';

        if ( ! is_wp_error( $response ) ) {
            $message_sid = isset( $response['sid'] ) ? $response['sid'] : '';
            $status      = isset( $response['status'] ) ? $response['status'] : 'queued';
        }

        $log_id = $this->log_message( $entry_id, 'outbound', $body, $message_sid, $status );

        if ( is_wp_error( $response ) ) {
            FRE_Logger::error(
                sprintf(
                    'SMS send failed: To=%s, From=%s, Error=%s',
                    $to,
                    $from,
                    $response->get_error_message()
                )
            );
            return $response;
        }

        /**
         * Fires after an SMS is sent successfully.
         *
         * @param string $to       Recipient phone number.
         * @param string $from     Sender phone number.
         * @param string $body     Message text.
         * @param int    $entry_id Associated entry ID.
         * @param array  $response Twilio API response.
         */
        do_action( 'fre_twilio_sms_sent', $to, $from, $body, $entry_id, $response );

        return $response;
    }

    /**
     * Log an inbound SMS message.
     *
     * @param int    $entry_id    Associated FRE entry ID.
     * @param string $body        Message content.
     * @param string $message_sid Twilio message SID.
     * @return int|false Log entry ID on success, false on failure.
     */
    public function log_inbound( $entry_id, $body, $message_sid ) {
        return $this->log_message( $entry_id, 'inbound', $body, $message_sid, 'received' );
    }

    /**
     * Log a message to the messages table.
     *
     * @param int    $entry_id    Associated FRE entry ID.
     * @param string $direction   Message direction (inbound or outbound).
     * @param string $body        Message content.
     * @param string $message_sid Twilio message SID.
     * @param string $status      Message status.
     * @return int|false Log entry ID on success, false on failure.
     */
    private function log_message( $entry_id, $direction, $body, $message_sid, $status ) {
        $result = $this->wpdb->insert(
            $this->messages_table,
            array(
                'entry_id'           => absint( $entry_id ),
                'direction'          => sanitize_key( $direction ),
                'body'               => sanitize_textarea_field( $body ),
                'twilio_message_sid' => sanitize_text_field( $message_sid ),
                'status'             => sanitize_key( $status ),
                'created_at'         => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( $result === false ) {
            FRE_Logger::error( 'Failed to log SMS message: ' . $this->wpdb->last_error );
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Update a message status by Twilio message SID.
     *
     * Called when we receive a status callback from Twilio.
     *
     * @param string $message_sid Twilio message SID.
     * @param string $status      New status (sent, delivered, failed, undelivered).
     * @return bool True on success, false on failure.
     */
    public function update_status( $message_sid, $status ) {
        if ( empty( $message_sid ) ) {
            return false;
        }

        $result = $this->wpdb->update(
            $this->messages_table,
            array( 'status' => sanitize_key( $status ) ),
            array( 'twilio_message_sid' => sanitize_text_field( $message_sid ) ),
            array( '%s' ),
            array( '%s' )
        );

        return $result !== false;
    }

    /**
     * Get all messages for an entry.
     *
     * @param int $entry_id Entry ID.
     * @return array Array of message records.
     */
    public function get_messages( $entry_id ) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->messages_table} WHERE entry_id = %d ORDER BY created_at ASC",
                $entry_id
            ),
            ARRAY_A
        );
    }

    /**
     * Check rate limit for outbound SMS.
     *
     * Prevents runaway costs from misconfiguration or abuse.
     *
     * @param string $from_number The Twilio number being sent from (for per-client tracking).
     * @return bool|WP_Error True if within limits, WP_Error if rate limited.
     */
    private function check_rate_limit( $from_number ) {
        // Check per-client hourly limit.
        $hourly_limit = apply_filters( 'fre_twilio_hourly_sms_limit', self::DEFAULT_HOURLY_LIMIT );
        $hourly_key   = 'fre_twilio_sms_' . md5( $from_number ) . '_' . gmdate( 'YmdH' );
        $hourly_count = (int) get_transient( $hourly_key );

        if ( $hourly_count >= $hourly_limit ) {
            FRE_Logger::error(
                sprintf( 'SMS rate limit reached: %d/%d hourly for %s', $hourly_count, $hourly_limit, $from_number )
            );
            return new WP_Error(
                'rate_limited',
                __( 'Hourly SMS limit reached for this number.', 'form-runtime-engine' )
            );
        }

        // Check global daily limit.
        $daily_limit = apply_filters( 'fre_twilio_daily_sms_limit', self::DEFAULT_DAILY_LIMIT );
        $daily_key   = 'fre_twilio_sms_global_' . gmdate( 'Ymd' );
        $daily_count = (int) get_transient( $daily_key );

        if ( $daily_count >= $daily_limit ) {
            FRE_Logger::error(
                sprintf( 'SMS global daily limit reached: %d/%d', $daily_count, $daily_limit )
            );
            return new WP_Error(
                'rate_limited',
                __( 'Daily SMS limit reached.', 'form-runtime-engine' )
            );
        }

        // Increment counters.
        set_transient( $hourly_key, $hourly_count + 1, HOUR_IN_SECONDS );
        set_transient( $daily_key, $daily_count + 1, DAY_IN_SECONDS );

        return true;
    }
}
