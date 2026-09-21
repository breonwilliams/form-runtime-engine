<?php
/**
 * Tests for PForms_Upload_Inspector and the upload handler's use of it.
 *
 * Both directions are pinned against real files in tests/Fixtures/uploads/:
 *
 *   genuine/   — artwork made with the tools a print shop's customers use:
 *                Illustrator (AI, both EPS kinds, PDF, both SVG kinds), a
 *                phone photo round-tripped through Apple's HEIC encoder,
 *                motion-photo and Ultra HDR style JPEGs, a PDF with embedded
 *                fonts, a Tajima DST from pyembroidery, mislabelled PNG/JPEG.
 *   malicious/ — PHP-in-image polyglots (metadata, appended, short tags,
 *                PHAR), content that does not match its extension, and SVGs
 *                carrying every active-content trick the inspector refuses.
 *
 * How they were made: tests/uploads/README.md. The wider measurement
 * (hundreds of real files that are not committed) is tests/uploads/matrix.php.
 *
 * Until 1.11.0 the byte scan refused almost every genuine file here: every
 * Illustrator export, every EPS with a preview, every SVG, most photos.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Upload inspection, both directions.
 */
class UploadInspectorTest extends UnitTestCase {

    /**
     * 725 Print Lab's design_file field.
     */
    const PRINT_SHOP_TYPES = array( 'png', 'jpg', 'jpeg', 'pdf', 'ai', 'eps', 'dst', 'svg' );

    /**
     * Genuine files stored under a different extension than they were named
     * with, because their real type is another allowed type.
     */
    const CORRECTED = array(
        'png-saved-as.jpg'  => 'png',
        'jpeg-saved-as.png' => 'jpg',
    );

    /**
     * Crafted samples the inspector deliberately accepts, and why. An entry
     * here must be a decision, not a gap.
     */
    const ACCEPTED_BY_DESIGN = array(
        // JavaScript in a PDF runs in the reader (sandboxed by Acrobat and
        // browser PDF viewers), never on this server or on this website's
        // origin. Refusing it would refuse real PDFs with form fields.
        'pdf-openaction-js.pdf' => 'viewer-side PDF JavaScript',
    );

    protected function set_up() {
        parent::set_up();
        Functions\when( 'is_wp_error' )->alias( function ( $thing ) {
            return $thing instanceof \WP_Error;
        } );
        Functions\when( 'size_format' )->alias( function ( $bytes ) {
            return round( $bytes / 1048576 ) . ' MB';
        } );
    }

    /**
     * @return array
     */
    public function genuine_files() {
        return $this->fixtures( 'genuine' );
    }

    /**
     * @return array
     */
    public function malicious_files() {
        return $this->fixtures( 'malicious' );
    }

    /**
     * @dataProvider genuine_files
     */
    public function test_genuine_artwork_is_accepted( $path ) {
        $name   = basename( $path );
        $result = ( new \PForms_Upload_Inspector() )->inspect( $path, pathinfo( $name, PATHINFO_EXTENSION ), self::PRINT_SHOP_TYPES );

        $this->assertIsArray( $result, $name . ' was refused: ' . ( is_wp_error( $result ) ? $result->get_error_code() . ' — ' . $result->get_error_message() : '' ) );

        $expected_ext = isset( self::CORRECTED[ $name ] ) ? self::CORRECTED[ $name ] : strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
        $this->assertSame( $expected_ext, $result['ext'], $name . ' stored under the wrong extension.' );
    }

    /**
     * @dataProvider malicious_files
     */
    public function test_malicious_upload_is_refused( $path ) {
        $name   = basename( $path );
        $result = ( new \PForms_Upload_Inspector() )->inspect( $path, pathinfo( $name, PATHINFO_EXTENSION ), self::PRINT_SHOP_TYPES );

        if ( isset( self::ACCEPTED_BY_DESIGN[ $name ] ) ) {
            $this->assertIsArray( $result, $name . ' is documented as accepted by design.' );
            return;
        }

        $this->assertInstanceOf( 'WP_Error', $result, $name . ' was accepted.' );
    }

    public function test_every_refusal_message_is_written_for_a_visitor() {
        foreach ( $this->malicious_files() as $case ) {
            $result = ( new \PForms_Upload_Inspector() )->inspect( $case[0], pathinfo( $case[0], PATHINFO_EXTENSION ), self::PRINT_SHOP_TYPES );
            if ( ! is_wp_error( $result ) ) {
                continue;
            }
            $message = $result->get_error_message();
            $this->assertMatchesRegularExpression( '/Please /', $message, basename( $case[0] ) . ': the message must tell the visitor what to do.' );
            $this->assertStringNotContainsString( 'dangerous', $message, 'No alarming wording for what is usually an export problem.' );
        }
    }

