<?php
/**
 * Email Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Email input field type.
 */
class FRE_Field_Email extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'email';

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

        $attributes['type']  = 'email';
        $attributes['class'] = 'fre-field__input fre-field__input--email';

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

        // Email format validation.
        if ( ! $this->is_empty( $value ) && ! is_email( $value ) ) {
            return new WP_Error(
                'invalid_email',
                sprintf(
                    /* translators: %s: field label */
                    __( '%s must be a valid email address.', 'form-runtime-engine' ),
                    $this->get_label( $field )
                )
            );
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
        return sanitize_email( $value );
    }

    /**
     * Format value for display with mailto link.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( empty( $value ) ) {
            return '';
        }

        return sprintf(
            '<a href="mailto:%1$s">%1$s</a>',
            esc_attr( $value )
        );
    }
}
