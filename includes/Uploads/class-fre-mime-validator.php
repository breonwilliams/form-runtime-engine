<?php
/**
 * MIME Type Validator for Form Runtime Engine.
 *
 * NOTE: This file uses low-level file operations (fopen, fread, fclose) intentionally.
 * These are required for binary-mode file scanning and MIME type detection.
 * WP_Filesystem is not suitable because:
 * 1. It lacks binary mode support needed for accurate magic byte detection
 * 2. Security scanning requires direct byte-level access
 * 3. Performance: scanning large files in chunks requires efficient I/O
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * MIME type validation handler.
 */
class FRE_Mime_Validator {

    /**
     * Magic bytes for file type verification (Fix #6: Polyglot detection).
     *
     * @var array
     */
    private const MAGIC_BYTES = array(
        // Images.
        'jpg'  => array( array( 0xFF, 0xD8, 0xFF ) ),
        'jpeg' => array( array( 0xFF, 0xD8, 0xFF ) ),
        'png'  => array( array( 0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A ) ),
        'gif'  => array( array( 0x47, 0x49, 0x46, 0x38 ) ),
        'webp' => array( array( 0x52, 0x49, 0x46, 0x46 ) ), // RIFF header.
        'bmp'  => array( array( 0x42, 0x4D ) ),

        // Fix #6: Add magic bytes for document formats.
        'pdf'  => array( array( 0x25, 0x50, 0x44, 0x46 ) ),                    // %PDF
        'doc'  => array( array( 0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1 ) ), // OLE Compound Document
        'xls'  => array( array( 0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1 ) ), // OLE Compound Document
        'ppt'  => array( array( 0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1 ) ), // OLE Compound Document
        'docx' => array( array( 0x50, 0x4B, 0x03, 0x04 ) ),                    // ZIP (OOXML)
        'xlsx' => array( array( 0x50, 0x4B, 0x03, 0x04 ) ),                    // ZIP (OOXML)
        'pptx' => array( array( 0x50, 0x4B, 0x03, 0x04 ) ),                    // ZIP (OOXML)
        'odt'  => array( array( 0x50, 0x4B, 0x03, 0x04 ) ),                    // ZIP (ODF)
        'ods'  => array( array( 0x50, 0x4B, 0x03, 0x04 ) ),                    // ZIP (ODF)
        'zip'  => array( array( 0x50, 0x4B, 0x03, 0x04 ), array( 0x50, 0x4B, 0x05, 0x06 ) ),
        'rar'  => array( array( 0x52, 0x61, 0x72, 0x21, 0x1A, 0x07 ) ),        // Rar!

        // Design file formats (added 1.5.0 for screen-printing / embroidery / agency clients).
        // Modern .ai files (Illustrator CS3+, ~2007) are PDF-compatible and start with %PDF.
        // Legacy .ai files use the PostScript header %!PS-Adobe.
        'ai'   => array(
            array( 0x25, 0x50, 0x44, 0x46 ),                                   // %PDF (modern AI)
            array( 0x25, 0x21, 0x50, 0x53 ),                                   // %!PS (legacy PostScript-based AI)
        ),
        // EPS (Encapsulated PostScript) always starts with %!PS-Adobe.
        'eps'  => array( array( 0x25, 0x21, 0x50, 0x53 ) ),                    // %!PS
        // DST (Tajima embroidery) starts with "LA:" header followed by metadata.
        // Some older DST files have alternate headers — the verify_magic_bytes
        // method gracefully passes when no signature matches if the entry's
        // not in this table at all, so worst case for an unusual variant is
        // we skip magic-byte verification and rely on extension + dangerous
        // pattern scan. Conservative default below covers standard Tajima output.
        'dst'  => array( array( 0x4C, 0x41, 0x3A ) ),                          // LA:

        // Audio/Video.
        'mp3'  => array(
            array( 0x49, 0x44, 0x33 ),                                          // ID3
            array( 0xFF, 0xFB ),                                                // MP3 frame sync
            array( 0xFF, 0xFA ),                                                // MP3 frame sync
        ),
        'mp4'  => array( array( 0x00, 0x00, 0x00 ) ),                          // ftyp at offset 4
        'wav'  => array( array( 0x52, 0x49, 0x46, 0x46 ) ),                    // RIFF
    );

