<?php
/**
 * Twilio webhook signature validator.
 *
 * Validates that incoming webhook requests actually originated from Twilio
 * by verifying the X-Twilio-Signature header using HMAC-SHA1.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Validates Twilio webhook request signatures.
 */
class FRE_Twilio_Validator {

    /**
     * Validate a Twilio webhook request.
     *
     * Implements Twilio's signature validation algorithm:
     * 1. Take the full request URL (including protocol and query string)
     * 2. Append all POST parameters, sorted alphabetically by key
     * 3. Compute HMAC-SHA1 hash using Auth Token as the key
     * 4. Base64-encode the hash
     * 5. Compare to X-Twilio-Signature header
     *
     * @param string $url        The full request URL.
     * @param array  $params     The POST parameters from the request.
     * @param string $signature  The X-Twilio-Signature header value.
     * @param string $auth_token The Twilio Auth Token.
     * @return bool True if the signature is valid.
     */
    public static function validate( $url, array $params, $signature, $auth_token ) {
        if ( empty( $url ) || empty( $signature ) || empty( $auth_token ) ) {
            return false;
        }

        // Sort parameters alphabetically by key.
        ksort( $params );

        // Build the data string: URL + concatenated key-value pairs.
        $data = $url;
        foreach ( $params as $key => $value ) {
            $data .= $key . $value;
        }

        // Compute expected signature.
        $expected = base64_encode( hash_hmac( 'sha1', $data, $auth_token, true ) );

        // Timing-safe comparison.
        return hash_equals( $expected, $signature );
    }

    /**
     * Validate the current request as a Twilio webhook.
     *
     * Convenience method that extracts URL, params, and signature from
     * the current request context.
     *
     * @param string $auth_token The Twilio Auth Token.
     * @return bool|WP_Error True if valid, WP_Error if validation fails.
     */
    public static function validate_current_request( $auth_token ) {
        // Get the signature header.
        $signature = isset( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_TWILIO_SIGNATURE'] ) )
            : '';

        if ( empty( $signature ) ) {
            FRE_Logger::error( 'Twilio Validator: Missing X-Twilio-Signature header.' );
            return new WP_Error(
                'missing_signature',
                __( 'Missing Twilio signature.', 'form-runtime-engine' ),
                array( 'status' => 403 )
            );
        }

        // Build the full request URL.
        $url = self::get_request_url();

        // Get POST parameters.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Twilio webhooks use signature validation, not nonces.
        $params = wp_unslash( $_POST );

        // Validate.
        $is_valid = self::validate( $url, $params, $signature, $auth_token );

        if ( ! $is_valid ) {
            FRE_Logger::error( 'Twilio Validator: Invalid signature for URL: ' . $url );
            return new WP_Error(
                'invalid_signature',
                __( 'Invalid Twilio signature.', 'form-runtime-engine' ),
                array( 'status' => 403 )
            );
        }

        return true;
    }

    /**
     * Get the full request URL for signature validation.
     *
     * Twilio requires the exact URL it sent the request to, including
     * protocol, host, path, and query string.
     *
     * @return string The full request URL.
     */
    private static function get_request_url() {
        $scheme = is_ssl() ? 'https' : 'http';

        // Use HTTP_HOST which includes port if non-standard.
        $host = isset( $_SERVER['HTTP_HOST'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) )
            : '';

        // Use REQUEST_URI which includes path and query string.
        $uri = isset( $_SERVER['REQUEST_URI'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) )
            : '';

        return $scheme . '://' . $host . $uri;
    }
}
