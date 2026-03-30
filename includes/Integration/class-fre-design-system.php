<?php
/**
 * Design System Integration for Form Runtime Engine.
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
class FRE_Design_System {

    /**
     * Constructor.
     */
    public function __construct() {
        // Enqueue neo-brutalist styles if AISB is active and setting is enabled.
        add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_neo_brutalist' ), 30 );
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
            'neo_brutalist_cards'   => false,
            'neo_brutalist_buttons' => false,
        );

        $settings = get_option( 'aisb_global_settings', array() );

        return wp_parse_args( $settings, $defaults );
    }

    /**
     * Check if neo-brutalist mode is enabled.
     *
     * @return bool True if any neo-brutalist setting is enabled.
     */
    public function is_neo_brutalist_enabled() {
        $settings = $this->get_aisb_settings();

        return ! empty( $settings['neo_brutalist_cards'] ) || ! empty( $settings['neo_brutalist_buttons'] );
    }

    /**
     * Conditionally enqueue neo-brutalist styles.
     */
    public function maybe_enqueue_neo_brutalist() {
        if ( ! $this->is_plugin_active() ) {
            return;
        }

        if ( ! $this->is_neo_brutalist_enabled() ) {
            return;
        }

        wp_enqueue_style(
            'fre-neo-brutalist',
            FRE_PLUGIN_URL . 'assets/css/neo-brutalist.css',
            array( 'fre-frontend' ),
            FRE_VERSION
        );
    }
}