    /**
     * Dangerous patterns to scan for in files (Fix #6 & #12: Polyglot detection).
     *
     * @var array
     */
    private const DANGEROUS_PATTERNS = array(
        // PHP opening tags.
        '<?php',
        '<?=',
        '<? ',
        '<%',

        // HTML/JavaScript.
        '<script',

        // PHP dangerous functions.
        '__halt_compiler',
        'eval(',
        'exec(',
        'system(',
        'passthru(',
        'shell_exec(',
        'popen(',
        'proc_open(',
        'base64_decode(',
        'assert(',
        'create_function(',
        'call_user_func',

        // Fix #12: Additional dangerous patterns.
        '$$',                    // Variable variables - can be used for code injection.
        'extract(',              // Can overwrite variables.
        'parse_str(',            // Can overwrite variables without second param.
        'include(',
        'include_once(',
        'require(',
        'require_once(',
        'file_get_contents(',
        'file_put_contents(',
        'fwrite(',
        'fputs(',
        'fopen(',
        'curl_exec(',
        'ReflectionFunction',
        'preg_replace_callback(',
    );

    /**
     * Extension to MIME type mapping.
     *
     * @var array
     */
    private $mime_map = array(
        // Images.
        'jpg'  => array( 'image/jpeg' ),
        'jpeg' => array( 'image/jpeg' ),
        'png'  => array( 'image/png' ),
        'gif'  => array( 'image/gif' ),
        'webp' => array( 'image/webp' ),
        'bmp'  => array( 'image/bmp', 'image/x-ms-bmp' ),

        // Documents.
        'pdf'  => array( 'application/pdf' ),
        'doc'  => array( 'application/msword' ),
        'docx' => array( 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
        'xls'  => array( 'application/vnd.ms-excel' ),
        'xlsx' => array( 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
        'ppt'  => array( 'application/vnd.ms-powerpoint' ),
        'pptx' => array( 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ),
        'odt'  => array( 'application/vnd.oasis.opendocument.text' ),
        'ods'  => array( 'application/vnd.oasis.opendocument.spreadsheet' ),

        // Text.
        'txt'  => array( 'text/plain' ),
        'csv'  => array( 'text/csv', 'text/plain' ),
        'rtf'  => array( 'application/rtf', 'text/rtf' ),

        // Archives.
        'zip'  => array( 'application/zip', 'application/x-zip-compressed' ),
        'rar'  => array( 'application/x-rar-compressed', 'application/vnd.rar' ),

        // Audio.
        'mp3'  => array( 'audio/mpeg' ),
        'wav'  => array( 'audio/wav', 'audio/x-wav' ),
        'ogg'  => array( 'audio/ogg' ),

        // Video.
        'mp4'  => array( 'video/mp4' ),
        'webm' => array( 'video/webm' ),
        'mov'  => array( 'video/quicktime' ),
        'avi'  => array( 'video/x-msvideo' ),

        // Design file formats (screen printing, embroidery, agencies).
        // Modern .ai files are PDF-compatible; older variants are PostScript.
        // Browsers / OSes inconsistently report these — accept the common set.
        'ai'   => array( 'application/pdf', 'application/postscript', 'application/illustrator', 'application/octet-stream' ),
        'eps'  => array( 'application/postscript', 'application/eps', 'image/eps', 'image/x-eps', 'application/octet-stream' ),
        // DST (Tajima) has no IANA-registered MIME; many clients emit octet-stream.
        'dst'  => array( 'application/octet-stream', 'application/x-tajima-dst' ),
    );

    /**
     * Constructor — applies the `fre_mime_map` filter so sites can register
     * custom MIME mappings without forking this class.
     *
     * Usage:
     *   add_filter( 'fre_mime_map', function ( $map ) {
     *       $map['dwg'] = array( 'application/acad', 'image/vnd.dwg', 'application/octet-stream' );
     *       return $map;
     *   } );
     *
     * Sites that add custom extensions should also consider the magic-bytes
     * verification path: if the extension is not in MAGIC_BYTES, the strict
     * verify_magic_bytes() check returns true (skipped), and validation falls
     * back to the dangerous-pattern scan. That is the safe default for binary
     * formats whose magic bytes are stable but unique to the format.
     *
     * @since 1.5.0
     */
    public function __construct() {
        /**
         * Filter the extension → MIME-types map used to validate file uploads.
         *
         * @param array $mime_map Map of lowercase extension → array of allowed MIME types.
         */
        $this->mime_map = (array) apply_filters( 'fre_mime_map', $this->mime_map );
    }

    /**
     * Get allowed MIME types for extensions.
     *
     * @param array $extensions Array of file extensions.
     * @return array Array of allowed MIME types.
     */
    public function get_allowed_mimes( array $extensions ) {
        $mimes = array();

        foreach ( $extensions as $ext ) {
            $ext = strtolower( $ext );
            if ( isset( $this->mime_map[ $ext ] ) ) {
                $mimes = array_merge( $mimes, $this->mime_map[ $ext ] );
            }
        }

        return array_unique( $mimes );
    }

    /**
     * Detect MIME type using finfo.
     *
     * @param string $file_path Path to file.
     * @return string|false MIME type or false on failure.
     */
    public function detect_mime( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return false;
        }

        // Use finfo (most reliable).
        if ( function_exists( 'finfo_open' ) ) {
            $finfo = finfo_open( FILEINFO_MIME_TYPE );
            $mime  = finfo_file( $finfo, $file_path );
            // Note: finfo_close() was historically called here but was
            // deprecated in PHP 8.5 — finfo objects are freed
            // automatically when they go out of scope (which happens
            // immediately after this block since $finfo is local).
            // Skipping the explicit close keeps the code forward-
            // compatible with PHP 9 and silent on PHP 8.5+, while
            // still working correctly on PHP 7.4 / 8.0–8.4.
            unset( $finfo );

            if ( $mime ) {
                return $mime;
            }
        }

        // Fallback to mime_content_type.
        if ( function_exists( 'mime_content_type' ) ) {
            return mime_content_type( $file_path );
        }

        return false;
    }

    /**
     * Validate that detected MIME matches allowed types.
     *
     * @param string $file_path        Path to file.
     * @param array  $allowed_extensions Allowed file extensions.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate( $file_path, array $allowed_extensions ) {
        $detected_mime = $this->detect_mime( $file_path );

        if ( $detected_mime === false ) {
            return new WP_Error(
                'mime_detection_failed',
                __( 'Unable to verify file type.', 'form-runtime-engine' )
            );
        }

        $allowed_mimes = $this->get_allowed_mimes( $allowed_extensions );

        if ( ! in_array( $detected_mime, $allowed_mimes, true ) ) {
            return new WP_Error(
                'mime_mismatch',
                __( 'File content does not match allowed types.', 'form-runtime-engine' )
            );
        }

        return true;
    }

    /**
     * Get extension for MIME type.
     *
     * @param string $mime MIME type.
     * @return string|null Extension or null if not found.
     */
    public function get_extension_for_mime( $mime ) {
        foreach ( $this->mime_map as $ext => $mimes ) {
            if ( in_array( $mime, $mimes, true ) ) {
                return $ext;
            }
        }

        return null;
    }

    /**
     * Check if MIME type is an image.
     *
     * @param string $mime MIME type.
     * @return bool
     */
    public function is_image( $mime ) {
        return strpos( $mime, 'image/' ) === 0;
    }

    /**
     * Check if MIME type is a document.
     *
     * @param string $mime MIME type.
     * @return bool
     */
    public function is_document( $mime ) {
        $doc_mimes = array_merge(
            $this->mime_map['pdf'],
            $this->mime_map['doc'],
            $this->mime_map['docx'],
            $this->mime_map['txt'],
            $this->mime_map['rtf']
        );

        return in_array( $mime, $doc_mimes, true );
    }

    /**
     * Add custom MIME type mapping.
     *
     * @param string $extension File extension.
     * @param array  $mimes     Array of MIME types.
     */
    public function add_mime_mapping( $extension, array $mimes ) {
        $this->mime_map[ strtolower( $extension ) ] = $mimes;
    }

    /**
     * Get all supported extensions.
     *
     * @return array
     */
    public function get_supported_extensions() {
        return array_keys( $this->mime_map );
    }

    /**
     * Dangerous SVG patterns for validation.
     *
     * @var array
     */
    private const SVG_DANGEROUS_PATTERNS = array(
        '/<script/i',
        '/on\w+\s*=/i',          // Event handlers (onclick, onload, etc.).
        '/javascript\s*:/i',      // JavaScript URLs.
        '/<foreignObject/i',      // Foreign object can embed HTML.
        '/<embed/i',
        '/<object/i',
        '/<iframe/i',
        '/<\?/i',                 // PHP tags.
        '/<%/i',                  // ASP tags.
        '/<!ENTITY/i',            // XXE attacks.
        '/xlink:href\s*=\s*["\']?\s*(javascript|data):/i',
        '/href\s*=\s*["\']?\s*(javascript|data):/i',
        '/data\s*:\s*text\/html/i',
        '/set\s*=.*javascript/i',
        '/animate\s*.*on/i',
    );

    /**
     * Validate SVG content for dangerous elements (Fix #4: SVG Content Validation).
     *
     * Performance fix: Uses chunked reading instead of loading entire file into memory.
     * This prevents memory spikes for large SVG files.
     *
     * @param string $file_path Path to SVG file.
     * @return bool|WP_Error True if safe, WP_Error if dangerous content detected.
     */
    public function validate_svg( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'File not found.', 'form-runtime-engine' ) );
        }

        $handle = fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'read_failed', __( 'Unable to read file.', 'form-runtime-engine' ) );
        }

        // Use overlapping chunks to catch patterns split across chunk boundaries.
        // SVG patterns can be up to ~50 characters, so 100 byte overlap is safe.
        $chunk_size   = 8192;
        $overlap_size = 100;
        $prev_chunk   = '';

        while ( ! feof( $handle ) ) {
            $new_data = fread( $handle, $chunk_size );
            if ( $new_data === false ) {
                break;
            }

            // Combine overlap from previous chunk with new data.
            $chunk = $prev_chunk . $new_data;

            // Check for dangerous patterns.
            foreach ( self::SVG_DANGEROUS_PATTERNS as $pattern ) {
                if ( preg_match( $pattern, $chunk ) ) {
                    fclose( $handle );
                    return new WP_Error( 'svg_malicious', __( 'SVG contains dangerous content.', 'form-runtime-engine' ) );
                }
            }

            // Keep last N bytes for overlap with next chunk.
            $prev_chunk = strlen( $chunk ) > $overlap_size
                ? substr( $chunk, -$overlap_size )
                : $chunk;
        }

        fclose( $handle );
        return true;
    }

    /**
     * Scan file for dangerous patterns (Fix #6 & #12: Polyglot detection with overlapping chunks).
     *
     * @param string $file_path Path to file.
     * @return bool|WP_Error True if safe, WP_Error if dangerous patterns found.
     */
    public function scan_for_dangerous_patterns( $file_path ) {
        if ( ! file_exists( $file_path ) ) {
            return new WP_Error( 'file_not_found', __( 'File not found.', 'form-runtime-engine' ) );
        }

        $handle = fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'read_failed', __( 'Unable to read file.', 'form-runtime-engine' ) );
        }

        // Fix #12: Use overlapping chunks to catch patterns split across chunk boundaries.
        // Keep last 100 bytes of previous chunk to prepend to next chunk.
        $overlap_size = 100;
        $prev_chunk = '';

        // Read file in chunks to handle large files.
        while ( ! feof( $handle ) ) {
            $new_data = fread( $handle, 8192 );
            if ( $new_data === false ) {
                break;
            }

            // Combine overlap from previous chunk with new data.
            $chunk = $prev_chunk . $new_data;

            // Check for dangerous patterns.
            foreach ( self::DANGEROUS_PATTERNS as $pattern ) {
                // Use case-insensitive search for most patterns.
                if ( stripos( $chunk, $pattern ) !== false ) {
                    fclose( $handle );
                    return new WP_Error(
                        'dangerous_content',
                        __( 'File contains potentially dangerous content.', 'form-runtime-engine' )
                    );
                }
            }

            // Check for regex patterns - preg_replace with /e modifier.
            if ( preg_match( '/preg_replace\s*\([^)]*\/[^\/]*e[^\/]*\//i', $chunk ) ) {
                fclose( $handle );
                return new WP_Error(
                    'dangerous_content',
                    __( 'File contains potentially dangerous content.', 'form-runtime-engine' )
                );
            }

            // Fix #12: Check for mb_ereg_replace with 'e' modifier.
            if ( preg_match( '/mb_ereg_replace\s*\([^)]*[\'"]e[\'"]/i', $chunk ) ) {
                fclose( $handle );
                return new WP_Error(
                    'dangerous_content',
                    __( 'File contains potentially dangerous content.', 'form-runtime-engine' )
                );
            }

            // Fix #12: Check for encoded payloads (base64 of common patterns).
            // PD9waHA= is base64 for "<?php"
            if ( stripos( $chunk, 'PD9waHA' ) !== false ) {
                fclose( $handle );
                return new WP_Error(
                    'dangerous_content',
                    __( 'File contains potentially dangerous encoded content.', 'form-runtime-engine' )
                );
            }

            // Keep last N bytes for overlap with next chunk.
            $prev_chunk = strlen( $chunk ) > $overlap_size
                ? substr( $chunk, -$overlap_size )
                : $chunk;
        }

        fclose( $handle );
        return true;
    }

    /**
     * Verify file magic bytes match expected format (Fix #6: Polyglot detection).
     *
     * @param string $file_path Path to file.
     * @param string $extension Expected file extension.
     * @return bool|WP_Error True if valid, WP_Error if mismatch.
     */
    public function verify_magic_bytes( $file_path, $extension ) {
        $extension = strtolower( $extension );

        /**
         * Filter the magic-byte signature table for a given extension.
         *
         * Receives the array of valid signature byte sequences (each an array
         * of integers) for the given extension, or an empty array if no
         * default signatures exist. Returning an empty array causes the
         * verification step to be skipped for that extension and validation
         * falls back to the extension-MIME-match + dangerous-pattern scan.
         *
         * @since 1.5.0
         *
         * @param array  $signatures Array of valid signature byte sequences.
         * @param string $extension  Lowercase file extension being verified.
         */
        $valid_signatures = isset( self::MAGIC_BYTES[ $extension ] ) ? self::MAGIC_BYTES[ $extension ] : array();
        $valid_signatures = (array) apply_filters( 'fre_magic_bytes', $valid_signatures, $extension );

        // Skip if we don't have magic bytes for this extension.
        if ( empty( $valid_signatures ) ) {
            return true;
        }

        $handle = fopen( $file_path, 'rb' );
        if ( ! $handle ) {
            return new WP_Error( 'read_failed', __( 'Unable to read file.', 'form-runtime-engine' ) );
        }

        // Read first 8 bytes (max magic byte length).
        $header = fread( $handle, 8 );
        fclose( $handle );

        if ( $header === false || strlen( $header ) < 2 ) {
            return new WP_Error( 'invalid_file', __( 'Invalid file format.', 'form-runtime-engine' ) );
        }

        // Convert header to byte array.
        $header_bytes = array_values( unpack( 'C*', $header ) );

        // Check against known magic bytes (potentially extended via filter above).
        foreach ( $valid_signatures as $signature ) {
            $match = true;
            for ( $i = 0; $i < count( $signature ); $i++ ) {
                if ( ! isset( $header_bytes[ $i ] ) || $header_bytes[ $i ] !== $signature[ $i ] ) {
                    $match = false;
                    break;
                }
            }

            if ( $match ) {
                return true;
            }
        }

        return new WP_Error(
            'magic_bytes_mismatch',
            __( 'File content does not match expected format.', 'form-runtime-engine' )
        );
    }
}
