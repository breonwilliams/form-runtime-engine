<?php
/**
 * MIME Type Validator for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
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
    );

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
            finfo_close( $finfo );

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
}
