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
 */
class FRE_Honeypot {

    /**
     * Get honeypot field name for a form.
     *
     * @param string $form_id Form ID.
     * @return string Honeypot field name.
     */
    public function get_field_name( $form_id ) {
        return '_fre_website_url_' . substr( md5( $form_id ), 0, 8 );
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
     * Validate honeypot.
     *
     * @param string $form_id Form ID.
     * @return bool|WP_Error True if valid, WP_Error if spam detected.
     */
    public function validate( $form_id ) {
        if ( $this->is_triggered( $form_id ) ) {
            return new WP_Error(
                'honeypot_triggered',
                __( 'Spam detected.', 'form-runtime-engine' )
            );
        }

        return true;
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
