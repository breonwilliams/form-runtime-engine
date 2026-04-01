<?php
/**
 * File Field Type for Form Runtime Engine.
 *
 * NOTE: Called from submission handler after nonce verification.
 * $_FILES access is safe as the caller has already verified the nonce.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File upload field type.
 */
class FRE_Field_File extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'file';

    /**
     * Whether this field handles file uploads.
     *
     * @var bool
     */
    protected $is_file_field = true;

    /**
     * Default allowed file types.
     *
     * @var array
     */
    private $default_allowed_types = array( 'pdf', 'jpg', 'jpeg', 'png', 'gif', 'doc', 'docx' );

    /**
     * Default maximum file size (5MB).
     *
     * @var int
     */
    private $default_max_size = 5242880;

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current field value (not used for file inputs).
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id = isset( $form['id'] ) ? $form['id'] : '';

        $attributes = array(
            'type'  => 'file',
            'id'    => $this->get_id( $field, $form_id ),
            'name'  => $this->get_name( $field ),
            'class' => 'fre-field__input fre-field__file',
        );

        // Required attribute.
        if ( ! empty( $field['required'] ) ) {
            $attributes['required']      = true;
            $attributes['aria-required'] = 'true';
        }

        // Accept attribute for allowed types.
        $allowed_types = $this->get_allowed_types( $field );
        if ( ! empty( $allowed_types ) ) {
            $accept = array_map( function( $type ) {
                return '.' . $type;
            }, $allowed_types );
            $attributes['accept'] = implode( ',', $accept );
        }

        // Multiple files.
        if ( ! empty( $field['multiple'] ) ) {
            $attributes['multiple'] = true;
            $attributes['name']    .= '[]';
        }

        $input = sprintf( '<input%s />', $this->build_attributes( $attributes ) );

        // Add file info.
        $max_size = $this->get_max_size( $field );
        $info     = sprintf(
            '<p class="fre-field__file-info">%s: %s. %s: %s</p>',
            esc_html__( 'Allowed types', 'form-runtime-engine' ),
            esc_html( implode( ', ', $allowed_types ) ),
            esc_html__( 'Max size', 'form-runtime-engine' ),
            esc_html( size_format( $max_size ) )
        );

        return $this->render_wrapper( $field, $form_id, $input . $info );
    }

    /**
     * Get the field's input name attribute.
     *
     * @param array $field Field configuration.
     * @return string
     */
    public function get_name( array $field ) {
        return 'fre_file_' . sanitize_key( $field['key'] );
    }

    /**
     * Validate the file upload.
     *
     * Note: Actual file validation is handled by FRE_Upload_Handler.
     * This method validates based on $_FILES data.
     *
     * @param mixed $value Field value (unused for files).
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error
     */
    public function validate( $value, array $field, array $form ) {
        $file_key = $this->get_name( $field );
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated via MIME type, extension, and size checks.
        $files    = isset( $_FILES[ $file_key ] ) ? $_FILES[ $file_key ] : null;

        // Check required.
        if ( ! empty( $field['required'] ) ) {
            if ( empty( $files ) || empty( $files['name'] ) ) {
                return new WP_Error(
                    'required_field',
                    sprintf(
                        /* translators: %s: field label */
                        __( '%s is required.', 'form-runtime-engine' ),
                        $this->get_label( $field )
                    )
                );
            }

            // Handle multiple files.
            if ( is_array( $files['name'] ) ) {
                $has_file = false;
                foreach ( $files['name'] as $name ) {
                    if ( ! empty( $name ) ) {
                        $has_file = true;
                        break;
                    }
                }
                if ( ! $has_file ) {
                    return new WP_Error(
                        'required_field',
                        sprintf(
                            /* translators: %s: field label */
                            __( '%s is required.', 'form-runtime-engine' ),
                            $this->get_label( $field )
                        )
                    );
                }
            }
        }

        // File-specific validation is handled by Upload Handler.
        return true;
    }

    /**
     * Sanitize file field (returns the file key for processing).
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string
     */
    public function sanitize( $value, array $field ) {
        // File handling is done separately.
        return '';
    }

    /**
     * Get allowed file types for this field.
     *
     * @param array $field Field configuration.
     * @return array
     */
    public function get_allowed_types( array $field ) {
        if ( ! empty( $field['allowed_types'] ) && is_array( $field['allowed_types'] ) ) {
            return array_map( 'strtolower', $field['allowed_types'] );
        }

        return $this->default_allowed_types;
    }

    /**
     * Get maximum file size for this field.
     *
     * @param array $field Field configuration.
     * @return int Size in bytes.
     */
    public function get_max_size( array $field ) {
        if ( ! empty( $field['max_size'] ) ) {
            return (int) $field['max_size'];
        }

        return $this->default_max_size;
    }

    /**
     * Format value for display (show file link).
     *
     * @param mixed $value File attachment ID or file data.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( empty( $value ) ) {
            return '';
        }

        // If value is attachment ID.
        if ( is_numeric( $value ) ) {
            $url      = wp_get_attachment_url( $value );
            $filename = get_the_title( $value );

            if ( $url ) {
                return sprintf(
                    '<a href="%s" target="_blank" rel="noopener noreferrer" download>%s</a>',
                    esc_url( $url ),
                    esc_html( $filename ?: basename( $url ) )
                );
            }
        }

        // If value is an array of attachments.
        if ( is_array( $value ) ) {
            $links = array();
            foreach ( $value as $attachment_id ) {
                if ( is_numeric( $attachment_id ) ) {
                    $url      = wp_get_attachment_url( $attachment_id );
                    $filename = get_the_title( $attachment_id );

                    if ( $url ) {
                        $links[] = sprintf(
                            '<a href="%s" target="_blank" rel="noopener noreferrer" download>%s</a>',
                            esc_url( $url ),
                            esc_html( $filename ?: basename( $url ) )
                        );
                    }
                }
            }
            return implode( '<br>', $links );
        }

        return esc_html( $value );
    }

    /**
     * Format value for CSV.
     *
     * @param mixed $value File data.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_csv_value( $value, array $field ) {
        if ( empty( $value ) ) {
            return '';
        }

        // If value is attachment ID, return URL.
        if ( is_numeric( $value ) ) {
            return wp_get_attachment_url( $value ) ?: '';
        }

        // If value is an array of attachments.
        if ( is_array( $value ) ) {
            $urls = array();
            foreach ( $value as $attachment_id ) {
                if ( is_numeric( $attachment_id ) ) {
                    $url = wp_get_attachment_url( $attachment_id );
                    if ( $url ) {
                        $urls[] = $url;
                    }
                }
            }
            return implode( '; ', $urls );
        }

        return (string) $value;
    }
}
