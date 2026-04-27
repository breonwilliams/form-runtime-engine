<?php
/**
 * Radio Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Radio button group field type.
 */
class FRE_Field_Radio extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'radio';

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param string $value Current field value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id = isset( $form['id'] ) ? $form['id'] : '';
        $name    = $this->get_name( $field );
        $options = isset( $field['options'] ) ? $field['options'] : array();

        $fieldset_classes = array( 'fre-field__fieldset', 'fre-field__radio-group' );

        if ( ! empty( $field['inline'] ) ) {
            $fieldset_classes[] = 'fre-field__radio-group--inline';
        }

        $html = sprintf(
            '<fieldset class="%s" role="radiogroup"%s>',
            implode( ' ', $fieldset_classes ),
            ! empty( $field['required'] ) ? ' aria-required="true"' : ''
        );

        // Legend for accessibility.
        if ( ! empty( $field['label'] ) ) {
            $required_indicator = '';
            if ( ! empty( $field['required'] ) ) {
                $required_indicator = '<span class="fre-required" aria-hidden="true">*</span>';
            }
            $html .= sprintf(
                '<legend class="fre-field__legend">%s%s</legend>',
                esc_html( $field['label'] ),
                $required_indicator
            );
        }

        // Render each option.
        foreach ( $options as $index => $option ) {
            $option_value = '';
            $option_label = '';

            if ( is_array( $option ) ) {
                $option_value = isset( $option['value'] ) ? $option['value'] : '';
                $option_label = isset( $option['label'] ) ? $option['label'] : $option_value;
            } else {
                $option_value = $option;
                $option_label = $option;
            }

            $option_id = $this->get_id( $field, $form_id ) . '-' . $index;
            $checked   = (string) $value === (string) $option_value ? ' checked' : '';

            $html .= sprintf(
                '<label class="fre-field__radio-label" for="%s">',
                esc_attr( $option_id )
            );

            $html .= sprintf(
                '<input type="radio" id="%s" name="%s" value="%s" class="fre-field__radio"%s%s />',
                esc_attr( $option_id ),
                esc_attr( $name ),
                esc_attr( $option_value ),
                $checked,
                ! empty( $field['required'] ) ? ' required' : ''
            );

            $html .= sprintf(
                '<span class="fre-field__radio-text">%s</span>',
                esc_html( $option_label )
            );

            $html .= '</label>';
        }

        $html .= '</fieldset>';

        // Wrap without the label (legend is used instead).
        $classes = array( 'fre-field', 'fre-field--' . esc_attr( $this->type ) );
        if ( ! empty( $field['required'] ) ) {
            $classes[] = 'fre-field--required';
        }
        if ( ! empty( $field['css_class'] ) ) {
            $classes[] = esc_attr( $field['css_class'] );
        }

        $wrapper = sprintf(
            '<div class="%s" data-field-key="%s">',
            implode( ' ', $classes ),
            esc_attr( $field['key'] )
        );

        $wrapper .= $html;

        if ( ! empty( $field['description'] ) ) {
            $wrapper .= sprintf(
                '<p class="fre-field__description">%s</p>',
                esc_html( $field['description'] )
            );
        }

        $wrapper .= '<div class="fre-field__error" role="alert" aria-live="polite"></div>';
        $wrapper .= '</div>';

        return $wrapper;
    }

    /**
     * Validate the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error
     */
    public function validate( $value, array $field, array $form ) {
        // Run parent validation (required).
        $parent_result = parent::validate( $value, $field, $form );
        if ( is_wp_error( $parent_result ) ) {
            return $parent_result;
        }

        // Skip validation if empty and not required.
        if ( $this->is_empty( $value ) ) {
            return true;
        }

        // Validate against allowed options.
        $valid_values = $this->get_valid_values( $field );

        if ( ! in_array( (string) $value, $valid_values, true ) ) {
            return new WP_Error(
                'invalid_option',
                sprintf(
                    /* translators: %s: field label */
                    __( '%s has an invalid selection.', 'form-runtime-engine' ),
                    $this->get_label( $field )
                )
            );
        }

        return true;
    }

    /**
     * Get valid option values from field config.
     *
     * @param array $field Field configuration.
     * @return array Array of valid values.
     */
    private function get_valid_values( array $field ) {
        $valid   = array();
        $options = isset( $field['options'] ) ? $field['options'] : array();

        foreach ( $options as $option ) {
            if ( is_array( $option ) ) {
                $valid[] = isset( $option['value'] ) ? (string) $option['value'] : '';
            } else {
                $valid[] = (string) $option;
            }
        }

        return $valid;
    }

    /**
     * Format value for display (show label).
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( $this->is_empty( $value ) ) {
            return '';
        }

        // Delegate to the central resolver so admin views, emails, and
        // exports all share one source of truth for value → label.
        return esc_html( self::resolve_display_value( $value, $field ) );
    }

    /**
     * Format value for CSV export (label, not raw value).
     *
     * Mirrors select / checkbox-group behavior so CSV columns are
     * human-readable.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_csv_value( $value, array $field ) {
        if ( $this->is_empty( $value ) ) {
            return '';
        }

        return self::resolve_display_value( $value, $field );
    }
}
