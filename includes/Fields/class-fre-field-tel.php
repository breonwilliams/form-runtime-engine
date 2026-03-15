<?php
/**
 * Telephone Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Telephone input field type.
 */
class FRE_Field_Tel extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'tel';

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param string $value Current field value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id    = isset( $form['id'] ) ? $form['id'] : '';
        $attributes = $this->get_common_attributes( $field, $form_id, $value );

        $attributes['type']  = 'tel';
        $attributes['class'] = 'fre-field__input fre-field__input--tel';

        // Set pattern if not specified.
        if ( empty( $field['pattern'] ) && empty( $attributes['pattern'] ) ) {
            // Allow common phone formats: digits, spaces, dashes, parentheses, plus.
            $attributes['pattern'] = '[0-9+\\-\\s\\(\\)]+';
        } elseif ( ! empty( $field['pattern'] ) ) {
            $attributes['pattern'] = $field['pattern'];
        }

        $input = sprintf( '<input%s />', $this->build_attributes( $attributes ) );

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
        // Run parent validation (required, length).
        $parent_result = parent::validate( $value, $field, $form );
        if ( is_wp_error( $parent_result ) ) {
            return $parent_result;
        }

        // Basic phone format validation.
        if ( ! $this->is_empty( $value ) ) {
            // Remove allowed characters and check if remaining chars are valid.
            $cleaned = preg_replace( '/[0-9+\-\s\(\)]+/', '', $value );
            if ( ! empty( $cleaned ) ) {
                return new WP_Error(
                    'invalid_phone',
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s contains invalid characters.', 'form-runtime-engine' ),
                        $this->get_label( $field )
                    )
                );
            }

            // Must have at least some digits.
            $digits = preg_replace( '/[^0-9]/', '', $value );
            if ( strlen( $digits ) < 7 ) {
                return new WP_Error(
                    'invalid_phone',
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s must be a valid phone number.', 'form-runtime-engine' ),
                        $this->get_label( $field )
                    )
                );
            }
        }

        return true;
    }

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string
     */
    public function sanitize( $value, array $field ) {
        // Remove anything except digits, plus, spaces, dashes, parentheses.
        return preg_replace( '/[^0-9+\-\s\(\)]/', '', $value );
    }

    /**
     * Format value for display with tel link.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( empty( $value ) ) {
            return '';
        }

        // Create tel: link with digits only.
        $tel_digits = preg_replace( '/[^0-9+]/', '', $value );

        return sprintf(
            '<a href="tel:%s">%s</a>',
            esc_attr( $tel_digits ),
            esc_html( $value )
        );
    }
}
