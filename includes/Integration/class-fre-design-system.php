<?php
/**
 * Design System Integration for Promptless Forms.
 *
 * Integrates with AI Section Builder Modern design system when available,
 * allowing forms to inherit brand styling (colors, typography, border radius).
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Design system integration class.
 */
class PForms_Design_System {

    /**
     * Constructor.
     */
    public function __construct() {
        // REGISTER the neo-brutalist stylesheet when AISB is active and the
        // setting is on; the renderer ENQUEUES it when a form renders. It used
        // to be enqueued here, on every page — and because it depends on
        // `pforms-frontend`, that dragged the 58 KB main stylesheet onto
        // every page of the site whether or not a form was on it (measured
        // 2026-09-13: 0% of it used on seven of eight demo pages).
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_register_neo_brutalist' ), 30 );
    }

    /**
     * Notification email accent when Promptless WP is not active (or has no
     * usable primary colour): the colour the template always used.
     */
    const EMAIL_ACCENT_FALLBACK = '#0073aa';

    /**
     * White header text and accent links on the white body must reach this
     * against the accent (WCAG 1.4.3, normal-size text).
     */
    const EMAIL_ACCENT_MIN_CONTRAST = 4.5;

    /**
     * Accent colour for the notification email (header band and links).
     *
     * Emails cannot read CSS custom properties, so the brand comes from the
     * stored Promptless WP settings at send time: its primary colour, darkened
     * toward black (hue kept) until white text on it, and it as link text on
     * white, reach 4.5:1. A yellow primary therefore becomes a deep olive, not
     * a band the white heading vanishes into. Without Promptless WP, or with
     * no valid primary, the email keeps #0073aa. Sparrowmere dry run
     * (2026-10): the band was WordPress blue on a moss-and-paper site.
     *
     * @since 1.12.3
     * @return string Lower-case #rrggbb.
     */
    public static function email_accent_color() {
        $color = self::EMAIL_ACCENT_FALLBACK;

        if ( defined( 'AISB_MODERN_VERSION' ) ) {
            $settings = get_option( 'aisb_global_settings', array() );
            $primary  = is_array( $settings ) && isset( $settings['primary_color'] )
                ? self::normalize_hex( $settings['primary_color'] )
                : null;
            if ( null !== $primary ) {
                $color = self::darken_until_contrast( $primary, self::EMAIL_ACCENT_MIN_CONTRAST );
            }
        }

        /**
         * Filter the notification email accent colour.
         *
         * Whatever is returned is used for the header band (white text) and
         * the links (on white); keep it at 4.5:1 against white.
         *
         * @since 1.12.3
         * @param string $color Lower-case #rrggbb.
         */
        $filtered = self::normalize_hex( apply_filters( 'pforms_email_accent_color', $color ) );

        return null !== $filtered ? $filtered : $color;
    }

