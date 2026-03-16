<?php
/**
 * Section Field Type for Form Runtime Engine.
 *
 * A container field for grouping related fields with a heading.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Section field type (container for grouping fields).
 */
class FRE_Field_Section extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'section';

    /**
     * Whether this field stores a value.
     *
     * @var bool
     */
    protected $stores_value = false;

    /**
     * Render the field HTML.
     *
     * Note: Sections are rendered by the renderer, not directly.
     * This method is used for standalone section rendering if needed.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current field value (unused).
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        // Sections are typically rendered by the renderer's section handling.
        // This provides a fallback for direct rendering.
        $classes = array( 'fre-section' );
        if ( ! empty( $field['css_class'] ) ) {
            $classes[] = esc_attr( $field['css_class'] );
        }

        $data_attrs = sprintf( 'data-section-key="%s"', esc_attr( $field['key'] ) );
        if ( ! empty( $field['conditions'] ) ) {
            $data_attrs .= sprintf(
                ' data-conditions="%s"',
                esc_attr( wp_json_encode( $field['conditions'] ) )
            );
        }

        $html = sprintf( '<div class="%s" %s>', implode( ' ', $classes ), $data_attrs );

        // Section title.
        if ( ! empty( $field['label'] ) ) {
            $html .= sprintf(
                '<h4 class="fre-section__title">%s</h4>',
                esc_html( $field['label'] )
            );
        }

        // Section description.
        if ( ! empty( $field['description'] ) ) {
            $html .= sprintf(
                '<p class="fre-section__description">%s</p>',
                esc_html( $field['description'] )
            );
        }

        // Opening for fields container (closed by renderer).
        $html .= '<div class="fre-section__fields">';

        return $html;
    }

    /**
     * Get the field's input name attribute.
     *
     * Section fields don't have inputs.
     *
     * @param array $field Field configuration.
     * @return string
     */
    public function get_name( array $field ) {
        return '';
    }

    /**
     * Validate - always passes since there's no input.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool
     */
    public function validate( $value, array $field, array $form ) {
        return true;
    }

    /**
     * Sanitize - nothing to sanitize.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string
     */
    public function sanitize( $value, array $field ) {
        return '';
    }

    /**
     * Format value for display.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        return '';
    }

    /**
     * Format value for CSV.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_csv_value( $value, array $field ) {
        return '';
    }
}
