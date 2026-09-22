<?php
/**
 * Upload inspector for Promptless Forms.
 *
 * Decides whether an uploaded file is what its name says it is and safe to
 * keep, with checks chosen per format instead of one byte scan for all.
 *
 * WHY THE BYTE SCAN WAS REPLACED
 *
 * Until 1.11.0 every upload was searched for code-like strings, including
 * 2-3 byte ones (`<%`, `$$`, `<?=`). Compressed image, PDF and Illustrator
 * data contain those by chance. Measured on 331 real artwork files plus 72
 * Illustrator exports: 83% of the real files and every Illustrator export
 * were refused as "potentially dangerous content" — every EPS with a preview
 * (its binary header was not recognised), every SVG (blocklisted outright),
 * and most JPGs, PDFs and AI files. A print shop's customers could not send
 * their artwork.
 *
 * WHAT PROTECTS THE SITE NOW
 *
 *   1. The extension must be one the field allows (upload handler).
 *   2. The detected content type must match the extension. When it does not,
 *      but the real type is ALSO allowed — a PNG saved with a .jpg name — the
 *      file is accepted under its correct extension, as WordPress core does.
 *   3. The header must match the format (magic bytes; DOS EPS supported).
 *   4. Raster images must parse as images.
 *   5. In every file: the two PHP markers that do not occur by chance —
 *      `<?php` followed by whitespace, and the PHAR stub `__halt_compiler(`.
 *      Measured: 0 of 424 genuine files contain either.
 *   6. In JPEG/PNG, where text can hide (metadata segments and data after the
 *      image ends): any PHP open tag, including `<?=` and `<? `. Appended
 *      media that phones write (motion-photo video, Ultra HDR gain maps) is
 *      recognised and only code-shaped PHP with a closing tag is refused
 *      there, because random video bytes contain `<? ` often.
 *   7. SVG is parsed as XML and refused if it carries active content:
 *      scripts, event handlers, javascript:/data: links, external entities,
 *      embedded HTML. Illustrator's own editing data is allowed.
 *   8. Stored files get a random name with the (corrected) allowed extension,
 *      so nothing uploaded can run as PHP by its name (upload handler).
 *
 * The repeatable measurement is tests/uploads/matrix.php.
 *
 * @package FormRuntimeEngine
 * @since   1.11.0
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Format-aware inspection of an uploaded file.
 *
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fread
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fclose
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
 */
class PForms_Upload_Inspector {

    /**
     * `<?php` followed by whitespace or the end of the file: what PHP needs
     * to start executing. Never found by chance in the measured corpus.
     */
    const PHP_OPEN = '/<\?php(?=\s|$)/i';

    /**
     * The PHAR stub terminator.
     */
    const PHAR_STUB = '/__halt_compiler\s*\(/i';

    /**
     * Any PHP open tag. Only used where text lives (image metadata, bytes
     * appended after an image), never on compressed data.
     */
    const PHP_ANY_TAG = '/<\?(?:php|=|\s)/i';

    /**
     * Code-shaped PHP with a closing tag: what an appended payload needs to
     * run, and what random media bytes do not produce.
     */
    const PHP_CODE_SHAPED = '/<\?(?:=|\s)\s*(?:\$[a-z_]|`|[a-z_][a-z0-9_]*\s*\()[^\x00]{0,200}?\?>/i';

    /**
     * Raster formats whose structure getimagesize() can confirm.
     */
    const RASTER = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp' );

