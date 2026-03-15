<?php
/**
 * Honeypot Protection for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Honeypot spam protection.
 *
 * Fix #13: Added server-side session token as fallback for non-JS users.
 */
class FRE_Honeypot {

    /**
     * Session token validity period in seconds (2 hours).
     *
     * @var int
     */
    private const TOKEN_VALIDITY = 7200;

    /**
     * Get or create site-specific secret for honeypot (Fix #26).
     *
     * @return string Secret key.
     */
    private function get_secret() {
        $secret = get_option( 'fre_honeypot_secret' );

        if ( ! $secret ) {
            $secret = wp_generate_password( 32, true, true );
            update_option( 'fre_honeypot_secret', $secret, false );
        }

        return $secret;
    }

    /**
     * Generate session token for non-JS fallback (Fix #13).
     *
     * The token is a time-limited HMAC that proves the user loaded the form.
     *
     * @param string $form_id Form ID.
     * @return string Session token.
     */
    public function generate_session_token( $form_id ) {
        $timestamp = time();
        $ip = $this->get_client_ip();

        // Create HMAC with timestamp, form_id, and IP.
        $data = $timestamp . '|' . $form_id . '|' . $ip;
        $signature = hash_hmac( 'sha256', $data, $this->get_secret() );

        // Format: timestamp.signature (base64 encoded).
        return base64_encode( $timestamp . '.' . $signature );
    }

    /**
     * Validate session token for non-JS fallback (Fix #13).
     *
     * @param string $form_id Form ID.
     * @param string $token   Session token.
     * @return bool True if valid.
     */
    public function validate_session_token( $form_id, $token ) {
        if ( empty( $token ) ) {
            return false;
        }

        $decoded = base64_decode( $token, true );
        if ( $decoded === false ) {
            return false;
        }

        $parts = explode( '.', $decoded, 2 );
        if ( count( $parts ) !== 2 ) {
            return false;
        }

        list( $timestamp, $signature ) = $parts;
        $timestamp = (int) $timestamp;

        // Check timestamp validity.
        $now = time();
        if ( $timestamp > $now || ( $now - $timestamp ) > self::TOKEN_VALIDITY ) {
            return false;
        }

        // Reconstruct and verify signature.
        $ip = $this->get_client_ip();
        $data = $timestamp . '|' . $form_id . '|' . $ip;
        $expected_signature = hash_hmac( 'sha256', $data, $this->get_secret() );

        return hash_equals( $expected_signature, $signature );
    }

    /**
     * Get session token field name.
     *
     * @param string $form_id Form ID.
     * @return string Field name.
     */
    public function get_session_token_field_name( $form_id ) {
        return '_fre_session_' . substr( hash_hmac( 'sha256', $form_id, $this->get_secret() ), 0, 8 );
    }

    /**
     * Get honeypot field name for a form (Fix #26: Site-specific, unpredictable).
     *
     * Uses HMAC with a site-specific secret to make field names unpredictable
     * even when form IDs are known.
     *
     * @param string $form_id Form ID.
     * @return string Honeypot field name.
     */
    public function get_field_name( $form_id ) {
        $hash = hash_hmac( 'sha256', $form_id, $this->get_secret() );
        return '_fre_website_url_' . substr( $hash, 0, 8 );
    }

    /**
     * Check if honeypot was triggered.
     *
     * @param string $form_id Form ID.
     * @return bool True if honeypot was triggered (spam detected).
     */
    public function is_triggered( $form_id ) {
        $field_name = $this->get_field_name( $form_id );

        // If the field has any value, it's likely a bot.
        if ( isset( $_POST[ $field_name ] ) && ! empty( $_POST[ $field_name ] ) ) {
            $this->log_spam_attempt( $form_id, 'honeypot' );
            return true;
        }

        return false;
    }

    /**
     * Validate honeypot with session token fallback (Fix #13).
     *
     * For JS users: The honeypot field is populated by JavaScript.
     * For non-JS users: A session token provides protection instead.
     *
     * @param string $form_id Form ID.
     * @return bool|WP_Error True if valid, WP_Error if spam detected.
     */
    public function validate( $form_id ) {
        // Check honeypot field.
        if ( $this->is_triggered( $form_id ) ) {
            return new WP_Error(
                'honeypot_triggered',
                __( 'Spam detected.', 'form-runtime-engine' )
            );
        }

        // Fix #13: Check if honeypot was initialized (JS ran).
        $honeypot_field = $this->get_field_name( $form_id );
        $honeypot_present = isset( $_POST[ $honeypot_field ] );

        // If honeypot is present (even if empty), JS ran and validation passed above.
        if ( $honeypot_present ) {
            return true;
        }

        // Fix #13: Honeypot not present - check session token as fallback.
        // This handles non-JS submissions.
        $session_field = $this->get_session_token_field_name( $form_id );
        $session_token = isset( $_POST[ $session_field ] )
            ? sanitize_text_field( wp_unslash( $_POST[ $session_field ] ) )
            : '';

        if ( $this->validate_session_token( $form_id, $session_token ) ) {
            return true;
        }

        // Neither honeypot nor valid session token - likely a bot or invalid request.
        $this->log_spam_attempt( $form_id, 'no_honeypot_or_session' );

        return new WP_Error(
            'validation_failed',
            __( 'Spam protection validation failed. Please try again.', 'form-runtime-engine' )
        );
    }

    /**
     * Log spam attempt for monitoring.
     *
     * @param string $form_id Form ID.
     * @param string $reason  Reason for flagging as spam.
     */
    private function log_spam_attempt( $form_id, $reason ) {
        $ip = $this->get_client_ip();

        error_log( sprintf(
            'FRE Spam blocked: form=%s, reason=%s, ip=%s',
            $form_id,
            $reason,
            $ip
        ) );

        /**
         * Fires when spam is detected.
         *
         * @param string $form_id Form ID.
         * @param string $reason  Detection reason.
         * @param string $ip      Client IP.
         */
        do_action( 'fre_spam_detected', $form_id, $reason, $ip );
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip() {
        return isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';
    }
}
