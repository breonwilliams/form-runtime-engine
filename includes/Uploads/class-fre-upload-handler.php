<?php
/**
 * Upload Handler for Form Runtime Engine.
 *
 * Handles secure file uploads with comprehensive validation.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * File upload handler.
 */
class FRE_Upload_Handler {

    /**
     * Blocked extensions (case-insensitive).
     *
     * @var array
     */
    private const BLOCKED_EXTENSIONS = array(
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb',
        'js', 'jsp', 'asp', 'aspx', 'htaccess', 'htpasswd',
        'svg', 'svgz', // SVG can contain JavaScript.
    );

    /**
     * Default maximum file size (10MB).
     *
     * @var int
     */
    private $default_max_size = 10485760;

    /**
     * Default maximum total upload size (25MB).
     *
     * @var int
     */
    private $default_max_total_size = 26214400;

    /**
     * MIME validator instance.
     *
     * @var FRE_Mime_Validator
     */
    private $mime_validator;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mime_validator = new FRE_Mime_Validator();
    }

    /**
     * Process file uploads for a form submission.
     *
     * @param array $form_config Form configuration.
     * @param int   $entry_id    Entry ID to associate files with.
     * @return array|WP_Error Array of uploaded file data, or WP_Error on failure.
     */
    public function process_uploads( array $form_config, $entry_id ) {
        $uploaded_files = array();
        $total_size     = 0;

        foreach ( $form_config['fields'] as $field ) {
            if ( $field['type'] !== 'file' ) {
                continue;
            }

            $file_field = new FRE_Field_File();
            $file_key   = $file_field->get_name( $field );

            if ( ! isset( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
                continue;
            }

            $files = $_FILES[ $file_key ];

            // Handle multiple files.
            if ( is_array( $files['name'] ) ) {
                foreach ( $files['name'] as $index => $name ) {
                    if ( empty( $name ) ) {
                        continue;
                    }

                    $file = array(
                        'name'     => $files['name'][ $index ],
                        'type'     => $files['type'][ $index ],
                        'tmp_name' => $files['tmp_name'][ $index ],
                        'error'    => $files['error'][ $index ],
                        'size'     => $files['size'][ $index ],
                    );

                    $total_size += $file['size'];

                    // Check total size limit.
                    if ( $total_size > $this->default_max_total_size ) {
                        return new WP_Error(
                            'total_size_exceeded',
                            __( 'Total upload size exceeds maximum allowed.', 'form-runtime-engine' )
                        );
                    }

                    $result = $this->process_single_file( $file, $field, $entry_id );

                    if ( is_wp_error( $result ) ) {
                        return $result;
                    }

                    $uploaded_files[ $field['key'] ][] = $result;
                }
            } else {
                // Single file.
                $total_size += $files['size'];

                if ( $total_size > $this->default_max_total_size ) {
                    return new WP_Error(
                        'total_size_exceeded',
                        __( 'Total upload size exceeds maximum allowed.', 'form-runtime-engine' )
                    );
                }

                $result = $this->process_single_file( $files, $field, $entry_id );

                if ( is_wp_error( $result ) ) {
                    return $result;
                }

                $uploaded_files[ $field['key'] ] = $result;
            }
        }

        return $uploaded_files;
    }

    /**
     * Process a single file upload.
     *
     * @param array $file     $_FILES array element.
     * @param array $field    Field configuration.
     * @param int   $entry_id Entry ID.
     * @return array|WP_Error File data array or WP_Error.
     */
    private function process_single_file( array $file, array $field, $entry_id ) {
        // Check for upload errors.
        if ( $file['error'] !== UPLOAD_ERR_OK ) {
            return new WP_Error(
                'upload_error',
                $this->get_upload_error_message( $file['error'] )
            );
        }

        // Validate the file.
        $validation = $this->validate_file( $file, $field );
        if ( is_wp_error( $validation ) ) {
            return $validation;
        }

        // Generate secure filename.
        $secure_filename = $this->generate_secure_filename( $file['name'] );

        // Prepare for WordPress upload.
        $upload_overrides = array(
            'test_form' => false,
            'unique_filename_callback' => function( $dir, $name, $ext ) use ( $secure_filename ) {
                return $secure_filename;
            },
        );

        // Use WordPress upload handling.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        // Move file to uploads directory.
        $uploaded = wp_handle_upload( $file, $upload_overrides );

        if ( isset( $uploaded['error'] ) ) {
            return new WP_Error( 'upload_failed', $uploaded['error'] );
        }

        // Create attachment.
        $attachment_data = array(
            'post_mime_type' => $uploaded['type'],
            'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'private', // Keep uploads private.
        );

        $attachment_id = wp_insert_attachment( $attachment_data, $uploaded['file'] );

        if ( is_wp_error( $attachment_id ) ) {
            // Clean up file.
            @unlink( $uploaded['file'] );
            return $attachment_id;
        }

        // Generate attachment metadata.
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        return array(
            'attachment_id' => $attachment_id,
            'file_path'     => $uploaded['file'],
            'file_url'      => $uploaded['url'],
            'file_name'     => $file['name'],
            'file_size'     => $file['size'],
            'mime_type'     => $uploaded['type'],
        );
    }

    /**
     * Validate a file before upload.
     *
     * @param array $file  $_FILES array element.
     * @param array $field Field configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_file( array $file, array $field ) {
        $filename = $file['name'];

        // Strip null bytes.
        $filename = str_replace( chr( 0 ), '', $filename );

        // Check for double extensions (e.g., shell.php.jpg).
        if ( preg_match( '/\.(php|phtml|exe|sh|js|svg)[0-9]*\./i', $filename ) ) {
            return new WP_Error(
                'blocked_extension',
                __( 'File type not allowed.', 'form-runtime-engine' )
            );
        }

        // Get true extension.
        $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

        // Check blocked extensions.
        if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
            return new WP_Error(
                'blocked_extension',
                __( 'File type not allowed.', 'form-runtime-engine' )
            );
        }

        // Get allowed types for this field.
        $file_field    = new FRE_Field_File();
        $allowed_types = $file_field->get_allowed_types( $field );

        // Check extension against allowed types.
        if ( ! in_array( $ext, $allowed_types, true ) ) {
            return new WP_Error(
                'extension_not_allowed',
                sprintf(
                    /* translators: %s: allowed file types */
                    __( 'Allowed file types: %s', 'form-runtime-engine' ),
                    implode( ', ', $allowed_types )
                )
            );
        }

        // Validate file size.
        $max_size = $file_field->get_max_size( $field );
        $size_validation = $this->validate_file_size( $file, $max_size );
        if ( is_wp_error( $size_validation ) ) {
            return $size_validation;
        }

        // Verify content matches extension using finfo.
        $mime_validation = $this->mime_validator->validate( $file['tmp_name'], $allowed_types );
        if ( is_wp_error( $mime_validation ) ) {
            return $mime_validation;
        }

        return true;
    }

    /**
     * Validate file size.
     *
     * @param array $file      $_FILES array element.
     * @param int   $max_bytes Maximum allowed bytes.
     * @return bool|WP_Error True if valid, WP_Error if too large.
     */
    public function validate_file_size( array $file, $max_bytes ) {
        // Check $_FILES size first.
        if ( $file['size'] > $max_bytes ) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    /* translators: %s: max file size */
                    __( 'File exceeds maximum size of %s.', 'form-runtime-engine' ),
                    size_format( $max_bytes )
                )
            );
        }

        // Also check actual file size (in case of spoofed header).
        if ( file_exists( $file['tmp_name'] ) ) {
            $actual_size = filesize( $file['tmp_name'] );
            if ( $actual_size > $max_bytes ) {
                return new WP_Error(
                    'file_too_large',
                    __( 'File exceeds maximum size.', 'form-runtime-engine' )
                );
            }
        }

        return true;
    }

    /**
     * Generate a secure random filename.
     *
     * @param string $original_filename Original filename.
     * @return string Secure filename.
     */
    public function generate_secure_filename( $original_filename ) {
        $ext = strtolower( pathinfo( $original_filename, PATHINFO_EXTENSION ) );

        // Sanitize extension.
        $ext = preg_replace( '/[^a-z0-9]/', '', $ext );

        // Generate UUID-based filename.
        $uuid = wp_generate_uuid4();

        return $uuid . '.' . $ext;
    }

    /**
     * Get human-readable upload error message.
     *
     * @param int $error_code PHP upload error code.
     * @return string Error message.
     */
    private function get_upload_error_message( $error_code ) {
        $messages = array(
            UPLOAD_ERR_INI_SIZE   => __( 'File exceeds server upload limit.', 'form-runtime-engine' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'File exceeds form upload limit.', 'form-runtime-engine' ),
            UPLOAD_ERR_PARTIAL    => __( 'File was only partially uploaded.', 'form-runtime-engine' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'form-runtime-engine' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'Server configuration error.', 'form-runtime-engine' ),
            UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file.', 'form-runtime-engine' ),
            UPLOAD_ERR_EXTENSION  => __( 'Upload blocked by server.', 'form-runtime-engine' ),
        );

        return isset( $messages[ $error_code ] )
            ? $messages[ $error_code ]
            : __( 'Unknown upload error.', 'form-runtime-engine' );
    }

    /**
     * Delete uploaded files for an entry.
     *
     * @param int $entry_id Entry ID.
     * @return bool True on success.
     */
    public function delete_entry_files( $entry_id ) {
        global $wpdb;

        $table = $wpdb->prefix . 'fre_entry_files';
        $files = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT attachment_id FROM {$table} WHERE entry_id = %d",
                $entry_id
            )
        );

        foreach ( $files as $file ) {
            if ( ! empty( $file->attachment_id ) ) {
                wp_delete_attachment( $file->attachment_id, true );
            }
        }

        return true;
    }

    /**
     * Check if file uploads are enabled.
     *
     * @return bool
     */
    public function is_uploads_enabled() {
        $upload_dir = wp_upload_dir();
        return empty( $upload_dir['error'] );
    }

    /**
     * Get maximum upload size allowed by server.
     *
     * @return int Bytes.
     */
    public function get_server_max_upload_size() {
        return wp_max_upload_size();
    }
}
