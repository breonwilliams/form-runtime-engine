<?php
/**
 * Hidden Field Type for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Hidden input field type.
 */
class FRE_Field_Hidden extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'hidden';

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

        // Use default value if set and no current value.
        if ( empty( $value ) && isset( $field['default'] ) ) {
            $value = $this->parse_dynamic_value( $field['default'] );
        }

        $attributes = array(
            'type'  => 'hidden',
            'id'    => $this->get_id( $field, $form_id ),
            'name'  => $this->get_name( $field ),
            'value' => $value,
        );

        // Hidden fields don't need a wrapper.
        return sprintf( '<input%s />', $this->build_attributes( $attributes ) );
    }

    /**
     * Parse dynamic values in hidden field defaults.
     *
     * Supports: {user_id}, {user_email}, {user_name}, {post_id}, {page_url}, {referrer}
     *
     * @param string $value Value to parse.
     * @return string Parsed value.
     */
    private function parse_dynamic_value( $value ) {
        if ( empty( $value ) || strpos( $value, '{' ) === false ) {
            return $value;
        }

        $replacements = array(
            '{user_id}'    => is_user_logged_in() ? get_current_user_id() : '',
            '{user_email}' => is_user_logged_in() ? wp_get_current_user()->user_email : '',
            '{user_name}'  => is_user_logged_in() ? wp_get_current_user()->display_name : '',
            '{post_id}'    => get_the_ID() ?: '',
            '{page_url}'   => $this->get_current_url(),
            '{referrer}'   => isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '',
            '{timestamp}'  => current_time( 'timestamp' ),
            '{date}'       => current_time( 'Y-m-d' ),
        );

        /**
         * Filter dynamic value replacements for hidden fields.
         *
         * @param array $replacements Key-value pairs of replacements.
         */
        $replacements = apply_filters( 'fre_hidden_field_replacements', $replacements );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $value );
    }

    /**
     * Get current page URL.
     *
     * @return string
     */
    private function get_current_url() {
        $protocol = is_ssl() ? 'https://' : 'http://';
        $host     = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
        $uri      = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';

        return $protocol . $host . $uri;
    }

    /**
     * Validate hidden field.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error
     */
    public function validate( $value, array $field, array $form ) {
        // Hidden fields typically don't require validation,
        // but can enforce expected values if configured.
        if ( isset( $field['expected_value'] ) && (string) $value !== (string) $field['expected_value'] ) {
            return new WP_Error(
                'invalid_value',
                __( 'Invalid form data.', 'form-runtime-engine' )
            );
        }

        return true;
    }
}
