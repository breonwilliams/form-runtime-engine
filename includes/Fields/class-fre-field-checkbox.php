<?php
/**
 * Checkbox Field Type for Form Runtime Engine.
 *
 * Supports both single checkbox and checkbox groups.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checkbox field type.
 */
class FRE_Field_Checkbox extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'checkbox';

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current field value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id = isset( $form['id'] ) ? $form['id'] : '';
        $options = isset( $field['options'] ) ? $field['options'] : array();

        // Single checkbox (no options defined).
        if ( empty( $options ) ) {
            return $this->render_single( $field, $value, $form_id );
        }

        // Multiple checkboxes (options defined).
        return $this->render_group( $field, $value, $form_id );
    }

    /**
     * Render single checkbox.
     *
     * @param array  $field   Field configuration.
     * @param mixed  $value   Current value.
     * @param string $form_id Form ID.
     * @return string
     */
    private function render_single( array $field, $value, $form_id ) {
        $id      = $this->get_id( $field, $form_id );
        $name    = $this->get_name( $field );
        $checked = ! empty( $value ) ? ' checked' : '';

        $classes = array( 'fre-field', 'fre-field--checkbox', 'fre-field--checkbox-single' );
        if ( ! empty( $field['required'] ) ) {
            $classes[] = 'fre-field--required';
        }
        if ( ! empty( $field['css_class'] ) ) {
            $classes[] = esc_attr( $field['css_class'] );
        }

        $html = sprintf(
            '<div class="%s" data-field-key="%s">',
            implode( ' ', $classes ),
            esc_attr( $field['key'] )
        );

        $html .= sprintf(
            '<label class="fre-field__checkbox-label" for="%s">',
            esc_attr( $id )
        );

        $html .= sprintf(
            '<input type="checkbox" id="%s" name="%s" value="1" class="fre-field__checkbox"%s%s />',
            esc_attr( $id ),
            esc_attr( $name ),
            $checked,
            ! empty( $field['required'] ) ? ' required aria-required="true"' : ''
        );

        if ( ! empty( $field['label'] ) ) {
            $required_indicator = '';
            if ( ! empty( $field['required'] ) ) {
                $required_indicator = '<span class="fre-required" aria-hidden="true">*</span>';
            }
            $html .= sprintf(
                '<span class="fre-field__checkbox-text">%s%s</span>',
                esc_html( $field['label'] ),
                $required_indicator
            );
        }

        $html .= '</label>';

        if ( ! empty( $field['description'] ) ) {
            $html .= sprintf(
                '<p class="fre-field__description">%s</p>',
                esc_html( $field['description'] )
            );
        }

        $html .= '<div class="fre-field__error" role="alert" aria-live="polite"></div>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render checkbox group.
     *
     * @param array  $field   Field configuration.
     * @param mixed  $value   Current value (array of selected values).
     * @param string $form_id Form ID.
     * @return string
     */
    private function render_group( array $field, $value, $form_id ) {
        $name    = $this->get_name( $field ) . '[]';
        $options = $field['options'];
        $values  = is_array( $value ) ? $value : array( $value );

        $fieldset_classes = array( 'fre-field__fieldset', 'fre-field__checkbox-group' );

        if ( ! empty( $field['inline'] ) ) {
            $fieldset_classes[] = 'fre-field__checkbox-group--inline';
        }

        $html = sprintf(
            '<fieldset class="%s" role="group"%s>',
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
            $checked   = in_array( (string) $option_value, array_map( 'strval', $values ), true ) ? ' checked' : '';

            $html .= sprintf(
                '<label class="fre-field__checkbox-label" for="%s">',
                esc_attr( $option_id )
            );

            $html .= sprintf(
                '<input type="checkbox" id="%s" name="%s" value="%s" class="fre-field__checkbox"%s />',
                esc_attr( $option_id ),
                esc_attr( $name ),
                esc_attr( $option_value ),
                $checked
            );

            $html .= sprintf(
                '<span class="fre-field__checkbox-text">%s</span>',
                esc_html( $option_label )
            );

            $html .= '</label>';
        }

        $html .= '</fieldset>';

        // Wrapper.
        $classes = array( 'fre-field', 'fre-field--checkbox', 'fre-field--checkbox-group' );
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
        $options = isset( $field['options'] ) ? $field['options'] : array();

        // Single checkbox validation.
        if ( empty( $options ) ) {
            if ( ! empty( $field['required'] ) && empty( $value ) ) {
                return new WP_Error(
                    'required_field',
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s must be checked.', 'form-runtime-engine' ),
                        $this->get_label( $field )
                    )
                );
            }
            return true;
        }

        // Checkbox group validation.
        if ( ! empty( $field['required'] ) && $this->is_empty( $value ) ) {
            return new WP_Error(
                'required_field',
                sprintf(
                    /* translators: %s: field label */
                    __( '%s requires at least one selection.', 'form-runtime-engine' ),
                    $this->get_label( $field )
                )
            );
        }

        // Validate against allowed options.
        if ( ! $this->is_empty( $value ) ) {
            $valid_values    = $this->get_valid_values( $field );
            $values_to_check = is_array( $value ) ? $value : array( $value );

            foreach ( $values_to_check as $val ) {
                if ( ! in_array( (string) $val, $valid_values, true ) ) {
                    return new WP_Error(
                        'invalid_option',
                        sprintf(
                            /* translators: %s: field label */
                            __( '%s has an invalid selection.', 'form-runtime-engine' ),
                            $this->get_label( $field )
                        )
                    );
                }
            }
        }

        return true;
    }

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return mixed
     */
    public function sanitize( $value, array $field ) {
        $options = isset( $field['options'] ) ? $field['options'] : array();

        // Single checkbox.
        if ( empty( $options ) ) {
            return ! empty( $value ) ? '1' : '';
        }

        // Checkbox group.
        if ( is_array( $value ) ) {
            return array_map( 'sanitize_text_field', $value );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Get valid option values from field config.
     *
     * @param array $field Field configuration.
     * @return array
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
     * Format value for display.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        $options = isset( $field['options'] ) ? $field['options'] : array();

        // Single checkbox.
        if ( empty( $options ) ) {
            return ! empty( $value )
                ? esc_html__( 'Yes', 'form-runtime-engine' )
                : esc_html__( 'No', 'form-runtime-engine' );
        }

        // Checkbox group.
        if ( $this->is_empty( $value ) ) {
            return '';
        }

        $values = is_array( $value ) ? $value : array( $value );
        $labels = array();

        foreach ( $values as $val ) {
            $found = false;
            foreach ( $options as $option ) {
                if ( is_array( $option ) ) {
                    if ( isset( $option['value'] ) && (string) $option['value'] === (string) $val ) {
                        $labels[] = isset( $option['label'] ) ? $option['label'] : $val;
                        $found    = true;
                        break;
                    }
                } elseif ( (string) $option === (string) $val ) {
                    $labels[] = $option;
                    $found    = true;
                    break;
                }
            }
            if ( ! $found ) {
                $labels[] = $val;
            }
        }

        return esc_html( implode( ', ', $labels ) );
    }

    /**
     * Format value for CSV.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_csv_value( $value, array $field ) {
        $options = isset( $field['options'] ) ? $field['options'] : array();

        // Single checkbox.
        if ( empty( $options ) ) {
            return ! empty( $value ) ? 'Yes' : 'No';
        }

        // Checkbox group.
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }

        return (string) $value;
    }
}
