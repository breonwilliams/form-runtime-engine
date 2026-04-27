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
     * Resolve a stored field value to its human-readable display string.
     *
     * Single source of truth for the "value → label" translation that
     * select / radio / checkbox-with-options fields require. Returns
     * PLAIN TEXT — does NOT escape for HTML; callers in HTML contexts
     * must wrap the result in esc_html().
     *
     * Behavior by field type:
     * - select / radio / checkbox (with options): looks up the option
     *   label for the stored value. For multi-value (array) inputs,
     *   resolves each element and joins with ", ". Falls back to the
     *   raw value for orphan options (option was deleted/renamed after
     *   submission) so admins still see something rather than nothing.
     * - checkbox WITHOUT options: returns "Yes" / "No" (single toggle).
     * - All other types: returns the value as a string (arrays joined
     *   with ", ").
     *
     * Filterable via `fre_field_display_value` so site owners or
     * extensions can customize translation (e.g., localize labels,
     * inject icons, redact sensitive option values).
     *
     * @param mixed $value Raw stored value.
     * @param array $field Field configuration.
     * @return string Plain-text display value (NOT html-escaped).
     */
    public static function resolve_display_value( $value, array $field ) {
        $type    = isset( $field['type'] ) ? $field['type'] : 'text';
        $options = isset( $field['options'] ) ? $field['options'] : array();
        $result  = '';

        // Single checkbox (no options) renders as a Yes/No toggle.
        if ( $type === 'checkbox' && empty( $options ) ) {
            $result = ! empty( $value )
                ? __( 'Yes', 'form-runtime-engine' )
                : __( 'No', 'form-runtime-engine' );
        } elseif ( in_array( $type, array( 'select', 'radio', 'checkbox' ), true ) && ! empty( $options ) ) {
            // Build value → label map once for this lookup.
            $map = array();
            foreach ( $options as $option ) {
                if ( is_array( $option ) ) {
                    $option_value = isset( $option['value'] ) ? (string) $option['value'] : '';
                    $option_label = isset( $option['label'] ) ? (string) $option['label'] : $option_value;
                    $map[ $option_value ] = $option_label;
                } else {
                    $map[ (string) $option ] = (string) $option;
                }
            }

            // Multi-value (multi-select / checkbox group).
            if ( is_array( $value ) ) {
                $labels = array();
                foreach ( $value as $val ) {
                    $key      = (string) $val;
                    $labels[] = isset( $map[ $key ] ) ? $map[ $key ] : $key;
                }
                $result = implode( ', ', $labels );
            } else {
                $key    = (string) $value;
                $result = isset( $map[ $key ] ) ? $map[ $key ] : $key;
            }
        } elseif ( is_array( $value ) ) {
            // Generic array stringification for any other field type.
            $result = implode( ', ', array_map( 'strval', $value ) );
        } else {
            $result = (string) $value;
        }

        /**
         * Filter the resolved display value for a field.
         *
         * Extensions can use this to localize labels, redact sensitive
         * option values from notifications, or inject custom formatting.
         *
         * @param string $result The resolved display string.
         * @param mixed  $value  The raw stored value.
         * @param array  $field  Field configuration.
         */
        return (string) apply_filters( 'fre_field_display_value', $result, $value, $field );
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

        // Column layout class.
        if ( ! empty( $field['column'] ) ) {
            $column_class = $this->get_column_class( $field['column'] );
            if ( $column_class ) {
                $classes[] = $column_class;
            }
        }

        // Build data attributes.
        $data_attrs = sprintf( 'data-field-key="%s"', esc_attr( $field['key'] ) );

        // Conditional logic data attribute.
        if ( ! empty( $field['conditions'] ) ) {
            $data_attrs .= sprintf(
                ' data-conditions="%s"',
                esc_attr( wp_json_encode( $field['conditions'] ) )
            );
        }

        $html = sprintf(
            '<div class="%s" %s>',
            implode( ' ', $classes ),
            $data_attrs
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
     * Get CSS class for column width.
     *
     * @param string $column Column specification (e.g., '1/2', '1/3', '2/3').
     * @return string|null CSS class or null if invalid.
     */
    protected function get_column_class( $column ) {
        $valid_columns = array(
            '1/2' => 'fre-col--1-2',
            '1/3' => 'fre-col--1-3',
            '2/3' => 'fre-col--2-3',
            '1/4' => 'fre-col--1-4',
            '3/4' => 'fre-col--3-4',
        );

        return isset( $valid_columns[ $column ] ) ? $valid_columns[ $column ] : null;
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