    /**
     * Normalise a hex colour to lower-case #rrggbb, or null when invalid.
     *
     * @param mixed $value Candidate colour.
     * @return string|null
     */
    public static function normalize_hex( $value ) {
        if ( ! is_string( $value ) || ! preg_match( '/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim( $value ), $m ) ) {
            return null;
        }
        $hex = strtolower( $m[1] );
        if ( 3 === strlen( $hex ) ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return '#' . $hex;
    }

    /**
     * WCAG contrast ratio between a colour and white.
     *
     * @param string $hex #rrggbb.
     * @return float
     */
    public static function contrast_with_white( $hex ) {
        $lum = 0.0;
        foreach ( array( 0.2126, 0.7152, 0.0722 ) as $i => $weight ) {
            $c    = hexdec( substr( $hex, 1 + 2 * $i, 2 ) ) / 255;
            $c    = $c <= 0.03928 ? $c / 12.92 : pow( ( $c + 0.055 ) / 1.055, 2.4 );
            $lum += $weight * $c;
        }
        return 1.05 / ( $lum + 0.05 );
    }

    /**
     * Mix a colour toward black in small steps until white reaches $ratio
     * against it. Black always does (21:1), so this terminates.
     *
     * @param string $hex   #rrggbb.
     * @param float  $ratio Minimum contrast with white.
     * @return string #rrggbb
     */
    private static function darken_until_contrast( $hex, $ratio ) {
        $rgb = array(
            hexdec( substr( $hex, 1, 2 ) ),
            hexdec( substr( $hex, 3, 2 ) ),
            hexdec( substr( $hex, 5, 2 ) ),
        );

        for ( $step = 0; $step <= 20; $step++ ) {
            $keep      = 1 - $step / 20;
            $candidate = sprintf(
                '#%02x%02x%02x',
                (int) round( $rgb[0] * $keep ),
                (int) round( $rgb[1] * $keep ),
                (int) round( $rgb[2] * $keep )
            );
            if ( self::contrast_with_white( $candidate ) >= $ratio ) {
                return $candidate;
            }
        }

        return '#000000';
    }

    /**
     * Check if AI Section Builder Modern plugin is active.
     *
     * @return bool True if AISB is active.
     */
    public function is_plugin_active() {
        return defined( 'AISB_MODERN_VERSION' );
    }

    /**
     * Get AISB global settings.
     *
     * @return array Settings array with defaults.
     */
    public function get_aisb_settings() {
        if ( ! $this->is_plugin_active() ) {
            return array();
        }

        $defaults = array(
            'neo_brutalist_cards'   => 'off',
            'neo_brutalist_buttons' => 'off',
        );

        $settings = get_option( 'aisb_global_settings', array() );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Check if neo-brutalist mode is enabled.
     *
     * Handles both legacy boolean values and new string values ('outline', 'lifted').
     *
     * @return bool True if any neo-brutalist setting is enabled.
     */
    public function is_neo_brutalist_enabled() {
        $settings = $this->get_aisb_settings();

        $cards   = $settings['neo_brutalist_cards'] ?? 'off';
        $buttons = $settings['neo_brutalist_buttons'] ?? 'off';

        // Handle legacy boolean true and new string values ('outline', 'lifted').
        $cards_enabled   = ( $cards === true ) || ( is_string( $cards ) && $cards !== 'off' && $cards !== '' );
        $buttons_enabled = ( $buttons === true ) || ( is_string( $buttons ) && $buttons !== 'off' && $buttons !== '' );

        return $cards_enabled || $buttons_enabled;
    }

    /**
     * Get the neo-brutalist mode for cards.
     *
     * @return string 'off', 'outline', or 'lifted'.
     */
    public function get_neo_brutalist_cards_mode() {
        $settings = $this->get_aisb_settings();
        $value    = $settings['neo_brutalist_cards'] ?? 'off';

        // Handle legacy boolean - treat true as 'lifted'.
        if ( $value === true ) {
            return 'lifted';
        }

        return in_array( $value, array( 'outline', 'lifted' ), true ) ? $value : 'off';
    }

    /**
     * Get the neo-brutalist mode for buttons.
     *
     * @return string 'off', 'outline', or 'lifted'.
     */
    public function get_neo_brutalist_buttons_mode() {
        $settings = $this->get_aisb_settings();
        $value    = $settings['neo_brutalist_buttons'] ?? 'off';

        // Handle legacy boolean - treat true as 'lifted'.
        if ( $value === true ) {
            return 'lifted';
        }

        return in_array( $value, array( 'outline', 'lifted' ), true ) ? $value : 'off';
    }

    /**
     * Conditionally REGISTER the neo-brutalist stylesheet.
     *
     * Registered (not enqueued) when AISB is active and a neo-brutalist
     * setting is on — so "registered" is the signal the renderer reads:
     * PForms_Renderer::enqueue_assets() enqueues this handle beside
     * `pforms-frontend` only when a form actually renders. Pages without a
     * form ship neither stylesheet. The body classes
     * (aisb-neo-brutalist-cards, etc.) are added by AISB itself.
     *
     * Register-early / enqueue-at-render is the same contract Post Runtime's
     * render cache relies on: a cache hit re-enqueues the handles recorded
     * at render time, which now include this one.
     */
    public function maybe_register_neo_brutalist() {
        if ( ! $this->is_plugin_active() ) {
            return;
        }

        if ( ! $this->is_neo_brutalist_enabled() ) {
            return;
        }

        wp_register_style(
            'pforms-neo-brutalist',
            PForms_PLUGIN_URL . 'assets/css/neo-brutalist.css',
            array( 'pforms-frontend' ),
            PForms_VERSION
        );
        // Right-to-left locales load the rtlcss sibling (assets/css/*-rtl.css); see bin/build-rtl.sh.
        wp_style_add_data( 'pforms-neo-brutalist', 'rtl', 'replace' );
    }
}
