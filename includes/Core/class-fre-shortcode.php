<?php
/**
 * Shortcode Handler for Promptless Forms.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shortcode handler class.
 *
 * Tag registration (1.6.5+):
 *   - `pforms_form`         — canonical tag (well-prefixed, documented).
 *   - `promptless_form`  — branding alias matching the WordPress.org name.
 *
 * Tag retired in 1.6.5:
 *   - `client_form` — flagged by WP.org reviewers because "client" is too
 *     generic a prefix. Sites that used `[client_form id="..."]` should
 *     migrate to `[pforms_form id="..."]`.
 */
class PForms_Shortcode {

    /**
     * Canonical shortcode tag.
     *
     * @var string
     */
    private $tag = 'pforms_form';

    /**
     * Constructor.
     */
    public function __construct() {
        // Canonical tag.
        add_shortcode( $this->tag, array( $this, 'render_shortcode' ) );

        // Branding alias — same handler.
        add_shortcode( 'promptless_form', array( $this, 'render_shortcode' ) );
    }

    /**
     * Render the shortcode.
     *
     * @param array  $atts    Shortcode attributes.
     * @param string $content Shortcode content (unused).
     * @return string Form HTML.
     */
    public function render_shortcode( $atts, $content = '' ) {
        $atts = shortcode_atts(
            array(
                'id'        => '',
                'form'      => '', // Alias for id.
                'class'     => '',
                'ajax'      => 'true',
            ),
            $atts,
            $this->tag
        );

        // Get form ID (support both 'id' and 'form' attributes).
        $form_id = ! empty( $atts['id'] ) ? $atts['id'] : $atts['form'];

        if ( empty( $form_id ) ) {
            return $this->render_admin_error(
                __( 'Form ID is required. Usage: [pforms_form id="contact"]', 'promptless-forms' )
            );
        }

        // Prepare render arguments.
        $args = array(
            'css_class' => sanitize_html_class( $atts['class'] ),
            'ajax'      => filter_var( $atts['ajax'], FILTER_VALIDATE_BOOLEAN ),
        );

        // Render form.
        $renderer = new PForms_Renderer();
        return $renderer->render( sanitize_key( $form_id ), $args );
    }

    /**
     * Render error message visible only to admins.
     *
     * @param string $message Error message.
     * @return string
     */
    private function render_admin_error( $message ) {
        if ( current_user_can( 'manage_options' ) ) {
            return sprintf(
                '<div class="fre-shortcode-error" style="background:#fef7f7;border:1px solid #f5c6cb;padding:10px;color:#721c24;">
                    <strong>%s</strong> %s
                </div>',
                esc_html__( 'Promptless Forms:', 'promptless-forms' ),
                esc_html( $message )
            );
        }

        return '';
    }

    /**
     * Get the shortcode tag.
     *
     * @return string
     */
    public function get_tag() {
        return $this->tag;
    }
}
