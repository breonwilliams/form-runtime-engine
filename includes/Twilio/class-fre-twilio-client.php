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
     * Wave 1 audit fix (item I3): distinguishes "credentials never
     * configured" from "credentials configured but unreadable." The
     * old behavior conflated both cases as `twilio_not_configured`,
     * which caused a host-migration scenario (where wp_salt rotated
     * and old encrypted values became un-decryptable) to silently
     * look like a fresh install — the admin would re-enter credentials
     * with no idea that the old encrypted blob was sitting orphaned
     * in `wp_options`. Now decryption failure surfaces explicitly as
     * `twilio_credentials_unreadable` so the admin sees a clear "Your
     * credentials are present but couldn't be decrypted — please
     * re-enter them" message.
     *
     * @return FRE_Twilio_Client|WP_Error Client instance, or:
     *           - WP_Error('twilio_not_configured') when neither
     *             credential is stored
     *           - WP_Error('twilio_credentials_unreadable') when
     *             credentials are stored but decryption failed
     */
    public static function from_settings() {
        $settings = get_option( 'fre_twilio_settings', array() );

        $stored_sid   = isset( $settings['account_sid'] ) ? $settings['account_sid'] : '';
        $stored_token = isset( $settings['auth_token'] ) ? $settings['auth_token'] : '';

        // Detect whether credentials were ever stored — even unreadable
        // ones are "stored." The is-stored check looks at the raw
        // pre-decrypt value because decrypt_value() returns '' for an
        // empty input AND returns WP_Error for an unreadable input.
        $sid_was_stored   = ! empty( $stored_sid );
        $token_was_stored = ! empty( $stored_token );

        // Run decryption.
        $account_sid = self::decrypt_value( $stored_sid );
        $auth_token  = self::decrypt_value( $stored_token );

        // Surface decryption failures explicitly. Either credential
        // failing to decrypt is a "credentials unreadable" condition —
        // the client cannot be constructed but the admin needs to see
        // that the credentials WERE saved (and need to be re-entered),
        // not that nothing was ever configured.
        if ( is_wp_error( $account_sid ) || is_wp_error( $auth_token ) ) {
            $reason = is_wp_error( $account_sid ) ? $account_sid : $auth_token;

            return new WP_Error(
                'twilio_credentials_unreadable',
                $reason->get_error_message(),
                array( 'underlying_code' => $reason->get_error_code() )
            );
        }

        // Neither stored nor decryptable to non-empty.
        if ( ! $sid_was_stored || ! $token_was_stored || empty( $account_sid ) || empty( $auth_token ) ) {
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
     * Uses WordPress security salts for encryption (AES-256-CBC).
     *
     * Wave 1 audit fix (item I1): historically this method silently
     * downgraded to base64 encoding when the openssl extension was
     * unavailable (`b64:` prefix). That was misleading — the storage
     * prefix and method name suggested encryption, but base64 is just
     * encoding. Anyone reading "credentials are encrypted" reasonably
     * believed the protection was real when it wasn't. The fix:
     * refuse to encode credentials without real encryption available
     * and return WP_Error so the admin sees an explicit message.
     *
     * Existing `b64:` values stored before this change continue to be
     * read by decrypt_value() (backward-compatible legacy path), but
     * no NEW `b64:` values are ever produced.
     *
     * @param string $value Plain text value.
     * @return string|WP_Error Encrypted value (with `enc:` prefix), empty
     *                         string when input is empty, or WP_Error
     *                         when the openssl extension is unavailable.
     */
    public static function encrypt_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        if ( ! function_exists( 'openssl_encrypt' ) ) {
            return new WP_Error(
                'twilio_openssl_missing',
                __( 'Twilio integration requires the PHP openssl extension to encrypt credentials. The credential was NOT saved. Please contact your host to enable openssl, then save again.', 'form-runtime-engine' )
            );
        }

        $key    = wp_salt( 'auth' );
        $iv     = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $method = 'aes-256-cbc';

        $encrypted = openssl_encrypt( $value, $method, $key, 0, $iv );

        if ( $encrypted === false ) {
            return new WP_Error(
                'twilio_encryption_failed',
                __( 'Failed to encrypt Twilio credential — openssl reported an error. The credential was NOT saved. This usually indicates a corrupted PHP installation.', 'form-runtime-engine' )
            );
        }

        return 'enc:' . $encrypted;
    }

    /**
     * Decrypt a stored value.
     *
     * Wave 1 audit fix (item I3): historically this method returned an
     * empty string in BOTH the "no stored value" and "stored value but
     * decryption failed" cases. That made decryption failure look like
     * "Twilio not configured" to the admin, which hid real problems
     * (corrupted ciphertext, salt rotation after a host migration,
     * openssl extension lost). The fix: empty string still means "no
     * stored value to decrypt" (input was empty); WP_Error means
     * "stored value present but couldn't be decrypted." Callers (in
     * particular `from_settings()`) check the type to surface the
     * right error to the admin.
     *
     * Returned-shape contract:
     *   - empty input               → ''            (no stored credential)
     *   - non-prefixed plain text   → $value        (legacy backward compat)
     *   - `b64:` prefix             → decoded text  (legacy backward compat for
     *                                                 sites that stored credentials
     *                                                 before the I1 fix)
     *   - `enc:` prefix, decrypts   → plain text
     *   - `enc:` prefix, fails      → WP_Error      (corrupted / wrong key)
     *   - `enc:` prefix, no openssl → WP_Error      (extension missing)
     *
     * @param string $value Stored value.
     * @return string|WP_Error Decrypted text, or WP_Error on failure.
     */
    public static function decrypt_value( $value ) {
        if ( empty( $value ) ) {
            return '';
        }

        // Plain text (not encrypted) — legacy values that predate
        // the encryption layer pass through.
        if ( strpos( $value, 'enc:' ) !== 0 && strpos( $value, 'b64:' ) !== 0 ) {
            return $value;
        }

        // `b64:` legacy fallback — preserved so sites that stored
        // credentials before the I1 fix continue to work. New writes
        // never produce `b64:` (encrypt_value refuses to downgrade).
        if ( strpos( $value, 'b64:' ) === 0 ) {
            $decoded = base64_decode( substr( $value, 4 ), true );
            if ( $decoded === false ) {
                return new WP_Error(
                    'twilio_decryption_failed',
                    __( 'Stored Twilio credential is in legacy base64 format but cannot be decoded. Please re-enter the credential in Settings → Twilio.', 'form-runtime-engine' )
                );
            }
            return $decoded;
        }

        // `enc:` — AES-256-CBC encrypted.
        if ( ! function_exists( 'openssl_decrypt' ) ) {
            return new WP_Error(
                'twilio_openssl_missing',
                __( 'Stored Twilio credential is encrypted but the PHP openssl extension is unavailable on this server. Please contact your host to enable openssl.', 'form-runtime-engine' )
            );
        }

        $key    = wp_salt( 'auth' );
        $iv     = substr( wp_salt( 'secure_auth' ), 0, 16 );
        $method = 'aes-256-cbc';

        $decrypted = openssl_decrypt( substr( $value, 4 ), $method, $key, 0, $iv );

        if ( $decrypted === false ) {
            return new WP_Error(
                'twilio_decryption_failed',
                __( 'Stored Twilio credential present but could not be decrypted. This usually means the WordPress security salts have rotated since the credential was saved. Please re-enter the credential in Settings → Twilio.', 'form-runtime-engine' )
            );
        }

        return $decrypted;
    }
}