    /**
     * Real content type → the extension a mislabelled file is stored under
     * when that extension is also allowed.
     */
    const CORRECTABLE = array(
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/gif'       => 'gif',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    );

    /**
     * SVG elements that run code or embed a document.
     */
    const SVG_ACTIVE_ELEMENTS = array( 'script', 'iframe', 'embed', 'object', 'handler', 'listener', 'frame', 'applet', 'base', 'link', 'meta' );

    /**
     * SVG animation elements (they can rewrite links at run time).
     */
    const SVG_ANIMATION_ELEMENTS = array( 'animate', 'set', 'animatemotion', 'animatetransform', 'animatecolor' );

    /**
     * Upper bound on internal entity declarations in an SVG.
     */
    const SVG_MAX_ENTITIES = 64;

    /**
     * MIME validator (content-type detection, extension map, magic bytes).
     *
     * @var PForms_Mime_Validator
     */
    private $mime;

    /**
     * Constructor.
     *
     * @param PForms_Mime_Validator|null $mime MIME validator (injected in tests).
     */
    public function __construct( $mime = null ) {
        $this->mime = $mime ? $mime : new PForms_Mime_Validator();
    }

    /**
     * Inspect a file.
     *
     * @param string $path          Path to the file on disk.
     * @param string $ext           Extension from the visitor's file name.
     * @param array  $allowed_types Extensions the field allows (lowercase, no dot).
     * @return array|WP_Error array( 'ext' => extension to store under, 'mime' => detected type ), or an error whose message a visitor can act on.
     */
    public function inspect( $path, $ext, array $allowed_types ) {
        $ext           = strtolower( (string) $ext );
        $allowed_types = array_map( 'strtolower', $allowed_types );

        if ( ! is_readable( $path ) ) {
            return $this->error( 'read_failed', __( 'We could not read this file. Please try again.', 'promptless-forms' ) );
        }

        // 1-2. Content type against extension, correcting a mislabelled file.
        $mime = $this->mime->detect_mime( $path );
        if ( false === $mime ) {
            return $this->error( 'mime_detection_failed', __( 'We could not tell what kind of file this is. Please save it again or choose a different file.', 'promptless-forms' ) );
        }

        if ( ! in_array( $mime, $this->mime->get_allowed_mimes( array( $ext ) ), true ) ) {
            $real = isset( self::CORRECTABLE[ $mime ] ) ? self::CORRECTABLE[ $mime ] : '';
            if ( '' === $real || ! $this->allows( $allowed_types, $real ) ) {
                return $this->error(
                    'content_type_mismatch',
                    /* translators: %s: file extension, e.g. PNG */
                    sprintf( __( 'This file is not really a %s file. Please save or export it again, or choose a different file.', 'promptless-forms' ), strtoupper( $ext ) )
                );
            }
            $ext = $real;
        }

        // 3. Header.
        $signature = $this->check_signature( $path, $ext );
        if ( is_wp_error( $signature ) ) {
            return $signature;
        }

        // 4. Raster structure.
        if ( in_array( $ext, self::RASTER, true ) && false === @getimagesize( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
            return $this->error( 'not_a_valid_image', __( 'This image appears to be damaged and cannot be opened. Please save it again or choose a different file.', 'promptless-forms' ) );
        }

        // 5. Every format: the markers that never occur by chance.
        if ( $this->stream_matches( $path, array( self::PHP_OPEN, self::PHAR_STUB ) ) ) {
            return $this->code_error( 'executable_code' );
        }

        // 6. JPEG/PNG: text-bearing regions.
        if ( in_array( $ext, array( 'jpg', 'jpeg', 'png' ), true ) && ! $this->raster_regions_clean( (string) file_get_contents( $path ), $ext, 0 ) ) {
            return $this->code_error( 'code_in_image_metadata' );
        }

        // 7. SVG: parse, then refuse active content.
        if ( 'svg' === $ext ) {
            $svg = $this->inspect_svg( $path );
            if ( is_wp_error( $svg ) ) {
                return $svg;
            }
        }

        return array(
            'ext'  => $ext,
            'mime' => $mime,
        );
    }

    /**
     * Whether the field allows an extension (jpg and jpeg are one format).
     *
     * @param array  $allowed Allowed extensions.
     * @param string $ext     Extension.
     * @return bool
     */
    private function allows( array $allowed, $ext ) {
        if ( in_array( $ext, $allowed, true ) ) {
            return true;
        }
        return 'jpg' === $ext && in_array( 'jpeg', $allowed, true );
    }

    /**
     * Check the header against the format.
     *
     * @param string $path File path.
     * @param string $ext  Extension (after correction).
     * @return true|WP_Error
     */
    private function check_signature( $path, $ext ) {
        $invalid = $this->error(
            'signature_mismatch',
            /* translators: %s: file extension, e.g. EPS */
            sprintf( __( 'This file does not look like a valid %s file. Please export it again or choose a different file.', 'promptless-forms' ), strtoupper( $ext ) )
        );

        $magic = $this->mime->verify_magic_bytes( $path, $ext );
        if ( is_wp_error( $magic ) ) {
            return $invalid;
        }

        // DOS EPS (what Illustrator writes with a preview): the header's
        // bytes 4-7 give the offset of the PostScript section, which must
        // really be PostScript.
        if ( 'eps' === $ext ) {
            $head = (string) file_get_contents( $path, false, null, 0, 8 );
            if ( 0 === strpos( $head, "\xC5\xD0\xD3\xC6" ) ) {
                $offset = unpack( 'V', substr( $head, 4, 4 ) );
                $ps     = (string) file_get_contents( $path, false, null, (int) $offset[1], 4 );
                if ( '%!PS' !== $ps ) {
                    return $invalid;
                }
            }
        }

        return true;
    }

    /**
     * Stream the file in overlapping chunks and test patterns.
     *
     * @param string $path     File path.
     * @param array  $patterns Regular expressions.
     * @return bool True when any pattern matches.
     */
    private function stream_matches( $path, array $patterns ) {
        $handle = fopen( $path, 'rb' );
        if ( ! $handle ) {
            return false;
        }

        $tail = '';
        while ( ! feof( $handle ) ) {
            $chunk = $tail . (string) fread( $handle, 1048576 );
            foreach ( $patterns as $pattern ) {
                if ( preg_match( $pattern, $chunk ) ) {
                    fclose( $handle );
                    return true;
                }
            }
            // Longer than any marker, so none can straddle two chunks unseen.
            $tail = substr( $chunk, -64 );
        }

        fclose( $handle );
        return false;
    }

    /**
     * Whether a JPEG/PNG's metadata and trailing data are free of PHP.
     *
     * @param string $data  File contents.
     * @param string $ext   jpg, jpeg or png.
     * @param int    $depth Nesting depth (a JPEG appended to a JPEG).
     * @return bool
     */
    private function raster_regions_clean( $data, $ext, $depth ) {
        list( $metadata, $trailer ) = 'png' === $ext ? $this->png_regions( $data ) : $this->jpeg_regions( $data );

        foreach ( $metadata as $region ) {
            if ( preg_match( self::PHP_ANY_TAG, $region ) ) {
                return false;
            }
        }

        if ( '' === $trailer ) {
            return true;
        }

        // A second JPEG after the first (multi-picture files, Ultra HDR gain
        // maps): inspect it the same way.
        if ( "\xFF\xD8\xFF" === substr( $trailer, 0, 3 ) && $depth < 4 ) {
            return $this->raster_regions_clean( $trailer, 'jpg', $depth + 1 );
        }

        $is_media = 'ftyp' === substr( $trailer, 4, 4 )                             // Motion photo (MP4 appended).
            || false !== strpos( substr( $trailer, 0, 4096 ), 'MotionPhoto_Data' ) // Samsung motion photo.
            || 'SEFT' === substr( $trailer, -4 );                                   // Samsung trailer footer.

        return ! preg_match( $is_media ? self::PHP_CODE_SHAPED : self::PHP_ANY_TAG, $trailer );
    }

    /**
     * PNG text chunks and anything after IEND.
     *
     * @param string $data File contents.
     * @return array array( string[] $metadata, string $trailer ).
     */
    private function png_regions( $data ) {
        $metadata = array();
        $trailer  = '';
        $length   = strlen( $data );
        $i        = 8;

        while ( $i + 8 <= $length ) {
            $size = unpack( 'N', substr( $data, $i, 4 ) );
            $size = (int) $size[1];
            $type = substr( $data, $i + 4, 4 );
            $body = (string) substr( $data, $i + 8, $size );

            if ( in_array( $type, array( 'tEXt', 'iTXt', 'eXIf' ), true ) ) {
                $metadata[] = $body;
            } elseif ( 'zTXt' === $type ) {
                $null = strpos( $body, "\0" );
                if ( false !== $null ) {
                    $text = @gzuncompress( substr( $body, $null + 2 ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                    if ( false !== $text ) {
                        $metadata[] = $text;
                    }
                }
            }

            $i += 12 + $size;
            if ( 'IEND' === $type ) {
                $trailer = (string) substr( $data, $i );
                break;
            }
        }

        return array( $metadata, $trailer );
    }

    /**
     * JPEG APPn/COM segments and anything after the end-of-image marker.
     *
     * @param string $data File contents.
     * @return array array( string[] $metadata, string $trailer ).
     */
    private function jpeg_regions( $data ) {
        $metadata = array();
        $trailer  = '';
        $length   = strlen( $data );
        $i        = 2;

        while ( $i + 4 <= $length && "\xFF" === $data[ $i ] ) {
            $marker = ord( $data[ $i + 1 ] );

            // Markers without a length.
            if ( 0xD8 === $marker || 0x01 === $marker || ( $marker >= 0xD0 && $marker <= 0xD7 ) || 0xFF === $marker ) {
                $i += ( 0xFF === $marker ) ? 1 : 2;
                continue;
            }

            $size = unpack( 'n', substr( $data, $i + 2, 2 ) );
            $size = (int) $size[1];

            if ( ( $marker >= 0xE0 && $marker <= 0xEF ) || 0xFE === $marker ) {
                $metadata[] = (string) substr( $data, $i + 4, $size - 2 );
            }

            $i += 2 + $size;

            // Start of scan: entropy-coded data runs to the end-of-image
            // marker (a real FF inside the data is byte-stuffed as FF 00).
            if ( 0xDA === $marker ) {
                $end = strpos( $data, "\xFF\xD9", $i );
                if ( false !== $end ) {
                    $trailer = (string) substr( $data, $end + 2 );
                }
                break;
            }
        }

        return array( $metadata, $trailer );
    }

    /**
     * Parse an SVG and refuse active content.
     *
     * @param string $path File path.
     * @return true|WP_Error
     */
    private function inspect_svg( $path ) {
        $xml      = (string) file_get_contents( $path );
        $active   = $this->error( 'svg_active_content', __( 'This SVG contains scripts or links to other files, which we cannot accept for security reasons. Please export it again as a plain SVG, or send a PDF or PNG instead.', 'promptless-forms' ) );
        $entities = array();

        // Internal DTD subset: only plain string entities, which is what
        // Illustrator writes. No external or parameter entities, no nesting.
        if ( preg_match( '/<!DOCTYPE[^\[>]*\[(.*?)\]\s*>/s', $xml, $doctype ) ) {
            if ( preg_match( '/<!ENTITY\s+%|<!ENTITY\s+\S+\s+(?:SYSTEM|PUBLIC)\b/i', $doctype[1] ) ) {
                return $active;
            }
            if ( preg_match_all( '/<!ENTITY\s+(\S+)\s+(["\'])(.*?)\2\s*>/s', $doctype[1], $declared, PREG_SET_ORDER ) ) {
                if ( count( $declared ) > self::SVG_MAX_ENTITIES ) {
                    return $active;
                }
                foreach ( $declared as $entity ) {
                    if ( false !== strpos( $entity[3], '&' ) || false !== strpos( $entity[3], '<' ) ) {
                        return $active;
                    }
                    $entities[ '&' . $entity[1] . ';' ] = $entity[3];
                }
            }
        }

        $previous = libxml_use_internal_errors( true );
        $dom      = new DOMDocument();
        $loaded   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_COMPACT );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );

        if ( ! $loaded || ! $dom->documentElement || 'svg' !== strtolower( $dom->documentElement->localName ) ) {
            return $this->error( 'not_a_valid_svg', __( 'This SVG file appears to be damaged and cannot be opened. Please export it again, or send a PDF or PNG instead.', 'promptless-forms' ) );
        }

        $xpath = new DOMXPath( $dom );

        foreach ( $xpath->query( '//processing-instruction()' ) as $instruction ) {
            if ( 'xml-stylesheet' !== $instruction->target || ! preg_match( '/href\s*=\s*["\']#/', $instruction->data ) ) {
                return $active;
            }
        }

        foreach ( $xpath->query( '//*' ) as $element ) {
            $name = strtolower( $element->localName );

            if ( in_array( $name, self::SVG_ACTIVE_ELEMENTS, true ) ) {
                return $active;
            }

            // Illustrator stores its editing data in a foreignObject whose
            // content is entirely in Adobe's namespaces. Anything else in a
            // foreignObject (HTML) is refused.
            if ( 'foreignobject' === $name ) {
                foreach ( $xpath->query( './/*', $element ) as $child ) {
                    $namespace = strtr( (string) $child->namespaceURI, $entities );
                    if ( 0 !== strpos( $namespace, 'http://ns.adobe.com/' ) ) {
                        return $active;
                    }
                }
            }

            if ( 'style' === $name && $this->unsafe_css( $element->textContent ) ) {
                return $active;
            }

            if ( in_array( $name, self::SVG_ANIMATION_ELEMENTS, true ) ) {
                $target = strtolower( trim( $element->getAttribute( 'attributeName' ) ) );
                if ( preg_match( '/(^|:)href$|^on/', $target ) ) {
                    return $active;
                }
            }

            foreach ( $element->attributes as $attribute ) {
                $attr  = strtolower( $attribute->localName );
                $value = strtolower( (string) preg_replace( '/[\s\x00-\x1F]+/', '', $attribute->value ) );

                if ( 0 === strpos( $attr, 'on' ) ) {
                    return $active;
                }
                if ( false !== strpos( $value, 'javascript:' ) || false !== strpos( $value, 'vbscript:' ) ) {
                    return $active;
                }
                if ( 'style' === $attr && $this->unsafe_css( $attribute->value ) ) {
                    return $active;
                }
                if ( 'href' === $attr ) {
                    if ( 0 === strpos( $value, 'data:' ) && ! preg_match( '#^data:image/(png|jpe?g|gif|webp)[;,]#', $value ) ) {
                        return $active;
                    }
                    if ( 'use' === $name && '' !== $value && '#' !== $value[0] ) {
                        return $active;
                    }
                }
            }
        }

        return true;
    }

    /**
     * Whether CSS text can run script or pull in another document.
     *
     * @param string $css CSS.
     * @return bool
     */
    private function unsafe_css( $css ) {
        $css = strtolower( (string) preg_replace( '/[\s\x00-\x1F]+|\\\\/', '', (string) $css ) );
        return (bool) preg_match( '/javascript:|vbscript:|expression\(|@import|-moz-binding|behavior:|url\((["\']?)data:text/', $css );
    }

    /**
     * Error for a file carrying program code.
     *
     * @param string $code Error code.
     * @return WP_Error
     */
    private function code_error( $code ) {
        return $this->error( $code, __( 'This file contains program code, which we cannot accept for security reasons. Please export it again, or send a PDF or PNG instead.', 'promptless-forms' ) );
    }

    /**
     * Build an error.
     *
     * @param string $code    Error code.
     * @param string $message Message for the visitor.
     * @return WP_Error
     */
    private function error( $code, $message ) {
        return new WP_Error( $code, $message );
    }
}
