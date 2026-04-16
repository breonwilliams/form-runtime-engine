<?php
/**
 * Twilio API client for Form Runtime Engine.
 *
 * Handles all communication with the Twilio REST API using
 * WordPress native wp_remote_post() instead of the Twilio SDK.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Twilio API client wrapper.
 */
class FRE_Twilio_Client {

    /**
     * Twilio API base URL.
     *
     * @var string
     */
    const API_BASE = 'https://api.twilio.com/2010-04-01/Accounts/';

    /**
     * Default timeout for API requests in seconds.
     *
     * @var int
     */
    const TIMEOUT = 15;

    /**
     * Twilio Account SID.
     *
     * @var string
     */
    private $account_sid;

    /**
     * Twilio Auth Token.
     *
     * @var string
     */
    private $auth_token;

    /**
     * Constructor.
     *
     * @param string $account_sid Twilio Account SID.
     * @param string $auth_token  Twilio Auth Token.
     */
    public function __construct( $account_sid, $auth_token ) {
        $this->account_sid = $account_sid;
        $this->auth_token  = $auth_token;
    }

    /**
     * Create a Twilio client from stored settings.
     *
     * @return FRE_Twilio_Client|WP_Error Client instance or error if credentials not configured.
     */
    public static function from_settings() {
        $settings = get_option( 'fre_twilio_settings', array() );

        $account_sid = isset( $settings['account_sid'] ) ? $settings['account_sid'] : '';
        $auth_token  = isset( $settings['auth_token'] ) ? $settings['auth_token'] : '';

        // Decrypt credentials if they are stored encrypted.
        $account_sid = self::decrypt_value( $account_sid );
        $auth_token  = self::decrypt_value( $auth_token );

        if ( empty( $account_sid ) || empty( $auth_token ) ) {
            return new WP_Error(
                'twilio_not_configured',
                __( 'Twilio credentials are not configured.', 'form-runtime-engine' )
            );
        }

        return new self( $account_sid, $auth_token );
    }

    /**
     * Send an SMS message.
     *
     * @param string $to               Recipient phone number (E.164 format).
     * @param string $from             Sender phone number (Twilio number, E.164 format).
     * @param string $body             Message text content.
     * @param string $status_callback  Optional URL for delivery status updates.
     * @return array|WP_Error Twilio API response array or WP_Error on failure.
     */
    public function send_sms( $to, $from, $body, $status_callback = '' ) {
        $endpoint = self::API_BASE . $this->account_sid . '/Messages.json';

        $request_body = array(
            'To'   => $to,
            'From' => $from,
            'Body' => $body,
        );

        if ( ! empty( $status_callback ) ) {
            $request_body['StatusCallback'] = $status_callback;
        }

        $response = wp_remote_post(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                ),
                'body'    => $request_body,
                'timeout' => self::TIMEOUT,
            )
        );

        if ( is_wp_error( $response ) ) {
            FRE_Logger::error( 'Twilio SMS Error: ' . $response->get_error_message() );
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );
        $body_raw    = wp_remote_retrieve_body( $response );
        $data        = json_decode( $body_raw, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_msg = isset( $data['message'] ) ? $data['message'] : 'Unknown Twilio error';
            $error_code = isset( $data['code'] ) ? $data['code'] : $status_code;

            FRE_Logger::error(
                sprintf( 'Twilio SMS Error (%d): %s | To: %s | From: %s', $error_code, $error_msg, $to, $from )
            );

            return new WP_Error(
                'twilio_api_error',
                $error_msg,
                array(
                    'status'     => $status_code,
                    'twilio_code' => $error_code,
                )
            );
        }

        return $data;
    }

    /**
     * Get the Auth Token (for signature validation).
     *
     * @return string The Auth Token.
     */
    public function get_auth_token() {
        return $this->auth_token;
    }

    /**
     * Get the Account SID.
     *
     * @return string The Account SID.
     */
    public function get_account_sid() {
        return $this->account_sid;
    }

    /**
     * Test the Twilio connection.
     *
     * Makes a simple API call to verify credentials are valid.
     *
     * @return bool|WP_Error True if connection successful, WP_Error on failure.
     */
    public function test_connection() {
        $endpoint = self::API_BASE . $this->account_sid . '.json';

        $response = wp_remote_get(
            $endpoint,
            array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $this->account_sid . ':' . $this->auth_token ),
                ),
                'timeout' => self::TIMEOUT,
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status_code = wp_remote_retrieve_response_code( $response );

        if ( $status_code === 200 ) {
            return true;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $error_msg = isset( $body['message'] ) ? $body['message'] : 'Connection failed';

        return new WP_Error( 'twilio_connection_failed', $error_msg, array( 'status' => $status_code ) );
    }

    /**
     * Encrypt a value for storage.
     *
     * Uses WordPress security salts for encryption.
     *
     * @param string $value Plain text value.
     * @return string Encrypted value.
     */
    public static function encrypt_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        $key    = wp_salt( 'auth' );
        $iv     = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $method = 'aes-256-cbc';

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            // Fallback: base64 encoding (not true encryption, but better than plain text).
            return 'b64:' . base64_encode( $value );
        }

        $encrypted = openssl_encrypt( $value, $method, $key, 0, $iv );

        return 'enc:' . $encrypted;
    }

    /**
     * Decrypt a stored value.
     *
     * @param string $value Encrypted value.
     * @return string Decrypted value.
     */
    public static function decrypt_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        // Plain text (not encrypted).
        if ( strpos( $value, 'enc:' ) !== 0 && strpos( $value, 'b64:' ) !== 0 ) {
            return $value;
        }

        // Base64 fallback.
        if ( strpos( $value, 'b64:' ) === 0 ) {
            return base64_decode( substr( $value, 4 ) );
        }

        // AES-256-CBC encrypted.
        $key    = wp_salt( 'auth' );
        $iv     = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $method = 'aes-256-cbc';

        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return '';
        }

        $decrypted = openssl_decrypt( substr( $value, 4 ), $method, $key, 0, $iv );

        return $decrypted !== false ? $decrypted : '';
    }
}
