<?php
/**
 * Upload Handler for Promptless Forms.
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
class PForms_Upload_Handler {

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
     * Blocked extensions a field may still accept by listing them explicitly
     * in `allowed_types`. SVG was blocked outright until 1.11.0, so a print
     * shop's logo field that allowed "svg" refused every SVG anyway. It is
     * now accepted when the field asks for it, and parsed and checked for
     * active content by PForms_Upload_Inspector. Nothing that can execute on
     * the server may ever be added here.
     *
     * @var string[]
     */
    private const FIELD_ALLOWABLE_BLOCKED = array( 'svg' );

    /**
     * Default maximum file size in bytes (10MB).
     *
     * @var int
     */
    private const DEFAULT_MAX_FILE_SIZE = 10485760;

    /**
     * Default maximum total upload size in bytes (25MB).
     *
     * @var int
     */
    private const DEFAULT_MAX_TOTAL_SIZE = 26214400;

    /**
     * File permissions for uploaded files (owner read/write only).
     *
     * @var int
     */
    /**
     * Default file mode applied to uploaded files after they pass validation
     * and are moved into the public uploads directory.
     *
     * 0644 (owner read/write, group/other read-only) matches WordPress core's
     * default for uploaded media and ensures the web server (which on most
     * shared / managed hosting runs as a different system user from the
     * PHP/account user) can read the file to serve it. The earlier 0600
     * default broke direct URL access to uploaded files — emails with
     * download links and webhook consumers (Zapier, Make) fetching the
     * file_url both received 403 Forbidden.
     *
     * Sites on configurations where 0600 works correctly (e.g., suEXEC
     * with PHP and Apache running as the same user) and that want the
     * tighter posture can override via the `pforms_uploaded_file_permissions`
     * filter.
     */
    private const FILE_PERMISSIONS = 0644;

    /**
     * Minimum byte size enforced per extension for binary formats whose magic
     * bytes are short and easily forged.
     *
     * Defense-in-depth against polyglot uploads. The dangerous-pattern scan
     * runs on every uploaded file and catches PHP / shell / JS payloads, and
     * randomized UUID filenames + Apache's default handler config prevent
     * non-PHP uploads from executing. This min-size check is the additional
     * belt-and-suspenders signal that "your magic bytes match but your file
     * is too small to actually be one of these formats" — a 50-byte file
     * pretending to be a 500KB embroidery pattern is suspicious regardless
     * of what its first 3 bytes say.
     *
     * Defaults reflect each format's structural floor:
     *   ai  — Illustrator files are PDF or PostScript; even minimal headers
     *         are well over 1KB once metadata + version block are included.
     *   eps — Encapsulated PostScript needs at minimum %!PS-Adobe-X.X EPSF-X.X
     *         header + bounding box comment; ~100 bytes is the realistic floor.
     *   dst — Tajima embroidery files have a 512-byte fixed header alone
     *         before any stitch data. 500 bytes is the conservative minimum.
     *
     * Override per site via the `pforms_min_file_sizes` filter — useful for
     * format variants the plugin doesn't ship with (e.g., .pes embroidery,
     * .cdr CorelDRAW) that are registered via the `pforms_mime_map` filter.
     *
     * @since 1.5.0
     */
    private const MIN_FILE_SIZES = array(
        'ai'  => 1024,
        'eps' => 100,
        'dst' => 500,
    );

    /**
     * Default maximum file size (10MB).
     *
     * @var int
     */
    private $default_max_size = self::DEFAULT_MAX_FILE_SIZE;


    /**
     * MIME validator instance.
     *
     * @var PForms_Mime_Validator
     */
    private $mime_validator;

    /**
     * Constructor.
     */
    public function __construct() {
        $this->mime_validator = new PForms_Mime_Validator();
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
        $max_total      = $this->get_max_total_size( $form_config );

        // Fix #3: Track successfully uploaded files for rollback on failure.
        $successful_uploads = array();

        foreach ( $form_config['fields'] as $field ) {
            if ( $field['type'] !== 'file' ) {
                continue;
            }

            $file_field = new PForms_Field_File();
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
                    if ( $total_size > $max_total ) {
                        // Fix #3: Rollback all successful uploads before returning error.
                        $this->rollback_uploads( $successful_uploads );
                        return new WP_Error(
                            'total_size_exceeded',
                            $this->total_size_message( $max_total )
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

                if ( $total_size > $max_total ) {
                    // Fix #3: Rollback all successful uploads before returning error.
                    $this->rollback_uploads( $successful_uploads );
                    return new WP_Error(
                        'total_size_exceeded',
                        $this->total_size_message( $max_total )
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

        PForms_Logger::info( 'Rolled back ' . count( $uploads ) . ' uploaded file(s) due to upload failure.' );
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
            return new WP_Error( 'invalid_upload', __( 'Invalid file upload.', 'promptless-forms' ) );
        }

        // Fix #19: Check disk space before processing upload.
        $disk_check = $this->check_disk_space( $file['size'] );
        if ( is_wp_error( $disk_check ) ) {
            return $disk_check;
        }

        // Validate and sanitize filename first.
        $file_field         = new PForms_Field_File();
        $sanitized_filename = $this->validate_and_sanitize_filename( $file['name'], $file_field->get_allowed_types( $field ) );
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
            return new WP_Error( 'move_failed', __( 'Failed to process upload.', 'promptless-forms' ) );
        }

        // Validate quarantined file (TOCTOU protection step 3).
        $quarantine_validation = $this->validate_quarantined_file( $quarantine_file, $field );
        if ( is_wp_error( $quarantine_validation ) ) {
            @unlink( $quarantine_file );
            $this->log_upload_rejection( $file['name'], $quarantine_validation->get_error_code() );
            return $quarantine_validation;
        }

        // Generate secure filename.
        // Stored under the inspected extension: a PNG uploaded with a .jpg
        // name is kept as .png, so its name, type and content agree.
        $secure_filename = $this->generate_secure_filename( $file['name'], $quarantine_validation['ext'] );

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
            PForms_Logger::warning( 'Upload blocked: Target path already exists - ' . $secure_filename );
            return new WP_Error( 'file_exists', __( 'Upload failed. Please try again.', 'promptless-forms' ) );
        }

        // Move from quarantine to final location (TOCTOU protection step 4).
        if ( ! rename( $quarantine_file, $final_path ) ) {
            @unlink( $quarantine_file );
            return new WP_Error( 'move_failed', __( 'Failed to complete upload.', 'promptless-forms' ) );
        }

        /**
         * Filter the file permissions applied to uploaded files after they
         * are moved into the public uploads directory.
         *
         * Default 0644 (matching WordPress core) ensures the web server can
         * read the file across the broadest range of hosting configurations.
         * Sites running suEXEC with PHP and Apache as the same user can
         * tighten via this filter (e.g., return 0600) without breaking URL
         * access on hosts that need the broader read bit.
         *
         * @since 1.5.0
         *
         * @param int    $permissions Octal file mode (default 0644).
         * @param string $final_path  Absolute path of the uploaded file.
         */
        $permissions = (int) apply_filters( 'pforms_uploaded_file_permissions', self::FILE_PERMISSIONS, $final_path );

        if ( ! chmod( $final_path, $permissions ) ) {
            PForms_Logger::warning( sprintf(
                'Failed to set file permissions (%o) for %s',
                $permissions,
                $final_path
            ) );
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

        wp_update_attachment_metadata( $attachment_id, $this->build_attachment_metadata( $attachment_id, $final_path, $mime_type ) );

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
     * @param string $filename      Original filename.
     * @param array  $allowed_types Extensions the field allows; lets a field opt in to FIELD_ALLOWABLE_BLOCKED types.
     * @return string|WP_Error Sanitized filename or error.
     */
    private function validate_and_sanitize_filename( $filename, array $allowed_types = array() ) {
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
            return new WP_Error( 'invalid_filename', __( 'Invalid filename.', 'promptless-forms' ) );
        }

        // Fix #7: Check for non-ASCII characters in extension (homograph attacks).
        // Only allow ASCII in file extension to prevent Cyrillic 'а' vs Latin 'a'.
        $ext = pathinfo( $filename, PATHINFO_EXTENSION );
        if ( ! empty( $ext ) && preg_match( '/[^\x20-\x7E]/', $ext ) ) {
            return new WP_Error( 'invalid_extension', __( 'File extension contains invalid characters.', 'promptless-forms' ) );
        }

        // Build pattern from blocked extensions.
        $blocked_pattern = implode( '|', array_map( 'preg_quote', $this->blocked_extensions( $allowed_types ) ) );

        // Check for blocked extension anywhere in filename (catches double extensions).
        if ( preg_match( '/\.(' . $blocked_pattern . ')(\.|$)/i', $filename ) ) {
            return new WP_Error( 'blocked_extension', __( 'File type not allowed.', 'promptless-forms' ) );
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
        $quarantine_suffix = get_option( 'pforms_quarantine_suffix' );
        if ( empty( $quarantine_suffix ) ) {
            $quarantine_suffix = wp_generate_password( 16, false );
            update_option( 'pforms_quarantine_suffix', $quarantine_suffix, false );
        }

        $quarantine_path = $upload_dir['basedir'] . '/' . self::QUARANTINE_DIR . '-' . $quarantine_suffix;

        // Create quarantine directory if it doesn't exist.
        if ( ! file_exists( $quarantine_path ) ) {
            if ( ! wp_mkdir_p( $quarantine_path ) ) {
                return new WP_Error( 'quarantine_create_failed', __( 'Failed to create quarantine directory.', 'promptless-forms' ) );
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
     * Validate the quarantined copy of an upload.
     *
     * @param string $file_path Path to quarantined file.
     * @param array  $field     Field configuration.
     * @return array|WP_Error Inspection result (ext, mime) or error.
     */
    private function validate_quarantined_file( $file_path, array $field ) {
        return $this->inspect_file( $file_path, strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) ), $field );
    }

    /**
     * Inspect a file for a field: content, format and size.
     *
     * Runs twice per upload — on PHP's temporary file BEFORE the entry is
     * written (so a refused file never creates and then deletes an entry),
     * and again on the quarantined copy that is actually stored.
     *
     * @param string $path  File path.
     * @param string $ext   Extension from the visitor's file name.
     * @param array  $field Field configuration.
     * @return array|WP_Error array( 'ext', 'mime' ) or error.
     */
    private function inspect_file( $path, $ext, array $field ) {
        $file_field = new PForms_Field_File();
        $inspector  = new PForms_Upload_Inspector( $this->mime_validator );
        $result     = $inspector->inspect( $path, $ext, $file_field->get_allowed_types( $field ) );

        /**
         * Filter the result of inspecting an uploaded file.
         *
         * Return a WP_Error to refuse a file the built-in inspection accepted
         * (for example, after calling a malware scanner), or an array with
         * 'ext' and 'mime' to accept one it refused. The message of a
         * returned WP_Error is shown to the visitor.
         *
         * @since 1.11.0
         *
         * @param array|WP_Error $result Inspection result.
         * @param string         $path   File path.
         * @param string         $ext    Extension from the visitor's file name.
         * @param array          $field  Field configuration.
         */
        $result = apply_filters( 'pforms_upload_inspection', $result, $path, $ext, $field );
        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $ext = $result['ext'];

        // Enforce minimum file size for formats with short / easily-forged
        // magic bytes (1.5.0). A tiny "DST" file whose only validity signal
        // is a 3-byte LA: header is suspicious regardless of the rest.
        /**
         * Filter the per-extension minimum file size enforcement table.
         *
         * Map of lowercase extension → minimum byte size. Keys not in the
         * returned map are not size-checked (existing behavior). Useful for
         * extending the protection to custom formats registered via
         * `pforms_mime_map` (e.g., `pes` for Brother embroidery, `cdr` for
         * CorelDRAW vector files).
         *
         * @since 1.5.0
         *
         * @param array $min_sizes Map of extension → minimum bytes.
         */
        $min_sizes   = (array) apply_filters( 'pforms_min_file_sizes', self::MIN_FILE_SIZES );
        $actual_size = filesize( $path );
        if ( isset( $min_sizes[ $ext ] ) && false !== $actual_size && $actual_size < (int) $min_sizes[ $ext ] ) {
            return new WP_Error(
                'file_too_small',
                sprintf(
                    /* translators: 1: file extension (e.g. AI, EPS, DST), 2: minimum size in human-readable form */
                    __( 'This file is too small to be a real %1$s file (the minimum is %2$s). Please export it again.', 'promptless-forms' ),
                    strtoupper( $ext ),
                    size_format( (int) $min_sizes[ $ext ] )
                )
            );
        }

        $max_size = $file_field->get_max_size( $field );
        if ( false !== $actual_size && $actual_size > $max_size ) {
            return $this->too_large_error( $max_size );
        }

        return $result;
    }

    /**
     * Blocked extensions for a field: the fixed list, minus any type in
     * FIELD_ALLOWABLE_BLOCKED that the field explicitly allows.
     *
     * @param array $allowed_types Extensions the field allows.
     * @return string[]
     */
    private function blocked_extensions( array $allowed_types ) {
        $opted_in = array_intersect( self::FIELD_ALLOWABLE_BLOCKED, array_map( 'strtolower', $allowed_types ) );
        return array_values( array_diff( self::BLOCKED_EXTENSIONS, $opted_in ) );
    }

    /**
     * Minimal attachment metadata for a form upload.
     *
     * Form uploads are private files for the business, never shown in the
     * media library, so no thumbnails are made. Until 1.11.0 every upload
     * went through wp_generate_attachment_metadata() inside the visitor's
     * request: resizing a 12-megapixel photo into every registered size, and
     * rendering PDF and Illustrator files through Ghostscript. That is slow on
     * shared hosting, a timeout risk after the entry is already saved, and a
     * server-side attack surface for crafted PDFs. Nothing in Promptless
     * Forms or FlowMint reads the sub-sizes.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $path          Stored file path.
     * @param string $mime_type     Detected MIME type.
     * @return array
     */
    private function build_attachment_metadata( $attachment_id, $path, $mime_type ) {
        /**
         * Generate full attachment metadata (thumbnails, PDF previews) for form uploads.
         *
         * Off by default since 1.11.0. Return true to restore the earlier
         * behaviour, e.g. if a custom admin view displays thumbnails.
         *
         * @since 1.11.0
         *
         * @param bool   $generate      Whether to generate full metadata.
         * @param int    $attachment_id Attachment ID.
         * @param string $path          Stored file path.
         */
        if ( apply_filters( 'pforms_generate_attachment_metadata', false, $attachment_id, $path ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            return (array) wp_generate_attachment_metadata( $attachment_id, $path );
        }

        $metadata = array( 'filesize' => (int) filesize( $path ) );
        if ( 0 === strpos( (string) $mime_type, 'image/' ) ) {
            $size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            if ( $size ) {
                $metadata['width']  = (int) $size[0];
                $metadata['height'] = (int) $size[1];
            }
        }

        return $metadata;
    }

    /**
     * Maximum combined size of all files in one submission.
     *
     * Until 1.11.0 this was a fixed 25 MB, so a form with a 10 MB field and a
     * 25 MB field refused a visitor who kept to both limits. It is now the
     * larger of 25 MB and the sum of the form's per-field limits (a field
     * that takes several files counts once per file the server accepts in
     * one request).
     *
     * @param array $form_config Form configuration.
     * @return int Bytes.
     */
    public function get_max_total_size( array $form_config ) {
        $file_field  = new PForms_Field_File();
        $per_request = max( 1, (int) ini_get( 'max_file_uploads' ) );
        $sum         = 0;

        foreach ( isset( $form_config['fields'] ) ? (array) $form_config['fields'] : array() as $field ) {
            if ( isset( $field['type'] ) && 'file' === $field['type'] ) {
                $sum += $file_field->get_max_size( $field ) * ( empty( $field['multiple'] ) ? 1 : $per_request );
            }
        }

        /**
         * Filter the maximum combined upload size for one submission.
         *
         * @since 1.11.0
         *
         * @param int   $max_total   Bytes.
         * @param array $form_config Form configuration.
         */
        return (int) apply_filters( 'pforms_max_total_upload_size', max( self::DEFAULT_MAX_TOTAL_SIZE, $sum ), $form_config );
    }

    /**
     * Visitor message for exceeding the combined size.
     *
     * @param int $max_total Bytes.
     * @return string
     */
    private function total_size_message( $max_total ) {
        return sprintf(
            /* translators: %s: size, e.g. 35 MB */
            __( 'Your files add up to more than %s. Please send fewer or smaller files.', 'promptless-forms' ),
            size_format( $max_total )
        );
    }

    /**
     * Error for a file over its field's limit.
     *
     * @param int $max_bytes Limit.
     * @return WP_Error
     */
    private function too_large_error( $max_bytes ) {
        return new WP_Error(
            'file_too_large',
            sprintf(
                /* translators: %s: max file size, e.g. 25 MB */
                __( 'This file is larger than %s. Please choose a smaller file.', 'promptless-forms' ),
                size_format( $max_bytes )
            )
        );
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

        PForms_Logger::warning( sprintf(
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
        do_action( 'pforms_upload_rejected', $filename, $reason, $ip );
    }

    /**
     * Validate a file before upload.
     *
     * @param array $file  $_FILES array element.
     * @param array $field Field configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate_file( array $file, array $field ) {
        // PHP's own upload outcome first. A file over the server's limit
        // arrives with no temporary file; it used to fall through to the type
        // check and reach the visitor as "Unable to verify file type."
        if ( isset( $file['error'] ) && UPLOAD_ERR_OK !== (int) $file['error'] ) {
            return new WP_Error( 'upload_error', $this->get_upload_error_message( (int) $file['error'] ) );
        }

        $file_field    = new PForms_Field_File();
        $allowed_types = $file_field->get_allowed_types( $field );

        // Validate and sanitize filename first (catches double extensions).
        $sanitized_filename = $this->validate_and_sanitize_filename( $file['name'], $allowed_types );
        if ( is_wp_error( $sanitized_filename ) ) {
            return $sanitized_filename;
        }

        // Get true extension.
        $ext = strtolower( pathinfo( $sanitized_filename, PATHINFO_EXTENSION ) );

        // Check blocked extensions.
        if ( in_array( $ext, $this->blocked_extensions( $allowed_types ), true ) ) {
            return new WP_Error(
                'blocked_extension',
                __( 'File type not allowed.', 'promptless-forms' )
            );
        }

        // Check extension against allowed types.
        if ( ! in_array( $ext, $allowed_types, true ) ) {
            return new WP_Error(
                'extension_not_allowed',
                sprintf(
                    /* translators: %s: allowed file types */
                    __( 'This type of file is not accepted here. Allowed file types: %s', 'promptless-forms' ),
                    implode( ', ', $allowed_types )
                )
            );
        }

        // Validate file size.
        $size_validation = $this->validate_file_size( $file, $file_field->get_max_size( $field ) );
        if ( is_wp_error( $size_validation ) ) {
            return $size_validation;
        }

        // Full inspection before anything is stored. Until 1.11.0 only the
        // content type was checked here and the rest ran after the entry was
        // written, so a refused file created an entry and then deleted it.
        $inspection = $this->inspect_file( $file['tmp_name'], $ext, $field );
        if ( is_wp_error( $inspection ) ) {
            $this->log_upload_rejection( $file['name'], $inspection->get_error_code() );
            return $inspection;
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
                    __( 'This file is larger than %s. Please choose a smaller file.', 'promptless-forms' ),
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
                    sprintf(
                        /* translators: %s: max file size */
                        __( 'This file is larger than %s. Please choose a smaller file.', 'promptless-forms' ),
                        size_format( $max_bytes )
                    )
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
    public function generate_secure_filename( $original_filename, $extension = '' ) {
        $ext = '' !== (string) $extension ? strtolower( (string) $extension ) : strtolower( pathinfo( $original_filename, PATHINFO_EXTENSION ) );

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
        $server_limit = function_exists( 'wp_max_upload_size' ) ? size_format( wp_max_upload_size() ) : '';

        $messages = array(
            UPLOAD_ERR_INI_SIZE   => '' !== $server_limit
                /* translators: %s: the server's upload limit, e.g. 64 MB */
                ? sprintf( __( 'This file is larger than this website accepts (%s). Please choose a smaller file.', 'promptless-forms' ), $server_limit )
                : __( 'This file is larger than this website accepts. Please choose a smaller file.', 'promptless-forms' ),
            UPLOAD_ERR_FORM_SIZE  => __( 'This file is larger than this form accepts. Please choose a smaller file.', 'promptless-forms' ),
            UPLOAD_ERR_PARTIAL    => __( 'The file did not finish uploading. Please check your connection and try again.', 'promptless-forms' ),
            UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'promptless-forms' ),
            UPLOAD_ERR_NO_TMP_DIR => __( 'The website could not receive your file. Please try again later.', 'promptless-forms' ),
            UPLOAD_ERR_CANT_WRITE => __( 'The website could not save your file. Please try again later.', 'promptless-forms' ),
            UPLOAD_ERR_EXTENSION  => __( 'The website blocked this upload. Please try a different file.', 'promptless-forms' ),
        );

        return isset( $messages[ $error_code ] )
            ? $messages[ $error_code ]
            : __( 'Your file could not be uploaded. Please try again.', 'promptless-forms' );
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
            PForms_Logger::warning( sprintf(
                'Disk space check failed. Required: %s, Available: %s',
                size_format( $minimum_required ),
                size_format( $free_space )
            ) );

            return new WP_Error(
                'disk_full',
                sprintf(
                    /* translators: %s: available disk space */
                    __( 'Insufficient disk space. Only %s available.', 'promptless-forms' ),
                    size_format( $free_space )
                )
            );
        }

        return true;
    }
}
