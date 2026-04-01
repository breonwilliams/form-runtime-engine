<?php
/**
 * Upload Handler for Form Runtime Engine.
 *
 * Handles secure file uploads with comprehensive validation.
 *
 * NOTE: This file uses low-level filesystem operations intentionally for security:
 * - unlink(): Required for atomic cleanup of quarantined/failed uploads
 * - rename(): Required for TOCTOU-safe file moves from quarantine
 * - chmod(): Required to set restrictive permissions on uploaded files
 *
 * WP_Filesystem is not suitable because:
 * 1. It requires credentials prompts which break AJAX uploads
 * 2. Security operations need guaranteed synchronous execution
 * 3. Quarantine cleanup must be atomic to prevent race conditions
 *
 * NOTE: Called from submission handler after nonce verification.
 * $_FILES access is safe as the caller has already verified the nonce.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.WP.AlternativeFunctions.rename_rename
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_chmod
 * phpcs:disable Generic.PHP.ForbiddenFunctions.Found
 * phpcs:disable WordPress.Security.NonceVerification.Missing
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
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
        // PHP variants.
        'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'php8', 'phps', 'phar',
        'pht', 'phpt', 'pgif', 'phtm', 'hphp', 'inc',
        // Executables.
        'exe', 'sh', 'bash', 'bat', 'cmd', 'com', 'cgi', 'pl', 'py', 'rb',
        'msi', 'scr', 'vbs', 'vbe', 'wsf', 'wsh', 'ps1', 'psm1',
        // Server scripts.
        'js', 'jsp', 'jspx', 'asp', 'aspx', 'ashx', 'asmx', 'ascx',
        'shtml', 'shtm', 'stm', 'ssi',
        // Server config.
        'htaccess', 'htpasswd', 'htgroup', 'htdigest',
        // Environment and config.
        'env', 'ini', 'conf', 'cfg', 'config',
        // SVG/HTML (XSS risk).
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'xml', 'xsl', 'xslt',
        // Archives that can contain executables.
        'jar', 'war', 'ear',
    );

    /**
     * Quarantine directory name.
     *
     * @var string
     */
    private const QUARANTINE_DIR = 'fre-quarantine';

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
     * Fix #3: Implements rollback of successful uploads if a later file fails.
     * Tracks all uploaded files and cleans them up on failure to prevent orphaned files.
     *
     * @param array $form_config Form configuration.
     * @param int   $entry_id    Entry ID to associate files with.
     * @return array|WP_Error Array of uploaded file data, or WP_Error on failure.
     */
    public function process_uploads( array $form_config, $entry_id ) {
        $uploaded_files = array();
        $total_size     = 0;

        // Fix #3: Track successfully uploaded files for rollback on failure.
        $successful_uploads = array();

        foreach ( $form_config['fields'] as $field ) {
            if ( $field['type'] !== 'file' ) {
                continue;
            }

            $file_field = new FRE_Field_File();
            $file_key   = $file_field->get_name( $field );

            if ( ! isset( $_FILES[ $file_key ] ) || empty( $_FILES[ $file_key ]['name'] ) ) {
                continue;
            }

            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- File uploads are validated via MIME type, extension, and size checks.
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
                        // Fix #3: Rollback all successful uploads before returning error.
                        $this->rollback_uploads( $successful_uploads );
                        return new WP_Error(
                            'total_size_exceeded',
                            __( 'Total upload size exceeds maximum allowed.', 'form-runtime-engine' )
                        );
                    }

                    $result = $this->process_single_file( $file, $field, $entry_id );

                    if ( is_wp_error( $result ) ) {
                        // Fix #3: Rollback all successful uploads before returning error.
                        $this->rollback_uploads( $successful_uploads );
                        return $result;
                    }

                    // Fix #3: Track successful upload for potential rollback.
                    $successful_uploads[] = $result;
                    $uploaded_files[ $field['key'] ][] = $result;
                }
            } else {
                // Single file.
                $total_size += $files['size'];

                if ( $total_size > $this->default_max_total_size ) {
                    // Fix #3: Rollback all successful uploads before returning error.
                    $this->rollback_uploads( $successful_uploads );
                    return new WP_Error(
                        'total_size_exceeded',
                        __( 'Total upload size exceeds maximum allowed.', 'form-runtime-engine' )
                    );
                }

                $result = $this->process_single_file( $files, $field, $entry_id );

                if ( is_wp_error( $result ) ) {
                    // Fix #3: Rollback all successful uploads before returning error.
                    $this->rollback_uploads( $successful_uploads );
                    return $result;
                }

                // Fix #3: Track successful upload for potential rollback.
                $successful_uploads[] = $result;
                $uploaded_files[ $field['key'] ] = $result;
            }
        }

        return $uploaded_files;
    }

    /**
     * Rollback uploaded files on failure (Fix #3).
     *
     * Deletes attachments and files for uploads that succeeded before a failure occurred.
     *
     * @param array $uploads Array of successful upload data to roll back.
     */
    private function rollback_uploads( array $uploads ) {
        foreach ( $uploads as $upload ) {
            // Delete WordPress attachment if created.
            if ( ! empty( $upload['attachment_id'] ) ) {
                wp_delete_attachment( $upload['attachment_id'], true );
            } elseif ( ! empty( $upload['file_path'] ) && file_exists( $upload['file_path'] ) ) {
                // Direct file deletion if no attachment.
                @unlink( $upload['file_path'] );
            }
        }

        FRE_Logger::info( 'Rolled back ' . count( $uploads ) . ' uploaded file(s) due to upload failure.' );
    }

    /**
     * Process a single file upload with TOCTOU protection.
     *
     * Uses quarantine directory to validate files before moving to final location.
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

        // Verify it's an uploaded file (TOCTOU protection step 1).
        if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
            $this->log_upload_rejection( $file['name'], 'not_uploaded_file' );
            return new WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'form-runtime-engine' ) );
        }

        // Fix #19: Check disk space before processing upload.
        $disk_check = $this->check_disk_space( $file['size'] );
        if ( is_wp_error( $disk_check ) ) {
            return $disk_check;
        }

        // Validate and sanitize filename first.
        $sanitized_filename = $this->validate_and_sanitize_filename( $file['name'] );
        if ( is_wp_error( $sanitized_filename ) ) {
            $this->log_upload_rejection( $file['name'], $sanitized_filename->get_error_code() );
            return $sanitized_filename;
        }

        // Move to quarantine directory first (TOCTOU protection step 2).
        $quarantine_path = $this->get_quarantine_path();
        if ( is_wp_error( $quarantine_path ) ) {
            return $quarantine_path;
        }

        $quarantine_file = $quarantine_path . '/' . wp_generate_uuid4() . '_' . basename( $sanitized_filename );
        if ( ! move_uploaded_file( $file['tmp_name'], $quarantine_file ) ) {
            return new WP_Error( 'move_failed', __( 'Failed to process upload.', 'form-runtime-engine' ) );
        }

        // Validate quarantined file (TOCTOU protection step 3).
        $quarantine_validation = $this->validate_quarantined_file( $quarantine_file, $field );
        if ( is_wp_error( $quarantine_validation ) ) {
            @unlink( $quarantine_file );
            $this->log_upload_rejection( $file['name'], $quarantine_validation->get_error_code() );
            return $quarantine_validation;
        }

        // Generate secure filename.
        $secure_filename = $this->generate_secure_filename( $file['name'] );

        // Get upload directory.
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            @unlink( $quarantine_file );
            return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
        }

        $final_path = $upload_dir['path'] . '/' . $secure_filename;

        // Fix #4: Check that target path doesn't exist (prevent TOCTOU race condition).
        // An attacker could create a symlink at the target path between validation and rename.
        if ( file_exists( $final_path ) || is_link( $final_path ) ) {
            @unlink( $quarantine_file );
            FRE_Logger::warning( 'Upload blocked: Target path already exists - ' . $secure_filename );
            return new WP_Error( 'file_exists', __( 'Upload failed. Please try again.', 'form-runtime-engine' ) );
        }

        // Move from quarantine to final location (TOCTOU protection step 4).
        if ( ! rename( $quarantine_file, $final_path ) ) {
            @unlink( $quarantine_file );
            return new WP_Error( 'move_failed', __( 'Failed to complete upload.', 'form-runtime-engine' ) );
        }

        // Fix #11: Set more restrictive permissions (0600 instead of 0644).
        // On shared hosting, 0644 allows other local users to read uploaded files.
        if ( ! chmod( $final_path, 0600 ) ) {
            FRE_Logger::warning( 'Failed to set restrictive file permissions for ' . $final_path );
        }

        // Determine MIME type of final file.
        $mime_type = $this->mime_validator->detect_mime( $final_path );
        if ( ! $mime_type ) {
            $mime_type = 'application/octet-stream';
        }

        // Create attachment.
        $attachment_data = array(
            'post_mime_type' => $mime_type,
            'post_title'     => sanitize_file_name( pathinfo( $file['name'], PATHINFO_FILENAME ) ),
            'post_content'   => '',
            'post_status'    => 'private', // Keep uploads private.
        );

        $attachment_id = wp_insert_attachment( $attachment_data, $final_path );

        if ( is_wp_error( $attachment_id ) ) {
            @unlink( $final_path );
            return $attachment_id;
        }

        // Generate attachment metadata.
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attach_data = wp_generate_attachment_metadata( $attachment_id, $final_path );
        wp_update_attachment_metadata( $attachment_id, $attach_data );

        return array(
            'attachment_id' => $attachment_id,
            'file_path'     => $final_path,
            'file_url'      => $upload_dir['url'] . '/' . $secure_filename,
            'file_name'     => $file['name'],
            'file_size'     => filesize( $final_path ),
            'mime_type'     => $mime_type,
        );
    }

    /**
     * Validate and sanitize filename (Fix #2: Double extension validation, Fix #7: Unicode bypass).
     *
     * @param string $filename Original filename.
     * @return string|WP_Error Sanitized filename or error.
     */
    private function validate_and_sanitize_filename( $filename ) {
        // Strip null bytes.
        $filename = str_replace( chr( 0 ), '', $filename );

        // Fix #7: Unicode normalization to prevent combining character attacks.
        // e.g., "image.p\u0307hp.jpg" could bypass extension checks.
        if ( function_exists( 'normalizer_normalize' ) ) {
            $normalized = normalizer_normalize( $filename, Normalizer::FORM_C );
            if ( $normalized !== false ) {
                $filename = $normalized;
            }
        }

        // Fix #7: Strip RTL/LTR override characters that can hide true extension.
        // e.g., "image.gpj[RTL]php." appears as "image.php.jpg" visually.
        $filename = preg_replace( '/[\x{202A}-\x{202E}\x{2066}-\x{2069}\x{200E}\x{200F}]/u', '', $filename );

        // Fix #7: Strip other dangerous Unicode characters.
        // Zero-width characters, combining marks that could hide extensions.
        $filename = preg_replace( '/[\x{200B}-\x{200D}\x{FEFF}]/u', '', $filename );

        // Remove whitespace.
        $filename = preg_replace( '/\s+/', '', $filename );

        // Remove trailing dots and spaces.
        $filename = rtrim( $filename, '. ' );

        // Check length.
        if ( mb_strlen( $filename ) > 255 || mb_strlen( $filename ) < 1 ) {
            return new WP_Error( 'invalid_filename', __( 'Invalid filename.', 'form-runtime-engine' ) );
        }

        // Fix #7: Check for non-ASCII characters in extension (homograph attacks).
        // Only allow ASCII in file extension to prevent Cyrillic 'а' vs Latin 'a'.
        $ext = pathinfo( $filename, PATHINFO_EXTENSION );
        if ( ! empty( $ext ) && preg_match( '/[^\x20-\x7E]/', $ext ) ) {
            return new WP_Error( 'invalid_extension', __( 'File extension contains invalid characters.', 'form-runtime-engine' ) );
        }

        // Build pattern from blocked extensions.
        $blocked_pattern = implode( '|', array_map( 'preg_quote', self::BLOCKED_EXTENSIONS ) );

        // Check for blocked extension anywhere in filename (catches double extensions).
        if ( preg_match( '/\.(' . $blocked_pattern . ')(\.|$)/i', $filename ) ) {
            return new WP_Error( 'blocked_extension', __( 'File type not allowed.', 'form-runtime-engine' ) );
        }

        return $filename;
    }

    /**
     * Get quarantine directory path (Fix #9: Cross-server compatibility).
     *
     * Uses randomized directory name and adds protection for Nginx/IIS as well as Apache.
     *
     * @return string|WP_Error Quarantine path or error.
     */
    private function get_quarantine_path() {
        $upload_dir = wp_upload_dir();
        if ( ! empty( $upload_dir['error'] ) ) {
            return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
        }

        // Fix #9: Use randomized quarantine directory name stored in options.
        // This makes the path unpredictable even if an attacker knows the structure.
        $quarantine_suffix = get_option( 'fre_quarantine_suffix' );
        if ( empty( $quarantine_suffix ) ) {
            $quarantine_suffix = wp_generate_password( 16, false );
            update_option( 'fre_quarantine_suffix', $quarantine_suffix, false );
        }

        $quarantine_path = $upload_dir['basedir'] . '/' . self::QUARANTINE_DIR . '-' . $quarantine_suffix;

        // Create quarantine directory if it doesn't exist.
        if ( ! file_exists( $quarantine_path ) ) {
            if ( ! wp_mkdir_p( $quarantine_path ) ) {
                return new WP_Error( 'quarantine_create_failed', __( 'Failed to create quarantine directory.', 'form-runtime-engine' ) );
            }

            // Fix #9: Set restrictive permissions (0700 - owner only).
            chmod( $quarantine_path, 0700 );

            // Protect quarantine directory with .htaccess (Apache).
            $htaccess_content = "# Deny all access\n";
            $htaccess_content .= "<IfModule mod_authz_core.c>\n";
            $htaccess_content .= "    Require all denied\n";
            $htaccess_content .= "</IfModule>\n";
            $htaccess_content .= "<IfModule !mod_authz_core.c>\n";
            $htaccess_content .= "    Order deny,allow\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</IfModule>\n";
            file_put_contents( $quarantine_path . '/.htaccess', $htaccess_content );

            // Add index.php for extra protection.
            file_put_contents( $quarantine_path . '/index.php', '<?php // Silence is golden.' );

            // Fix #9: Add web.config for IIS protection.
            $webconfig = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
            $webconfig .= '<configuration>' . "\n";
            $webconfig .= '    <system.webServer>' . "\n";
            $webconfig .= '        <authorization>' . "\n";
            $webconfig .= '            <deny users="*" />' . "\n";
            $webconfig .= '        </authorization>' . "\n";
            $webconfig .= '    </system.webServer>' . "\n";
            $webconfig .= '</configuration>' . "\n";
            file_put_contents( $quarantine_path . '/web.config', $webconfig );
        }

        return $quarantine_path;
    }

    /**
     * Validate file in quarantine directory (Fix #10: SVG validation).
     *
     * @param string $file_path Path to quarantined file.
     * @param array  $field     Field configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    private function validate_quarantined_file( $file_path, array $field ) {
        // Get allowed types for this field.
        $file_field    = new FRE_Field_File();
        $allowed_types = $file_field->get_allowed_types( $field );

        // Verify content matches extension using finfo.
        $mime_validation = $this->mime_validator->validate( $file_path, $allowed_types );
        if ( is_wp_error( $mime_validation ) ) {
            return $mime_validation;
        }

        // Scan for dangerous patterns (polyglot detection).
        $pattern_scan = $this->mime_validator->scan_for_dangerous_patterns( $file_path );
        if ( is_wp_error( $pattern_scan ) ) {
            return $pattern_scan;
        }

        // Verify magic bytes.
        $ext = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
        $magic_validation = $this->mime_validator->verify_magic_bytes( $file_path, $ext );
        if ( is_wp_error( $magic_validation ) ) {
            return $magic_validation;
        }

        // Fix #10: Validate SVG content for XSS if SVG uploads are enabled.
        // The validate_svg() method existed but was never called.
        if ( $ext === 'svg' || $ext === 'svgz' ) {
            $svg_validation = $this->mime_validator->validate_svg( $file_path );
            if ( is_wp_error( $svg_validation ) ) {
                return $svg_validation;
            }
        }

        // Validate file size.
        $max_size = $file_field->get_max_size( $field );
        $actual_size = filesize( $file_path );
        if ( $actual_size > $max_size ) {
            return new WP_Error(
                'file_too_large',
                sprintf(
                    /* translators: %s: max file size */
                    __( 'File exceeds maximum size of %s.', 'form-runtime-engine' ),
                    size_format( $max_size )
                )
            );
        }

        return true;
    }

    /**
     * Log upload rejection for monitoring (Fix #31).
     *
     * @param string $filename Original filename.
     * @param string $reason   Rejection reason.
     */
    private function log_upload_rejection( $filename, $reason ) {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : 'unknown';

        FRE_Logger::warning( sprintf(
            'Upload blocked: file=%s, reason=%s, ip=%s',
            sanitize_file_name( $filename ),
            $reason,
            $ip
        ) );

        /**
         * Fires when an upload is rejected.
         *
         * @param string $filename Original filename.
         * @param string $reason   Rejection reason.
         * @param string $ip       Client IP.
         */
        do_action( 'fre_upload_rejected', $filename, $reason, $ip );
    }

    /**
     * Validate a file before upload.
     *
     * @param array $file  $_FILES array element.
     * @param array $field Field configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_file( array $file, array $field ) {
        // Validate and sanitize filename first (catches double extensions).
        $sanitized_filename = $this->validate_and_sanitize_filename( $file['name'] );
        if ( is_wp_error( $sanitized_filename ) ) {
            return $sanitized_filename;
        }

        // Get true extension.
        $ext = strtolower( pathinfo( $sanitized_filename, PATHINFO_EXTENSION ) );

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

    /**
     * Check available disk space before upload (Fix #19).
     *
     * @param int $required_bytes Bytes needed for the upload.
     * @return bool|WP_Error True if enough space, WP_Error otherwise.
     */
    private function check_disk_space( $required_bytes ) {
        $upload_dir = wp_upload_dir();

        if ( ! empty( $upload_dir['error'] ) ) {
            return new WP_Error( 'upload_dir_error', $upload_dir['error'] );
        }

        $target_path = $upload_dir['basedir'];

        // Check if disk_free_space function is available.
        if ( ! function_exists( 'disk_free_space' ) ) {
            // Function disabled or unavailable - allow upload.
            return true;
        }

        $free_space = @disk_free_space( $target_path );

        if ( $free_space === false ) {
            // Unable to determine free space - allow upload.
            return true;
        }

        // Require at least the file size plus 10MB buffer.
        $minimum_required = $required_bytes + ( 10 * 1024 * 1024 );

        if ( $free_space < $minimum_required ) {
            FRE_Logger::warning( sprintf(
                'Disk space check failed. Required: %s, Available: %s',
                size_format( $minimum_required ),
                size_format( $free_space )
            ) );

            return new WP_Error(
                'disk_full',
                sprintf(
                    /* translators: %s: available disk space */
                    __( 'Insufficient disk space. Only %s available.', 'form-runtime-engine' ),
                    size_format( $free_space )
                )
            );
        }

        return true;
    }
}
