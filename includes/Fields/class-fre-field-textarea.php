<?php
/**
 * Textarea Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Textarea field type.
 */
class FRE_Field_Textarea extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'textarea';

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

        $attributes = array(
            'id'    => $this->get_id( $field, $form_id ),
            'name'  => $this->get_name( $field ),
            'class' => 'fre-field__input fre-field__textarea',
            'rows'  => isset( $field['rows'] ) ? (int) $field['rows'] : 5,
        );

        if ( ! empty( $field['placeholder'] ) ) {
            $attributes['placeholder'] = $field['placeholder'];
        }

        if ( ! empty( $field['required'] ) ) {
            $attributes['required']      = true;
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

        if ( isset( $field['cols'] ) ) {
            $attributes['cols'] = (int) $field['cols'];
        }

        $input = sprintf(
            '<textarea%s>%s</textarea>',
            $this->build_attributes( $attributes ),
            esc_textarea( $value )
        );

        return $this->render_wrapper( $field, $form_id, $input );
    }

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string
     */
    public function sanitize( $value, array $field ) {
        return sanitize_textarea_field( $value );
    }

    /**
     * Format value for display (with line breaks preserved).
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        return nl2br( esc_html( $value ) );
    }
}