    public function test_mislabelled_file_is_refused_when_its_real_type_is_not_allowed() {
        $png    = $this->fixture( 'genuine', 'png-saved-as.jpg' );
        $result = ( new \PForms_Upload_Inspector() )->inspect( $png, 'jpg', array( 'jpg', 'jpeg' ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'content_type_mismatch', $result->get_error_code() );
    }

    public function test_illustrator_svg_editing_data_is_allowed_but_html_in_foreign_object_is_not() {
        $inspector = new \PForms_Upload_Inspector();

        $this->assertIsArray( $inspector->inspect( $this->fixture( 'genuine', 'artwork-illustrator-editable.svg' ), 'svg', array( 'svg' ) ) );
        $this->assertInstanceOf( 'WP_Error', $inspector->inspect( $this->fixture( 'malicious', 'svg-adobe-fo-with-xhtml.svg' ), 'svg', array( 'svg' ) ) );
    }

    // ── The upload handler around the inspector ─────────────────────────

    public function test_svg_is_accepted_when_the_field_allows_it() {
        $handler = new \PForms_Upload_Handler();
        $svg     = $this->fixture( 'genuine', 'artwork-illustrator-plain.svg' );

        $this->assertTrue( $handler->validate_file( $this->upload( $svg, 'logo.svg' ), array( 'key' => 'design_file', 'type' => 'file', 'allowed_types' => self::PRINT_SHOP_TYPES, 'max_size' => 26214400 ) ) );
    }

    public function test_svg_stays_blocked_when_the_field_does_not_allow_it() {
        $handler = new \PForms_Upload_Handler();
        $svg     = $this->fixture( 'genuine', 'artwork-illustrator-plain.svg' );
        $result  = $handler->validate_file( $this->upload( $svg, 'logo.svg' ), array( 'key' => 'photo', 'type' => 'file', 'allowed_types' => array( 'jpg', 'png' ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    public function test_executable_extensions_stay_blocked_even_if_a_field_lists_them() {
        $handler = new \PForms_Upload_Handler();
        $path    = $this->fixture( 'malicious', 'php-renamed.jpg' );
        $result  = $handler->validate_file( $this->upload( $path, 'shell.php' ), array( 'key' => 'f', 'type' => 'file', 'allowed_types' => array( 'php', 'jpg' ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'blocked_extension', $result->get_error_code() );
    }

    public function test_file_over_the_server_limit_gets_a_plain_message() {
        Functions\when( 'wp_max_upload_size' )->justReturn( 67108864 );
        $handler = new \PForms_Upload_Handler();
        $file    = array( 'name' => 'big.png', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 0 );
        $result  = $handler->validate_file( $file, array( 'key' => 'f', 'type' => 'file', 'allowed_types' => array( 'png' ) ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertStringContainsString( 'larger than this website accepts (64 MB)', $result->get_error_message() );
    }

    public function test_total_limit_follows_the_form_fields() {
        $handler = new \PForms_Upload_Handler();
        $bulk    = array( 'fields' => array(
            array( 'key' => 'tax_exempt_doc', 'type' => 'file', 'max_size' => 10485760 ),
            array( 'key' => 'design_file', 'type' => 'file', 'max_size' => 26214400 ),
            array( 'key' => 'name', 'type' => 'text' ),
        ) );
        $small   = array( 'fields' => array( array( 'key' => 'photo', 'type' => 'file', 'max_size' => 5242880 ) ) );

        $this->assertSame( 36700160, $handler->get_max_total_size( $bulk ), '10 MB + 25 MB: a visitor keeping to both limits is not refused.' );
        $this->assertSame( 26214400, $handler->get_max_total_size( $small ), 'Never below the 25 MB floor.' );
    }

    public function test_stored_name_uses_the_inspected_extension() {
        Functions\when( 'wp_generate_uuid4' )->justReturn( 'abc' );
        $handler = new \PForms_Upload_Handler();

        $this->assertSame( 'abc.png', $handler->generate_secure_filename( 'IMG_1.jpg', 'png' ) );
        $this->assertSame( 'abc.jpg', $handler->generate_secure_filename( 'IMG_1.JPG' ) );
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function fixtures( $set ) {
        $cases = array();
        foreach ( glob( FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/uploads/' . $set . '/*' ) as $path ) {
            $cases[ basename( $path ) ] = array( $path );
        }
        return $cases;
    }

    private function fixture( $set, $name ) {
        return FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/uploads/' . $set . '/' . $name;
    }

    private function upload( $path, $name ) {
        return array( 'name' => $name, 'type' => '', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize( $path ) );
    }
}
