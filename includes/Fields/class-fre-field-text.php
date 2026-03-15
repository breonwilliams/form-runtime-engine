<?php
/**
 * Text Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Text input field type.
 */
class FRE_Field_Text extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'text';

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

        $attributes['class'] = 'fre-field__input fre-field__input--text';

        // Pattern validation.
        if ( ! empty( $field['pattern'] ) ) {
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

        // Pattern validation.
        if ( ! empty( $field['pattern'] ) && ! $this->is_empty( $value ) ) {
            if ( ! preg_match( '/' . $field['pattern'] . '/', $value ) ) {
                return new WP_Error(
                    'pattern_mismatch',
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s format is invalid.', 'form-runtime-engine' ),
                        $this->get_label( $field )
                    )
                );
            }
        }

        return true;
    }
}
