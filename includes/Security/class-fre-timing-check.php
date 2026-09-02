<?php
/**
 * Timing Check Protection for Promptless Forms.
 *
 * Detects bot submissions that happen too quickly.
 *
 * NOTE: Called from submission handler after nonce verification.
 * $_POST access is safe as the caller has already verified the nonce.
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
 * Timing-based spam protection.
 */
class PForms_Timing_Check {

    /**
     * Default minimum submission time in seconds.
     *
     * @var int
     */
    private $default_min_time = 3;

    /**
     * Check if submission was too fast (Fix #16: Signed timestamp tokens).
     *
     * Uses HMAC-signed tokens to prevent timestamp manipulation.
     *
     * @param string $form_id Form ID.
     * @param array  $settings Form spam protection settings.
     * @return bool True if submission was too fast (likely bot).
     */
    public function is_too_fast( $form_id, array $settings = array() ) {
        $token = isset( $_POST['_pforms_timing_token'] ) ? sanitize_text_field( wp_unslash( $_POST['_pforms_timing_token'] ) ) : '';

        // Fallback: Check for legacy timestamp (for backwards compatibility).
        if ( empty( $token ) ) {
            $timestamp = isset( $_POST['_pforms_timestamp'] ) ? (int) $_POST['_pforms_timestamp'] : 0;

            if ( empty( $timestamp ) ) {
                // No timestamp provided, fail closed for security.
                $this->log_spam_attempt( $form_id, 'no_timing_token', 0 );
                return true;
            }

            $min_time = isset( $settings['min_submission_time'] )
                ? (int) $settings['min_submission_time']
                : $this->default_min_time;

            $elapsed = time() - $timestamp;

            if ( $elapsed < $min_time ) {
                $this->log_spam_attempt( $form_id, 'timing', $elapsed );
                return true;
            }

            return false;
        }

        // Fix #16: Validate signed timing token.
        $validation = $this->validate_timing_token( $token, $form_id );
        if ( is_wp_error( $validation ) ) {
            $this->log_spam_attempt( $form_id, 'invalid_timing_token', 0 );
            return true;
        }

        $timestamp = $validation;
        $min_time  = isset( $settings['min_submission_time'] )
            ? (int) $settings['min_submission_time']
            : $this->default_min_time;

        $elapsed = time() - $timestamp;

        // If submitted faster than minimum time, likely a bot.
        if ( $elapsed < $min_time ) {
            $this->log_spam_attempt( $form_id, 'timing', $elapsed );
            return true;
        }

        return false;
    }

    /**
     * Generate a signed timing token (Fix #16).
     *
     * @param string $form_id Form ID.
     * @return string Signed timing token.
     */
    public static function generate_timing_token( $form_id ) {
        $timestamp = time();
        $signature = hash_hmac( 'sha256', $timestamp . '|' . $form_id, wp_salt( 'auth' ) );
        return base64_encode( $timestamp . '|' . $signature );
    }

    /**
     * Validate a signed timing token (Fix #16).
     *
     * @param string $token   The timing token.
     * @param string $form_id Form ID.
     * @return int|WP_Error Timestamp on success, WP_Error on failure.
     */
    private function validate_timing_token( $token, $form_id ) {
        $decoded = base64_decode( $token, true );
        if ( $decoded === false ) {
            return new WP_Error( 'invalid_token', 'Invalid timing token.' );
        }

        $parts = explode( '|', $decoded, 2 );
        if ( count( $parts ) !== 2 ) {
            return new WP_Error( 'invalid_token', 'Malformed timing token.' );
        }

        list( $timestamp, $signature ) = $parts;
        $timestamp = (int) $timestamp;

        // Validate signature.
        $expected = hash_hmac( 'sha256', $timestamp . '|' . $form_id, wp_salt( 'auth' ) );
        if ( ! hash_equals( $expected, $signature ) ) {
            return new WP_Error( 'invalid_signature', 'Invalid timing token signature.' );
        }

        // NO AGE CEILING HERE, deliberately.
        //
        // This class detects submissions that are too FAST. Rejecting a token
        // for being too OLD inverted that: a 25-hour-old token is the
        // strongest available evidence that a submission was not rushed, and
        // it was being treated as spam. The visitor got "Please wait a moment
        // before submitting." — advice that cannot help, because waiting is
        // exactly what they had already done.
        //
        // It also produced a guaranteed false positive that no amount of
        // careful behaviour avoids. The client refreshes the NONCE after an
        // hour open (assets/js/frontend.js, refreshNonce) but nothing ever
        // refreshed this token, so the two credentials drifted apart: the
        // session was kept alive indefinitely while the token expired at
        // exactly 86400s. Any form submitted more than 24 hours after its
        // HTML was generated failed here — a tab left open overnight, or a
        // page served from a long-lived cache. Reproduced over HTTP: the same
        // request with a 60-second-old token succeeds and with a 25-hour-old
        // token returns timing_check_failed.
        //
        // The ceiling bought nothing against bots either: a token costs one
        // GET to obtain, so an attacker simply fetches a fresh one. It only
        // ever penalised slow humans.
        //
        // Forgery is still prevented by the HMAC check above, and the actual
        // anti-bot value — min_submission_time — still applies to the
        // timestamp this returns. If "form rendered suspiciously long ago" is
        // ever a concern worth acting on, is_too_old() below exists for
        // exactly that and should be called explicitly, not smuggled into a
        // speed check.
        return $timestamp;
    }

    /**
     * Validate timing.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Form spam protection settings.
     * @return bool|WP_Error True if valid, WP_Error if spam detected.
     */
    public function validate( $form_id, array $settings = array() ) {
        if ( $this->is_too_fast( $form_id, $settings ) ) {
            return new WP_Error(
                'timing_check_failed',
                __( 'Please wait a moment before submitting.', 'promptless-forms' )
            );
        }

        return true;
    }

    /**
     * Check if submission is suspiciously old (stale form).
     *
     * Forms older than 24 hours might indicate session hijacking or stale pages.
     *
     * @param int $max_age Maximum form age in seconds (default: 24 hours).
     * @return bool True if form is too old.
     */
    public function is_too_old( $max_age = 86400 ) {
        $timestamp = isset( $_POST['_pforms_timestamp'] ) ? (int) $_POST['_pforms_timestamp'] : 0;

        if ( empty( $timestamp ) ) {
            return false;
        }

        $elapsed = time() - $timestamp;

        return $elapsed > $max_age;
    }

    /**
     * Get elapsed time since form render.
     *
     * @return int Elapsed seconds, or 0 if timestamp not available.
     */
    public function get_elapsed_time() {
        $timestamp = isset( $_POST['_pforms_timestamp'] ) ? (int) $_POST['_pforms_timestamp'] : 0;

        if ( empty( $timestamp ) ) {
            return 0;
        }

        return time() - $timestamp;
    }

    /**
     * Log spam attempt for monitoring.
     *
     * @param string $form_id Form ID.
     * @param string $reason  Reason for flagging as spam.
     * @param int    $elapsed Elapsed time in seconds.
     */
    private function log_spam_attempt( $form_id, $reason, $elapsed ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';

        PForms_Logger::warning( sprintf(
            'Spam blocked: form=%s, reason=%s, elapsed=%ds, ip=%s',
            $form_id,
            $reason,
            $elapsed,
            $ip
        ) );

        /**
         * Fires when spam is detected.
         *
         * @param string $form_id Form ID.
         * @param string $reason  Detection reason.
         * @param string $ip      Client IP.
         */
        do_action( 'pforms_spam_detected', $form_id, $reason, $ip );
    }
}
