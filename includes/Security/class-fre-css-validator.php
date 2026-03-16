<?php
/**
 * CSS Validator for Form Runtime Engine.
 *
 * Validates and sanitizes custom CSS to prevent malicious code injection.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * CSS validator class.
 */
class FRE_CSS_Validator {

    /**
     * Blocked dangerous patterns that can execute code.
     *
     * @var array
     */
    private static $blocked_patterns = array(
        'expression\s*\(',       // IE JavaScript execution via CSS expressions.
        'behavior\s*:',          // IE HTC files (HTML Components).
        '-moz-binding\s*:',      // Firefox XBL bindings.
        'javascript\s*:',        // JavaScript URLs in CSS.
        '@import',               // External stylesheet loading (potential data exfiltration).
        'data\s*:',              // Data URIs (can contain embedded scripts).
        'vbscript\s*:',          // VBScript URLs.
        '-o-link\s*:',           // Opera link property.
        '-o-link-source\s*:',    // Opera link source.
        'base64',                // Base64 encoded content (often used to hide malicious code).
    );

    /**
     * Validate CSS for security issues.
     *
     * @param string $css The CSS to validate.
     * @return true|WP_Error True if valid, WP_Error if invalid.
     */
    public static function validate( $css ) {
        if ( empty( $css ) ) {
            return true;
        }

        $css_lower = strtolower( $css );

        // Check for dangerous patterns.
        foreach ( self::$blocked_patterns as $pattern ) {
            if ( preg_match( '/' . $pattern . '/i', $css_lower ) ) {
                $readable_pattern = str_replace( array( '\s*', '\(' ), array( '', '(' ), $pattern );
                return new WP_Error(
                    'css_unsafe_pattern',
                    sprintf(
                        /* translators: %s: The blocked CSS pattern */
                        __( 'CSS contains potentially unsafe pattern: %s', 'form-runtime-engine' ),
                        $readable_pattern
                    )
                );
            }
        }

        // Check for balanced braces.
        $open_braces  = substr_count( $css, '{' );
        $close_braces = substr_count( $css, '}' );

        if ( $open_braces !== $close_braces ) {
            return new WP_Error(
                'css_unbalanced_braces',
                __( 'Invalid CSS syntax: unbalanced braces. Check that all { have matching }.', 'form-runtime-engine' )
            );
        }

        // Check for balanced parentheses (basic check).
        $open_parens  = substr_count( $css, '(' );
        $close_parens = substr_count( $css, ')' );

        if ( $open_parens !== $close_parens ) {
            return new WP_Error(
                'css_unbalanced_parens',
                __( 'Invalid CSS syntax: unbalanced parentheses. Check that all ( have matching ).', 'form-runtime-engine' )
            );
        }

        // Check for url() with suspicious protocols.
        if ( preg_match( '/url\s*\(\s*["\']?\s*(javascript|vbscript|data):/i', $css_lower ) ) {
            return new WP_Error(
                'css_unsafe_url',
                __( 'CSS contains unsafe URL protocol. Only http, https, and relative URLs are allowed.', 'form-runtime-engine' )
            );
        }

        return true;
    }

    /**
     * Sanitize CSS by removing potentially dangerous content.
     *
     * @param string $css The CSS to sanitize.
     * @return string Sanitized CSS.
     */
    public static function sanitize( $css ) {
        if ( empty( $css ) ) {
            return '';
        }

        // Remove HTML tags.
        $css = wp_strip_all_tags( $css );

        // Remove any null bytes.
        $css = str_replace( "\0", '', $css );

        // Remove dangerous patterns (case-insensitive).
        foreach ( self::$blocked_patterns as $pattern ) {
            $css = preg_replace( '/' . $pattern . '/i', '/* blocked */', $css );
        }

        // Remove url() with dangerous protocols.
        $css = preg_replace( '/url\s*\(\s*["\']?\s*(javascript|vbscript|data):[^)]*\)/i', 'url()', $css );

        // Remove HTML comments that might be used for injection.
        $css = preg_replace( '/<!--.*?-->/s', '', $css );

        // Remove any remaining script-like content.
        $css = preg_replace( '/<\/?script[^>]*>/i', '', $css );

        // Trim whitespace.
        $css = trim( $css );

        return $css;
    }

    /**
     * Get list of blocked patterns for documentation.
     *
     * @return array Human-readable list of blocked patterns.
     */
    public static function get_blocked_patterns() {
        return array(
            'expression()'     => 'IE JavaScript execution via CSS expressions',
            'behavior:'        => 'IE HTC files (HTML Components)',
            '-moz-binding:'    => 'Firefox XBL bindings',
            'javascript:'      => 'JavaScript URLs',
            '@import'          => 'External stylesheet loading',
            'data:'            => 'Data URIs',
            'vbscript:'        => 'VBScript URLs',
            'base64'           => 'Base64 encoded content',
        );
    }
}
