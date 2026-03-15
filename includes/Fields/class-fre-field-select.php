<?php
/**
 * Select Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Select dropdown field type.
 */
class FRE_Field_Select extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'select';

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

        $attributes = array(
            'id'    => $this->get_id( $field, $form_id ),
            'name'  => $this->get_name( $field ),
            'class' => 'fre-field__input fre-field__select',
        );

        if ( ! empty( $field['required'] ) ) {
            $attributes['required']      = true;
            $attributes['aria-required'] = 'true';
        }

        if ( ! empty( $field['disabled'] ) ) {
            $attributes['disabled'] = true;
        }

        if ( ! empty( $field['multiple'] ) ) {
            $attributes['multiple'] = true;
            $attributes['name']    .= '[]';
        }

        // Build options HTML.
        $options_html = '';

        // Placeholder option.
        if ( ! empty( $field['placeholder'] ) ) {
            $options_html .= sprintf(
                '<option value="">%s</option>',
                esc_html( $field['placeholder'] )
            );
        }

        // Get options.
        $options = isset( $field['options'] ) ? $field['options'] : array();

        foreach ( $options as $option ) {
            $option_value = '';
            $option_label = '';

            // Handle both array format and key => value format.
            if ( is_array( $option ) ) {
                $option_value = isset( $option['value'] ) ? $option['value'] : '';
                $option_label = isset( $option['label'] ) ? $option['label'] : $option_value;
            } else {
                $option_value = $option;
                $option_label = $option;
            }

            // Check if selected.
            $selected = '';
            if ( is_array( $value ) ) {
                if ( in_array( $option_value, $value, true ) ) {
                    $selected = ' selected';
                }
            } elseif ( (string) $value === (string) $option_value ) {
                $selected = ' selected';
            }

            $options_html .= sprintf(
                '<option value="%s"%s>%s</option>',
                esc_attr( $option_value ),
                $selected,
                esc_html( $option_label )
            );
        }

        $input = sprintf(
            '<select%s>%s</select>',
            $this->build_attributes( $attributes ),
            $options_html
        );

        return $this->render_wrapper( $field, $form_id, $input );
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

        // Get valid option values.
        $valid_values = $this->get_valid_values( $field );

        // Validate selected value(s).
        $values_to_check = is_array( $value ) ? $value : array( $value );

        foreach ( $values_to_check as $val ) {
            if ( ! in_array( $val, $valid_values, true ) ) {
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

        return true;
    }

    /**
     * Get valid option values from field config.
     *
     * @param array $field Field configuration.
     * @return array Array of valid values.
     */
    private function get_valid_values( array $field ) {
        $valid  = array();
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
     * Format value for display (show label instead of value if available).
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( $this->is_empty( $value ) ) {
            return '';
        }

        $values  = is_array( $value ) ? $value : array( $value );
        $options = isset( $field['options'] ) ? $field['options'] : array();
        $labels  = array();

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
}
