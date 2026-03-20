<?php
/**
 * Webhook URL Validator for Form Runtime Engine.
 *
 * Validates webhook URLs for security including SSRF protection.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook URL validator class.
 */
class FRE_Webhook_Validator {

    /**
     * Private IP ranges to block (SSRF protection).
     *
     * @var array
     */
    private static $private_ranges = array(
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '127.0.0.0/8',
        '169.254.0.0/16',
        '0.0.0.0/8',
        '100.64.0.0/10',
        '192.0.0.0/24',
        '192.0.2.0/24',
        '198.18.0.0/15',
        '198.51.100.0/24',
        '203.0.113.0/24',
        '224.0.0.0/4',
        '240.0.0.0/4',
        '255.255.255.255/32',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    );

    /**
     * Blocked hostnames.
     *
     * @var array
     */
    private static $blocked_hosts = array(
        'localhost',
        'localhost.localdomain',
        '0.0.0.0',
        '127.0.0.1',
        '::1',
    );

    /**
     * Validate a webhook URL.
     *
     * @param string $url URL to validate.
     * @return true|WP_Error True if valid, WP_Error on failure.
     */
    public static function validate( $url ) {
        // Check if URL is empty.
        if ( empty( $url ) ) {
            return new WP_Error( 'empty_url', __( 'Webhook URL is required.', 'form-runtime-engine' ) );
        }

        $url = trim( $url );

        // Validate URL format.
        if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
            return new WP_Error( 'invalid_url', __( 'Invalid webhook URL format.', 'form-runtime-engine' ) );
        }

        // Parse URL.
        $parsed = wp_parse_url( $url );

        if ( ! $parsed || empty( $parsed['host'] ) ) {
            return new WP_Error( 'invalid_url', __( 'Could not parse webhook URL.', 'form-runtime-engine' ) );
        }

        // Check scheme (only http/https allowed).
        $scheme = isset( $parsed['scheme'] ) ? strtolower( $parsed['scheme'] ) : '';
        if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
            return new WP_Error( 'invalid_scheme', __( 'Webhook URL must use http:// or https://.', 'form-runtime-engine' ) );
        }

        $host = strtolower( $parsed['host'] );

        // Check blocked hostnames.
        if ( in_array( $host, self::$blocked_hosts, true ) ) {
            return new WP_Error( 'blocked_host', __( 'Webhook URL cannot point to localhost or loopback addresses.', 'form-runtime-engine' ) );
        }

        // Resolve hostname to IP.
        $ip = gethostbyname( $host );

        // If gethostbyname returns the hostname, resolution failed.
        if ( $ip === $host && ! filter_var( $host, FILTER_VALIDATE_IP ) ) {
            // For security, we proceed but log a warning.
            // The webhook will fail at send time if DNS doesn't resolve.
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( 'FRE Webhook: Could not resolve hostname: ' . $host );
            }
        } else {
            // Check if IP is in private ranges.
            if ( self::is_private_ip( $ip ) ) {
                return new WP_Error(
                    'private_ip',
                    __( 'Webhook URL cannot point to private or internal IP addresses.', 'form-runtime-engine' )
                );
            }
        }

        return true;
    }

    /**
     * Check if an IP address is in private/reserved ranges.
     *
     * @param string $ip IP address to check.
     * @return bool True if private/reserved.
     */
    public static function is_private_ip( $ip ) {
        // Use filter_var for basic private/reserved check.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) === false ) {
            return true;
        }

        // Additional check for IPv6 addresses.
        if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
            $ip_lower = strtolower( $ip );

            // Check for loopback.
            if ( $ip_lower === '::1' ) {
                return true;
            }

            // Check for link-local.
            if ( strpos( $ip_lower, 'fe80:' ) === 0 ) {
                return true;
            }

            // Check for unique local (fc00::/7).
            if ( strpos( $ip_lower, 'fc' ) === 0 || strpos( $ip_lower, 'fd' ) === 0 ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Sanitize a webhook URL for storage.
     *
     * @param string $url URL to sanitize.
     * @return string Sanitized URL.
     */
    public static function sanitize( $url ) {
        $url = trim( $url );
        $url = esc_url_raw( $url, array( 'http', 'https' ) );

        return $url;
    }

    /**
     * Validate and sanitize a webhook URL.
     *
     * Combines validation and sanitization in one call.
     *
     * @param string $url URL to process.
     * @return string|WP_Error Sanitized URL on success, WP_Error on failure.
     */
    public static function validate_and_sanitize( $url ) {
        $result = self::validate( $url );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return self::sanitize( $url );
    }
}
