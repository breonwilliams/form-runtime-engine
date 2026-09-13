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
