<?php
/**
 * Timing Check Protection for Form Runtime Engine.
 *
 * Detects bot submissions that happen too quickly.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Timing-based spam protection.
 */
class FRE_Timing_Check {

    /**
     * Default minimum submission time in seconds.
     *
     * @var int
     */
    private $default_min_time = 3;

    /**
     * Check if submission was too fast.
     *
     * @param string $form_id Form ID.
     * @param array  $settings Form spam protection settings.
     * @return bool True if submission was too fast (likely bot).
     */
    public function is_too_fast( $form_id, array $settings = array() ) {
        $timestamp = isset( $_POST['_fre_timestamp'] ) ? (int) $_POST['_fre_timestamp'] : 0;

        if ( empty( $timestamp ) ) {
            // No timestamp provided, can't validate timing.
            return false;
        }

        $min_time    = isset( $settings['min_submission_time'] )
            ? (int) $settings['min_submission_time']
            : $this->default_min_time;

        $elapsed     = time() - $timestamp;

        // If submitted faster than minimum time, likely a bot.
        if ( $elapsed < $min_time ) {
            $this->log_spam_attempt( $form_id, 'timing', $elapsed );
            return true;
        }

        return false;
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
                __( 'Please wait a moment before submitting.', 'form-runtime-engine' )
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
        $timestamp = isset( $_POST['_fre_timestamp'] ) ? (int) $_POST['_fre_timestamp'] : 0;

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
        $timestamp = isset( $_POST['_fre_timestamp'] ) ? (int) $_POST['_fre_timestamp'] : 0;

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

        error_log( sprintf(
            'FRE Spam blocked: form=%s, reason=%s, elapsed=%ds, ip=%s',
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
        do_action( 'fre_spam_detected', $form_id, $reason, $ip );
    }
}
