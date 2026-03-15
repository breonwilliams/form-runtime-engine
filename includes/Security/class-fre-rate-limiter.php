<?php
/**
 * Rate Limiter for Form Runtime Engine.
 *
 * Prevents excessive form submissions from a single IP.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rate limiting spam protection.
 */
class FRE_Rate_Limiter {

    /**
     * Default maximum submissions.
     *
     * @var int
     */
    private $default_max = 5;

    /**
     * Default time window in seconds (1 hour).
     *
     * @var int
     */
    private $default_window = 3600;

    /**
     * Trusted proxy IPs (set via filter or constant).
     *
     * @var array
     */
    private $trusted_proxies = array();

    /**
     * Constructor.
     */
    public function __construct() {
        /**
         * Filter trusted proxy IP addresses.
         *
         * @param array $proxies Array of trusted proxy IPs.
         */
        $this->trusted_proxies = apply_filters( 'fre_trusted_proxies', array() );
    }

    /**
     * Check if rate limit is exceeded.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @return bool True if rate limit exceeded.
     */
    public function is_exceeded( $form_id, array $settings = array() ) {
        $ip  = $this->get_client_ip();
        $key = $this->get_cache_key( $form_id, $ip );

        $max    = isset( $settings['max'] ) ? (int) $settings['max'] : $this->default_max;
        $window = isset( $settings['window'] ) ? (int) $settings['window'] : $this->default_window;

        $current = get_transient( $key );

        if ( $current === false ) {
            // First submission in window.
            set_transient( $key, 1, $window );
            return false;
        }

        if ( (int) $current >= $max ) {
            $this->log_rate_limit_hit( $form_id, $ip, $current, $max );
            return true;
        }

        // Increment counter.
        set_transient( $key, (int) $current + 1, $window );

        return false;
    }

    /**
     * Validate rate limit.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @return bool|WP_Error True if valid, WP_Error if exceeded.
     */
    public function validate( $form_id, array $settings = array() ) {
        if ( $this->is_exceeded( $form_id, $settings ) ) {
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Too many submissions. Please try again later.', 'form-runtime-engine' )
            );
        }

        return true;
    }

    /**
     * Check global rate limit (across all IPs).
     *
     * Prevents high-volume attacks from distributed sources.
     *
     * @param string $form_id Form ID.
     * @return bool True if global rate limit exceeded.
     */
    public function is_global_exceeded( $form_id ) {
        $key = "fre_global_rate_{$form_id}";

        /**
         * Filter global rate limit per minute.
         *
         * @param int    $limit   Maximum submissions per minute.
         * @param string $form_id Form ID.
         */
        $max = apply_filters( 'fre_global_rate_limit', 30, $form_id );

        $current = get_transient( $key );

        if ( $current === false ) {
            set_transient( $key, 1, 60 );
            return false;
        }

        if ( (int) $current >= $max ) {
            error_log( sprintf(
                'FRE Global rate limit hit: form=%s, count=%d, max=%d',
                $form_id,
                $current,
                $max
            ) );
            return true;
        }

        set_transient( $key, (int) $current + 1, 60 );

        return false;
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    public function get_client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        // Only trust X-Forwarded-For if from trusted proxy.
        if ( $this->is_trusted_proxy( $ip ) ) {
            $forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] )
                ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) )
                : '';

            if ( ! empty( $forwarded ) ) {
                // Take first IP (original client).
                $ips = explode( ',', $forwarded );
                $ip  = trim( $ips[0] );
            }
        }

        // Validate IP format.
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            $ip = 'invalid';
        }

        return $ip;
    }

    /**
     * Check if IP is a trusted proxy.
     *
     * @param string $ip IP address.
     * @return bool
     */
    private function is_trusted_proxy( $ip ) {
        return in_array( $ip, $this->trusted_proxies, true );
    }

    /**
     * Get cache key for rate limiting.
     *
     * @param string $form_id Form ID.
     * @param string $ip      IP address.
     * @return string
     */
    private function get_cache_key( $form_id, $ip ) {
        return 'fre_rate_' . md5( $form_id . '_' . $ip );
    }

    /**
     * Get current submission count for IP.
     *
     * @param string $form_id Form ID.
     * @return int
     */
    public function get_current_count( $form_id ) {
        $ip  = $this->get_client_ip();
        $key = $this->get_cache_key( $form_id, $ip );

        $count = get_transient( $key );

        return $count !== false ? (int) $count : 0;
    }

    /**
     * Reset rate limit for an IP.
     *
     * @param string $form_id Form ID.
     * @param string $ip      IP address (defaults to current client IP).
     */
    public function reset( $form_id, $ip = '' ) {
        if ( empty( $ip ) ) {
            $ip = $this->get_client_ip();
        }

        $key = $this->get_cache_key( $form_id, $ip );
        delete_transient( $key );
    }

    /**
     * Log rate limit hit for monitoring.
     *
     * @param string $form_id Form ID.
     * @param string $ip      IP address.
     * @param int    $current Current count.
     * @param int    $max     Maximum allowed.
     */
    private function log_rate_limit_hit( $form_id, $ip, $current, $max ) {
        error_log( sprintf(
            'FRE Rate limit exceeded: form=%s, ip=%s, count=%d, max=%d',
            $form_id,
            $ip,
            $current,
            $max
        ) );

        /**
         * Fires when rate limit is exceeded.
         *
         * @param string $form_id Form ID.
         * @param string $ip      Client IP.
         * @param int    $current Current submission count.
         * @param int    $max     Maximum allowed.
         */
        do_action( 'fre_rate_limit_exceeded', $form_id, $ip, $current, $max );
    }

    /**
     * Get remaining submissions for current IP.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @return int
     */
    public function get_remaining( $form_id, array $settings = array() ) {
        $max     = isset( $settings['max'] ) ? (int) $settings['max'] : $this->default_max;
        $current = $this->get_current_count( $form_id );

        return max( 0, $max - $current );
    }
}
