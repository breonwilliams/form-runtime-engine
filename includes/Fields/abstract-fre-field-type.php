<?php
/**
 * Abstract Field Type for Form Runtime Engine.
 *
 * Base class for all field types with common functionality.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Abstract base class for field types.
 */
abstract class FRE_Field_Type_Abstract implements FRE_Field_Type {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = '';

    /**
     * Whether this field stores a value.
     *
     * @var bool
     */
    protected $stores_value = true;

    /**
     * Whether this field handles file uploads.
     *
     * @var bool
     */
    protected $is_file_field = false;

    /**
     * Get the field type slug.
     *
     * @return string
     */
    public function get_type() {
        return $this->type;
    }

    /**
     * Get the field's input name attribute.
     *
     * @param array $field Field configuration.
     * @return string
     */
    public function get_name( array $field ) {
        return 'fre_field_' . sanitize_key( $field['key'] );
    }

    /**
     * Get the field's input ID attribute.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @return string
     */
    public function get_id( array $field, $form_id ) {
        return 'fre-' . sanitize_key( $form_id ) . '-' . sanitize_key( $field['key'] );
    }

    /**
     * Check if this field stores a value.
     *
     * @return bool
     */
    public function stores_value() {
        return $this->stores_value;
    }

    /**
     * Check if this field handles file uploads.
     *
     * @return bool
     */
    public function is_file_field() {
        return $this->is_file_field;
    }

    /**
     * Default validation - checks required field.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error
     */
    public function validate( $value, array $field, array $form ) {
        // Check required.
        if ( ! empty( $field['required'] ) && $this->is_empty( $value ) ) {
            return new WP_Error(
                'required_field',
                sprintf(
                    /* translators: %s: field label */
                    __( '%s is required.', 'form-runtime-engine' ),
                    $this->get_label( $field )
                )
            );
        }

        // Check max length if specified.
        if ( ! empty( $field['maxlength'] ) && is_string( $value ) ) {
            if ( mb_strlen( $value ) > (int) $field['maxlength'] ) {
                return new WP_Error(
                    'max_length_exceeded',
                    sprintf(
                        /* translators: %1$s: field label, %2$d: max length */
                        __( '%1$s must not exceed %2$d characters.', 'form-runtime-engine' ),
                        $this->get_label( $field ),
                        (int) $field['maxlength']
                    )
                );
            }
        }

        // Check min length if specified.
        if ( ! empty( $field['minlength'] ) && is_string( $value ) && ! $this->is_empty( $value ) ) {
            if ( mb_strlen( $value ) < (int) $field['minlength'] ) {
                return new WP_Error(
                    'min_length_not_met',
                    sprintf(
                        /* translators: %1$s: field label, %2$d: min length */
                        __( '%1$s must be at least %2$d characters.', 'form-runtime-engine' ),
                        $this->get_label( $field ),
                        (int) $field['minlength']
                    )
                );
            }
        }

        return true;
    }

    /**
     * Default sanitization - sanitize text field.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return mixed
     */
    public function sanitize( $value, array $field ) {
        if ( is_array( $value ) ) {
            return array_map( 'sanitize_text_field', $value );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Format value for display in admin.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( is_array( $value ) ) {
            return implode( ', ', array_map( 'esc_html', $value ) );
        }

        return esc_html( $value );
    }

    /**
     * Format value for CSV export.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_csv_value( $value, array $field ) {
        if ( is_array( $value ) ) {
            return implode( ', ', $value );
        }

        return (string) $value;
    }

    /**
     * Check if a value is empty.
     *
     * @param mixed $value Value to check.
     * @return bool
     */
    protected function is_empty( $value ) {
        if ( is_null( $value ) ) {
            return true;
        }

        if ( is_string( $value ) && trim( $value ) === '' ) {
            return true;
        }

        if ( is_array( $value ) && empty( $value ) ) {
            return true;
        }

        return false;
    }

    /**
     * Get field label for error messages.
     *
     * @param array $field Field configuration.
     * @return string
     */
    protected function get_label( array $field ) {
        if ( ! empty( $field['label'] ) ) {
            return $field['label'];
        }

        // Convert key to readable label.
        return ucfirst( str_replace( array( '_', '-' ), ' ', $field['key'] ) );
    }

    /**
     * Build HTML attributes string.
     *
     * @param array $attributes Key-value pairs.
     * @return string
     */
    protected function build_attributes( array $attributes ) {
        $html = '';

        foreach ( $attributes as $key => $value ) {
            if ( is_bool( $value ) ) {
                if ( $value ) {
                    $html .= ' ' . esc_attr( $key );
                }
            } elseif ( ! is_null( $value ) ) {
                $html .= sprintf( ' %s="%s"', esc_attr( $key ), esc_attr( $value ) );
            }
        }

        return $html;
    }

    /**
     * Render field wrapper.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @param string $input   Input HTML.
     * @return string
     */
    protected function render_wrapper( array $field, $form_id, $input ) {
        $classes = array( 'fre-field', 'fre-field--' . esc_attr( $this->type ) );

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

        // Label.
        if ( ! empty( $field['label'] ) ) {
            $html .= $this->render_label( $field, $form_id );
        }

        // Input.
        $html .= $input;

        // Description.
        if ( ! empty( $field['description'] ) ) {
            $html .= sprintf(
                '<p class="fre-field__description">%s</p>',
                esc_html( $field['description'] )
            );
        }

        // Error placeholder.
        $html .= '<div class="fre-field__error" role="alert" aria-live="polite"></div>';

        $html .= '</div>';

        return $html;
    }

    /**
     * Render field label.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @return string
     */
    protected function render_label( array $field, $form_id ) {
        $required_indicator = '';
        if ( ! empty( $field['required'] ) ) {
            $required_indicator = '<span class="fre-required" aria-hidden="true">*</span>';
        }

        return sprintf(
            '<label class="fre-field__label" for="%s">%s%s</label>',
            esc_attr( $this->get_id( $field, $form_id ) ),
            esc_html( $field['label'] ),
            $required_indicator
        );
    }

    /**
     * Get common input attributes.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @param string $value   Current value.
     * @return array
     */
    protected function get_common_attributes( array $field, $form_id, $value ) {
        $attributes = array(
            'type'  => $this->type,
            'id'    => $this->get_id( $field, $form_id ),
            'name'  => $this->get_name( $field ),
            'value' => $value,
        );

        if ( ! empty( $field['placeholder'] ) ) {
            $attributes['placeholder'] = $field['placeholder'];
        }

        if ( ! empty( $field['required'] ) ) {
            $attributes['required'] = true;
            $attributes['aria-required'] = 'true';
        }

        if ( ! empty( $field['maxlength'] ) ) {
            $attributes['maxlength'] = (int) $field['maxlength'];
        }

        if ( ! empty( $field['minlength'] ) ) {
            $attributes['minlength'] = (int) $field['minlength'];
        }

        if ( ! empty( $field['readonly'] ) ) {
            $attributes['readonly'] = true;
        }

        if ( ! empty( $field['disabled'] ) ) {
            $attributes['disabled'] = true;
        }

        if ( ! empty( $field['autocomplete'] ) ) {
            $attributes['autocomplete'] = $field['autocomplete'];
        }

        return $attributes;
    }
}
