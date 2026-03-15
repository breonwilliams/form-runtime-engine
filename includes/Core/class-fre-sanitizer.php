<?php
/**
 * Sanitizer for Form Runtime Engine.
 *
 * Handles all form field sanitization.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form sanitization handler.
 */
class FRE_Sanitizer {

    /**
     * Field type instances cache.
     *
     * @var array
     */
    private $field_instances = array();

    /**
     * Sanitize all form fields.
     *
     * @param array $form_config Form configuration.
     * @param array $data        Raw submitted data.
     * @return array Sanitized data keyed by field key.
     */
    public function sanitize( array $form_config, array $data ) {
        $sanitized = array();

        foreach ( $form_config['fields'] as $field ) {
            $field_type = $this->get_field_instance( $field['type'] );

            if ( ! $field_type ) {
                continue;
            }

            // Skip non-storing fields.
            if ( ! $field_type->stores_value() ) {
                continue;
            }

            // Skip file fields (handled separately).
            if ( $field_type->is_file_field() ) {
                continue;
            }

            // Get submitted value.
            $name  = $field_type->get_name( $field );
            $value = isset( $data[ $name ] ) ? $data[ $name ] : '';

            // Sanitize field value.
            $sanitized_value = $field_type->sanitize( $value, $field );

            /**
             * Filter the sanitized value.
             *
             * @param mixed  $sanitized_value Sanitized value.
             * @param mixed  $value           Original value.
             * @param array  $field           Field configuration.
             * @param array  $form_config     Form configuration.
             */
            $sanitized_value = apply_filters(
                'fre_sanitized_value',
                $sanitized_value,
                $value,
                $field,
                $form_config
            );

            $sanitized[ $field['key'] ] = $sanitized_value;
        }

        return $sanitized;
    }

    /**
     * Sanitize a single field value.
     *
     * @param array $field Field configuration.
     * @param mixed $value Raw value.
     * @return mixed Sanitized value.
     */
    public function sanitize_field( array $field, $value ) {
        $field_type = $this->get_field_instance( $field['type'] );

        if ( ! $field_type ) {
            // Fallback to basic sanitization.
            return $this->sanitize_default( $value );
        }

        return $field_type->sanitize( $value, $field );
    }

    /**
     * Default sanitization for unknown field types.
     *
     * @param mixed $value Value to sanitize.
     * @return mixed
     */
    private function sanitize_default( $value ) {
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'sanitize_default' ), $value );
        }

        return sanitize_text_field( $value );
    }

    /**
     * Sanitize email header value.
     *
     * Removes newlines to prevent header injection.
     *
     * @param string $value Value to sanitize.
     * @return string
     */
    public function sanitize_email_header( $value ) {
        // Strip all newlines and carriage returns.
        $value = preg_replace( '/[\r\n]/', '', $value );

        // Remove any header injection attempts.
        $value = preg_replace( '/^(to|cc|bcc|from|reply-to):/i', '', $value );

        return sanitize_text_field( $value );
    }

    /**
     * Sanitize filename.
     *
     * @param string $filename Original filename.
     * @return string Sanitized filename.
     */
    public function sanitize_filename( $filename ) {
        // Remove null bytes.
        $filename = str_replace( chr( 0 ), '', $filename );

        // Use WordPress sanitization.
        $filename = sanitize_file_name( $filename );

        // Additional cleanup.
        $filename = preg_replace( '/[^a-zA-Z0-9._-]/', '', $filename );

        return $filename;
    }

    /**
     * Sanitize IP address.
     *
     * @param string $ip IP address.
     * @return string
     */
    public function sanitize_ip( $ip ) {
        $ip = sanitize_text_field( $ip );

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        return $ip;
    }

    /**
     * Sanitize URL.
     *
     * @param string $url URL to sanitize.
     * @return string
     */
    public function sanitize_url( $url ) {
        return esc_url_raw( $url );
    }

    /**
     * Deep sanitize array (recursive).
     *
     * @param array $data Data to sanitize.
     * @return array
     */
    public function deep_sanitize( array $data ) {
        $sanitized = array();

        foreach ( $data as $key => $value ) {
            $key = sanitize_key( $key );

            if ( is_array( $value ) ) {
                $sanitized[ $key ] = $this->deep_sanitize( $value );
            } elseif ( is_string( $value ) ) {
                $sanitized[ $key ] = sanitize_text_field( $value );
            } else {
                $sanitized[ $key ] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Strip all HTML tags from value.
     *
     * @param mixed $value Value to strip.
     * @return mixed
     */
    public function strip_tags( $value ) {
        if ( is_array( $value ) ) {
            return array_map( array( $this, 'strip_tags' ), $value );
        }

        return wp_strip_all_tags( $value );
    }

    /**
     * Get field type instance.
     *
     * @param string $type Field type slug.
     * @return FRE_Field_Type|null
     */
    private function get_field_instance( $type ) {
        if ( isset( $this->field_instances[ $type ] ) ) {
            return $this->field_instances[ $type ];
        }

        $class_name = FRE_Autoloader::get_field_class( $type );

        if ( ! $class_name || ! class_exists( $class_name ) ) {
            return null;
        }

        $this->field_instances[ $type ] = new $class_name();

        return $this->field_instances[ $type ];
    }
}
