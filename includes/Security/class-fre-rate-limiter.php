<?php
/**
 * Rate Limiter for Form Runtime Engine.
 *
 * Prevents excessive form submissions from a single IP.
 *
 * NOTE: Uses direct database queries for atomic rate limit operations.
 * Transactional queries prevent race conditions in concurrent submissions.
 * Caching is intentionally avoided to ensure accurate real-time counts.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
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
     * Whether to use object cache for rate limiting.
     *
     * @var bool
     */
    private $use_object_cache = false;

    /**
     * Cache group for rate limiting.
     *
     * @var string
     */
    private const CACHE_GROUP = 'fre_rate_limit';

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

        // Check if external object cache is available (Redis, Memcached, etc.).
        $this->use_object_cache = $this->has_external_object_cache();
    }

    /**
     * Check if external object cache is available.
     *
     * @return bool
     */
    private function has_external_object_cache() {
        // Check if wp_cache_add_global_groups exists (indicates external cache).
        if ( ! function_exists( 'wp_cache_add_global_groups' ) ) {
            return false;
        }

        // Check for common object cache drop-ins.
        if ( defined( 'WP_REDIS_DISABLED' ) && WP_REDIS_DISABLED ) {
            return false;
        }

        // Check if the cache supports atomic operations via wp_cache_incr.
        // Most external caches support this, but we verify the function exists.
        return function_exists( 'wp_cache_incr' );
    }

    /**
     * Maximum retry attempts for deadlock recovery.
     *
     * @var int
     */
    private const MAX_DEADLOCK_RETRIES = 2;

    /**
     * Check if rate limit is exceeded (Fix #10 & #2: Race condition protection).
     *
     * Uses object cache with atomic operations when available, falling back to
     * database-level locking to prevent race conditions where multiple
     * concurrent requests could bypass the rate limit.
     *
     * Fix #9: Added retry logic with exponential backoff for transient deadlocks.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @return bool True if rate limit exceeded.
     */
    public function is_exceeded( $form_id, array $settings = array() ) {
        // Use object cache if available for better performance.
        if ( $this->use_object_cache ) {
            return $this->is_exceeded_cache( $form_id, $settings );
        }

        // Fix #9: Retry with exponential backoff for deadlocks.
        $attempts = 0;

        while ( $attempts <= self::MAX_DEADLOCK_RETRIES ) {
            $result = $this->is_exceeded_internal( $form_id, $settings, $attempts );

            if ( $result !== 'deadlock' ) {
                return $result;
            }

            $attempts++;

            if ( $attempts <= self::MAX_DEADLOCK_RETRIES ) {
                // Exponential backoff: 50ms, 100ms.
                $delay = 50000 * pow( 2, $attempts - 1 );
                usleep( $delay );
                FRE_Logger::warning( "Rate Limiter: Deadlock detected, retry attempt {$attempts}" );
            }
        }

        // Max retries exhausted - fail closed to enforce rate limits under load.
        FRE_Logger::error( 'Rate Limiter: Max deadlock retries exceeded, failing closed' );
        return true;
    }

    /**
     * Check rate limit using object cache with atomic increment.
     *
     * Performance optimization: Uses wp_cache_incr for atomic operations,
     * avoiding database locking overhead.
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @return bool True if rate limit exceeded.
     */
    private function is_exceeded_cache( $form_id, array $settings ) {
        $ip  = $this->get_client_ip();
        $key = $this->get_cache_key( $form_id, $ip );

        // Apply bounds to rate limit settings.
        $max    = max( 1, min( 100, isset( $settings['max'] ) ? (int) $settings['max'] : $this->default_max ) );
        $window = max( 60, min( 86400, isset( $settings['window'] ) ? (int) $settings['window'] : $this->default_window ) );

        // Try to get current count.
        $current = wp_cache_get( $key, self::CACHE_GROUP );

        if ( $current === false ) {
            // Key doesn't exist - add it with initial value of 1.
            $added = wp_cache_add( $key, 1, self::CACHE_GROUP, $window );

            if ( $added ) {
                return false; // First submission.
            }

            // Another request added it - get current value.
            $current = wp_cache_get( $key, self::CACHE_GROUP );
        }

        // Check if already at limit.
        if ( (int) $current >= $max ) {
            $this->log_rate_limit_hit( $form_id, $ip, $current, $max );
            return true;
        }

        // Atomic increment.
        $new_count = wp_cache_incr( $key, 1, self::CACHE_GROUP );

        // Check if increment pushed us over the limit.
        if ( $new_count > $max ) {
            $this->log_rate_limit_hit( $form_id, $ip, $new_count, $max );
            return true;
        }

        return false;
    }

    /**
     * Internal rate limit check (Fix #9: Separated for retry logic).
     *
     * @param string $form_id  Form ID.
     * @param array  $settings Rate limit settings.
     * @param int    $attempt  Current attempt number.
     * @return bool|string True if exceeded, false if not, 'deadlock' if deadlock detected.
     */
    private function is_exceeded_internal( $form_id, array $settings, $attempt ) {
        global $wpdb;

        $ip  = $this->get_client_ip();
        $key = $this->get_cache_key( $form_id, $ip );

        // Fix #25: Apply bounds to rate limit settings.
        $max    = max( 1, min( 100, isset( $settings['max'] ) ? (int) $settings['max'] : $this->default_max ) );
        $window = max( 60, min( 86400, isset( $settings['window'] ) ? (int) $settings['window'] : $this->default_window ) );

        $option_name = '_transient_' . $key;
        $timeout_name = '_transient_timeout_' . $key;

        // Fix #2: Use transaction with row-level locking to prevent race condition.
        $wpdb->query( 'START TRANSACTION' );

        try {
            // Try to lock the existing row.
            $current = $wpdb->get_var( $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s FOR UPDATE",
                $option_name
            ) );

            // Fix #9: Check for deadlock in last error.
            if ( $this->is_deadlock_error( $wpdb->last_error ) ) {
                $wpdb->query( 'ROLLBACK' );
                return 'deadlock';
            }

            if ( $current === null ) {
                // Row doesn't exist - create it atomically using INSERT ... ON DUPLICATE KEY.
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                     VALUES (%s, '1', 'no')
                     ON DUPLICATE KEY UPDATE option_value = option_value + 1",
                    $option_name
                ) );

                if ( $this->is_deadlock_error( $wpdb->last_error ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    return 'deadlock';
                }

                // Set timeout.
                $wpdb->query( $wpdb->prepare(
                    "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
                     VALUES (%s, %d, 'no')
                     ON DUPLICATE KEY UPDATE option_value = %d",
                    $timeout_name,
                    time() + $window,
                    time() + $window
                ) );

                $wpdb->query( 'COMMIT' );
                return false;
            }

            // Check if limit already reached.
            if ( (int) $current >= $max ) {
                $wpdb->query( 'COMMIT' );
                $this->log_rate_limit_hit( $form_id, $ip, $current, $max );
                return true;
            }

            // Increment counter.
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$wpdb->options} SET option_value = option_value + 1 WHERE option_name = %s",
                $option_name
            ) );

            if ( $this->is_deadlock_error( $wpdb->last_error ) ) {
                $wpdb->query( 'ROLLBACK' );
                return 'deadlock';
            }

            $wpdb->query( 'COMMIT' );
            return false;

        } catch ( Exception $e ) {
            $wpdb->query( 'ROLLBACK' );
            FRE_Logger::error( 'Rate Limiter Error: ' . $e->getMessage() );
            return false; // Fail open on error.
        }
    }

    /**
     * Check if error is a deadlock (Fix #9).
     *
     * @param string $error Error message.
     * @return bool True if deadlock detected.
     */
    private function is_deadlock_error( $error ) {
        if ( empty( $error ) ) {
            return false;
        }

        // MySQL deadlock error codes: 1213 (deadlock found), 1205 (lock wait timeout).
        return preg_match( '/Deadlock found|Lock wait timeout|1213|1205/i', $error ) === 1;
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
     * Uses atomic operations to prevent race conditions.
     *
     * @param string $form_id Form ID.
     * @return bool True if global rate limit exceeded.
     */
    public function is_global_exceeded( $form_id ) {
        /**
         * Filter global rate limit per minute.
         *
         * @param int    $limit   Maximum submissions per minute.
         * @param string $form_id Form ID.
         */
        $max = apply_filters( 'fre_global_rate_limit', 30, $form_id );

        // Use object cache with atomic increment if available.
        if ( $this->use_object_cache ) {
            return $this->is_global_exceeded_cache( $form_id, $max );
        }

        // Fallback to database-based atomic increment.
        return $this->is_global_exceeded_db( $form_id, $max );
    }

    /**
     * Check global rate limit using object cache.
     *
     * @param string $form_id Form ID.
     * @param int    $max     Maximum submissions per minute.
     * @return bool True if exceeded.
     */
    private function is_global_exceeded_cache( $form_id, $max ) {
        $key = 'global_' . sanitize_key( $form_id );

        $current = wp_cache_get( $key, self::CACHE_GROUP );

        if ( $current === false ) {
            // Add with initial value.
            $added = wp_cache_add( $key, 1, self::CACHE_GROUP, 60 );
            if ( $added ) {
                return false;
            }
            $current = wp_cache_get( $key, self::CACHE_GROUP );
        }

        if ( (int) $current >= $max ) {
            FRE_Logger::warning( sprintf(
                'Global rate limit hit: form=%s, count=%d, max=%d',
                $form_id,
                $current,
                $max
            ) );
            return true;
        }

        // Atomic increment.
        wp_cache_incr( $key, 1, self::CACHE_GROUP );

        return false;
    }

    /**
     * Check global rate limit using database with atomic increment.
     *
     * @param string $form_id Form ID.
     * @param int    $max     Maximum submissions per minute.
     * @return bool True if exceeded.
     */
    private function is_global_exceeded_db( $form_id, $max ) {
        global $wpdb;

        $option_name  = '_transient_fre_global_rate_' . sanitize_key( $form_id );
        $timeout_name = '_transient_timeout_fre_global_rate_' . sanitize_key( $form_id );

        // Atomic increment using INSERT ... ON DUPLICATE KEY UPDATE.
        $result = $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, 1, 'no')
             ON DUPLICATE KEY UPDATE option_value = option_value + 1",
            $option_name
        ) );

        if ( $result === false ) {
            return false; // Fail open on error.
        }

        // Get current count after increment.
        $current = $wpdb->get_var( $wpdb->prepare(
            "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
            $option_name
        ) );

        // Ensure timeout exists/is updated.
        $expiry = time() + 60;
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$wpdb->options} (option_name, option_value, autoload)
             VALUES (%s, %d, 'no')
             ON DUPLICATE KEY UPDATE option_value = CASE
                 WHEN option_value < %d THEN %d
                 ELSE option_value
             END",
            $timeout_name,
            $expiry,
            $expiry,
            $expiry
        ) );

        if ( (int) $current > $max ) {
            FRE_Logger::warning( sprintf(
                'Global rate limit hit: form=%s, count=%d, max=%d',
                $form_id,
                $current,
                $max
            ) );
            return true;
        }

        return false;
    }

    /**
     * Check global IP rate limit across all forms (Fix #12).
     *
     * Prevents a single IP from submitting too many forms across the site.
     *
     * @param int $max    Maximum submissions per hour (default: 20).
     * @param int $window Time window in seconds (default: 3600).
     * @return bool True if global IP rate limit exceeded.
     */
    public function is_global_ip_exceeded( $max = 20, $window = 3600 ) {
        $ip  = $this->get_client_ip();
        $key = 'fre_global_ip_' . md5( $ip );

        $current = get_transient( $key );

        if ( $current === false ) {
            set_transient( $key, 1, $window );
            return false;
        }

        if ( (int) $current >= $max ) {
            FRE_Logger::warning( sprintf(
                'Global IP rate limit exceeded: ip=%s, count=%d, max=%d',
                $ip,
                $current,
                $max
            ) );

            /**
             * Fires when global IP rate limit is exceeded.
             *
             * @param string $ip      Client IP.
             * @param int    $current Current submission count.
             * @param int    $max     Maximum allowed.
             */
            do_action( 'fre_global_ip_rate_exceeded', $ip, $current, $max );

            return true;
        }

        set_transient( $key, (int) $current + 1, $window );

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
        FRE_Logger::warning( sprintf(
            'Rate limit exceeded: form=%s, ip=%s, count=%d, max=%d',
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
