<?php
/**
 * Message Field Type for Form Runtime Engine.
 *
 * A display-only field for showing text or HTML content.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Message field type (display only, no input).
 */
class FRE_Field_Message extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'message';

    /**
     * Whether this field stores a value.
     *
     * @var bool
     */
    protected $stores_value = false;

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current field value (unused).
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id = isset( $form['id'] ) ? $form['id'] : '';
        $content = isset( $field['content'] ) ? $field['content'] : '';

        // Allow HTML but sanitize it.
        $allowed_html = array(
            'p'      => array( 'class' => array() ),
            'br'     => array(),
            'strong' => array(),
            'b'      => array(),
            'em'     => array(),
            'i'      => array(),
            'a'      => array(
                'href'   => array(),
                'target' => array(),
                'rel'    => array(),
                'class'  => array(),
            ),
            'ul'     => array( 'class' => array() ),
            'ol'     => array( 'class' => array() ),
            'li'     => array( 'class' => array() ),
            'span'   => array( 'class' => array() ),
            'div'    => array( 'class' => array() ),
            'h1'     => array( 'class' => array() ),
            'h2'     => array( 'class' => array() ),
            'h3'     => array( 'class' => array() ),
            'h4'     => array( 'class' => array() ),
            'h5'     => array( 'class' => array() ),
            'h6'     => array( 'class' => array() ),
        );

        $safe_content = wp_kses( $content, $allowed_html );

        // Build classes.
        $classes = array( 'fre-field', 'fre-field--message' );
        if ( ! empty( $field['css_class'] ) ) {
            $classes[] = esc_attr( $field['css_class'] );
        }

        // Message style variant.
        if ( ! empty( $field['style'] ) ) {
            $valid_styles = array( 'info', 'warning', 'success', 'error' );
            if ( in_array( $field['style'], $valid_styles, true ) ) {
                $classes[] = 'fre-field--message-' . esc_attr( $field['style'] );
            }
        }

        $html = sprintf(
            '<div class="%s" data-field-key="%s">',
            implode( ' ', $classes ),
            esc_attr( $field['key'] )
        );

        // Optional heading.
        if ( ! empty( $field['label'] ) ) {
            $html .= sprintf(
                '<h4 class="fre-field__message-heading">%s</h4>',
                esc_html( $field['label'] )
            );
        }

        $html .= sprintf(
            '<div class="fre-field__message-content">%s</div>',
            $safe_content
        );

        $html .= '</div>';

        return $html;
    }

    /**
     * Get the field's input name attribute.
     *
     * Message fields don't have inputs.
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
