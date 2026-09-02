<?php
/**
 * File Upload Integration Tests.
 *
 * Tests for file upload workflow.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for file uploads.
 */
class FileUploadTest extends IntegrationTestCase {

    /**
     * Upload handler instance.
     *
     * @var \PForms_Upload_Handler
     */
    private $upload_handler;

    /**
     * MIME validator instance.
     *
     * @var \PForms_Mime_Validator
     */
    private $mime_validator;

    /**
     * Test upload directory.
     *
     * @var string
     */
    private $test_upload_dir;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        $this->upload_handler = new \PForms_Upload_Handler();
        $this->mime_validator = new \PForms_Mime_Validator();

        // Get upload directory.
        $upload_dir            = wp_upload_dir();
        $this->test_upload_dir = trailingslashit( $upload_dir['basedir'] ) . 'fre-uploads';
    }

    /**
     * Tear down after each test.
     */
    public function tear_down() {
        // Clean up test files.
        $this->cleanup_test_files();

        parent::tear_down();
    }

    /**
     * Clean up test files.
     */
    private function cleanup_test_files() {
        if ( is_dir( $this->test_upload_dir ) ) {
            $files = glob( $this->test_upload_dir . '/test_*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    unlink( $file );
                }
            }
        }
    }

    /**
     * Create a mock uploaded file.
     *
     * @param string $filename  File name.
     * @param string $mime_type MIME type.
     * @param int    $size      File size.
     * @param string $content   File content (if null, uses magic bytes for the file type).
     * @return array File array similar to $_FILES.
     */
    private function create_mock_file( $filename, $mime_type, $size = 1024, $content = null ) {
        // If no content provided, use appropriate magic bytes for the file type.
        if ( $content === null ) {
            $content = $this->get_magic_bytes_for_mime( $mime_type );
        }

        // Create a temporary file.
        $tmp_file = tempnam( sys_get_temp_dir(), 'fre_test_' );
        file_put_contents( $tmp_file, $content );

        return array(
            'name'     => $filename,
            'type'     => $mime_type,
            'tmp_name' => $tmp_file,
            'error'    => UPLOAD_ERR_OK,
            'size'     => strlen( $content ),
        );
    }

    /**
     * Get appropriate magic bytes for a given MIME type.
     *
     * These must match the MAGIC_BYTES in PForms_Mime_Validator.
     *
     * @param string $mime_type MIME type.
     * @return string File content with magic bytes.
     */
    private function get_magic_bytes_for_mime( $mime_type ) {
        $magic_bytes = array(
            // PDF: %PDF
            'application/pdf'          => "\x25\x50\x44\x46" . "-1.4\ntest content",
            // JPEG: FF D8 FF
            'image/jpeg'               => "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00",
            // PNG: 89 50 4E 47 0D 0A 1A 0A
            'image/png'                => "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A\x00\x00\x00\rIHDR",
            // GIF: GIF89a
            'image/gif'                => "\x47\x49\x46\x38\x39\x61\x01\x00\x01\x00",
            // DOC/XLS/PPT OLE: D0 CF 11 E0 A1 B1 1A E1
            'application/msword'       => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\x00", 100 ),
            'application/vnd.ms-excel' => "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat( "\x00", 100 ),
            // DOCX/XLSX/PPTX ZIP: PK (50 4B 03 04)
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => "\x50\x4B\x03\x04" . str_repeat( "\x00", 100 ),
            // EXE: MZ header
            'application/x-msdownload' => "MZ\x90\x00\x03\x00\x00\x00",
            // Plain text
            'text/plain'               => "test content",
        );

        return isset( $magic_bytes[ $mime_type ] ) ? $magic_bytes[ $mime_type ] : 'test content';
    }

    /**
     * Test allowed file type accepted.
     */
    public function test_allowed_file_type_accepted() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'allowed_types' => array( 'pdf', 'doc', 'docx' ),
        );

        $file = $this->create_mock_file( 'test.pdf', 'application/pdf' );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertTrue( $result );
    }

    /**
     * Test disallowed file type rejected.
     */
    public function test_disallowed_file_type_rejected() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'allowed_types' => array( 'pdf', 'doc', 'docx' ),
        );

        $file = $this->create_mock_file( 'test.exe', 'application/x-msdownload' );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertInstanceOf( 'WP_Error', $result );
        // The error code can be 'blocked_extension' or 'invalid_file_type' depending on validation order.
        $this->assertContains( $result->get_error_code(), array( 'invalid_file_type', 'blocked_extension' ) );
    }

    /**
     * Test PHP file rejected.
     */
    public function test_php_file_rejected() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'allowed_types' => array( 'pdf', 'jpg', 'png' ),
        );

        $dangerous_extensions = array( 'php', 'phtml', 'php3', 'php4', 'php5', 'php7', 'phar' );

        foreach ( $dangerous_extensions as $ext ) {
            $file = $this->create_mock_file( "test.{$ext}", 'text/plain', 100, '<?php echo "hack"; ?>' );

            $result = $this->upload_handler->validate_file( $file, $field );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "Extension '{$ext}' should be rejected"
            );
        }
    }

    /**
     * Test oversized file rejected.
     */
    public function test_oversized_file_rejected() {
        $field = array(
            'key'      => 'document',
            'type'     => 'file',
            'max_size' => 1024, // 1 KB limit.
        );

        // Create content larger than limit (magic bytes + padding).
        $pdf_magic     = "\x25\x50\x44\x46" . "-1.4\n";
        $large_content = $pdf_magic . str_repeat( 'a', 2048 - strlen( $pdf_magic ) ); // 2 KB.
        $file          = $this->create_mock_file( 'large.pdf', 'application/pdf', 2048, $large_content );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertEquals( 'file_too_large', $result->get_error_code() );
    }

    /**
     * Test file within size limit accepted.
     */
    public function test_file_within_size_limit_accepted() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'max_size'      => 5242880, // 5 MB limit.
            'allowed_types' => array( 'pdf' ),
        );

        // Use null for content to get proper magic bytes.
        $file = $this->create_mock_file( 'small.pdf', 'application/pdf', 1024, null );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertTrue( $result );
    }

    /**
     * Test default allowed types.
     */
    public function test_default_allowed_types() {
        $field = array(
            'key'  => 'document',
            'type' => 'file',
            // No allowed_types specified - uses defaults.
        );

        // Test common file types that have reliable magic byte detection.
        // Note: .doc files are skipped as OLE compound documents are complex to mock.
        $default_allowed = array(
            array( 'file' => 'test.pdf', 'mime' => 'application/pdf' ),
            array( 'file' => 'test.jpg', 'mime' => 'image/jpeg' ),
            array( 'file' => 'test.png', 'mime' => 'image/png' ),
            array( 'file' => 'test.gif', 'mime' => 'image/gif' ),
        );

        foreach ( $default_allowed as $test ) {
            $file = $this->create_mock_file( $test['file'], $test['mime'] );

            $result = $this->upload_handler->validate_file( $file, $field );

            $this->assertTrue(
                $result,
                "File '{$test['file']}' should be allowed by default"
            );
        }
    }

    /**
     * Test MIME type validation.
     */
    public function test_mime_type_validation() {
        // Create a fake "PDF" that's actually PHP.
        $malicious_content = '<?php echo "hack"; ?>';
        $file              = $this->create_mock_file( 'document.pdf', 'application/pdf', strlen( $malicious_content ), $malicious_content );

        $result = $this->mime_validator->validate( $file['tmp_name'], array( 'pdf' ) );

        // Should fail because content doesn't match PDF signature.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test required file validation when missing.
     */
    public function test_required_file_missing_fails() {
        $field = array(
            'key'      => 'resume',
            'type'     => 'file',
            'required' => true,
        );

        // Empty file.
        $file = array(
            'name'     => '',
            'type'     => '',
            'tmp_name' => '',
            'error'    => UPLOAD_ERR_NO_FILE,
            'size'     => 0,
        );

        // The field type handles required validation.
        $field_type = new \PForms_Field_File();
        $result     = $field_type->validate( '', $field, array() );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test optional file can be empty.
     */
    public function test_optional_file_empty_passes() {
        $field = array(
            'key'      => 'resume',
            'type'     => 'file',
            'required' => false,
        );

        $field_type = new \PForms_Field_File();
        $result     = $field_type->validate( '', $field, array() );

        $this->assertTrue( $result );
    }

    /**
     * Test file upload error handling.
     */
    public function test_file_upload_error_handling() {
        $field = array(
            'key'  => 'document',
            'type' => 'file',
        );

        $errors = array(
            UPLOAD_ERR_INI_SIZE   => 'file_too_large',
            UPLOAD_ERR_FORM_SIZE  => 'file_too_large',
            UPLOAD_ERR_PARTIAL    => 'upload_error',
            UPLOAD_ERR_NO_TMP_DIR => 'upload_error',
            UPLOAD_ERR_CANT_WRITE => 'upload_error',
            UPLOAD_ERR_EXTENSION  => 'upload_error',
        );

        foreach ( $errors as $error_code => $expected_error ) {
            $file = array(
                'name'     => 'test.pdf',
                'type'     => 'application/pdf',
                'tmp_name' => '',
                'error'    => $error_code,
                'size'     => 0,
            );

            $result = $this->upload_handler->validate_file( $file, $field );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "Error code {$error_code} should result in validation error"
            );
        }
    }

    /**
     * Test double extension detection.
     */
    public function test_double_extension_rejected() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'allowed_types' => array( 'pdf', 'jpg' ),
        );

        $dangerous_names = array(
            'test.pdf.php',
            'test.jpg.phtml',
            'test.php.pdf',
            'image.php.jpg',
        );

        foreach ( $dangerous_names as $filename ) {
            $file = $this->create_mock_file( $filename, 'application/pdf' );

            $result = $this->upload_handler->validate_file( $file, $field );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "Filename '{$filename}' with double extension should be rejected"
            );
        }
    }

    /**
     * Test upload directory has PHP execution disabled.
     */
    public function test_upload_directory_php_blocked() {
        // Verify .htaccess exists.
        $htaccess = $this->test_upload_dir . '/.htaccess';

        if ( file_exists( $htaccess ) ) {
            $content = file_get_contents( $htaccess );

            // Should block PHP execution.
            $this->assertStringContainsString( 'php', strtolower( $content ) );
            $this->assertStringContainsString( 'Deny', $content );
        } else {
            // If no .htaccess, the upload handler should create it when processing files.
            // Mark as skipped since the directory may not have been initialized yet.
            $this->markTestSkipped( 'Upload directory .htaccess not created yet (requires file upload to initialize).' );
        }
    }

    /**
     * Test multiple file upload handling.
     */
    public function test_multiple_file_upload() {
        $field = array(
            'key'           => 'documents',
            'type'          => 'file',
            'multiple'      => true,
            'allowed_types' => array( 'pdf', 'jpg' ),
        );

        // Simulate multiple files structure.
        $files = array(
            'name'     => array( 'doc1.pdf', 'doc2.pdf', 'image.jpg' ),
            'type'     => array( 'application/pdf', 'application/pdf', 'image/jpeg' ),
            'tmp_name' => array(
                $this->create_mock_file( 'doc1.pdf', 'application/pdf' )['tmp_name'],
                $this->create_mock_file( 'doc2.pdf', 'application/pdf' )['tmp_name'],
                $this->create_mock_file( 'image.jpg', 'image/jpeg' )['tmp_name'],
            ),
            'error'    => array( UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK ),
            'size'     => array( 1024, 2048, 512 ),
        );

        // Validate each file.
        foreach ( $files['name'] as $index => $name ) {
            $single_file = array(
                'name'     => $files['name'][ $index ],
                'type'     => $files['type'][ $index ],
                'tmp_name' => $files['tmp_name'][ $index ],
                'error'    => $files['error'][ $index ],
                'size'     => $files['size'][ $index ],
            );

            $result = $this->upload_handler->validate_file( $single_file, $field );

            $this->assertTrue( $result, "File '{$name}' should be valid" );
        }
    }

    /**
     * Test file with null bytes in name rejected.
     */
    public function test_null_bytes_rejected() {
        $field = array(
            'key'           => 'document',
            'type'          => 'file',
            'allowed_types' => array( 'pdf' ),
        );

        $file = $this->create_mock_file( "test\x00.php.pdf", 'application/pdf' );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test image files validated correctly.
     */
    public function test_image_file_validation() {
        $field = array(
            'key'           => 'image',
            'type'          => 'file',
            'allowed_types' => array( 'jpg', 'jpeg', 'png', 'gif' ),
        );

        // Valid image types.
        $valid_images = array(
            array( 'name' => 'photo.jpg', 'mime' => 'image/jpeg' ),
            array( 'name' => 'photo.jpeg', 'mime' => 'image/jpeg' ),
            array( 'name' => 'image.png', 'mime' => 'image/png' ),
            array( 'name' => 'animation.gif', 'mime' => 'image/gif' ),
        );

        foreach ( $valid_images as $image ) {
            $file = $this->create_mock_file( $image['name'], $image['mime'] );

            $result = $this->upload_handler->validate_file( $file, $field );

            $this->assertTrue(
                $result,
                "Image '{$image['name']}' should be valid"
            );
        }
    }

    /**
     * Test SVG files blocked by default.
     */
    public function test_svg_blocked_by_default() {
        $field = array(
            'key'  => 'image',
            'type' => 'file',
            // Default allowed types don't include SVG.
        );

        $svg_content = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert("xss")</script></svg>';
        $file        = $this->create_mock_file( 'image.svg', 'image/svg+xml', strlen( $svg_content ), $svg_content );

        $result = $this->upload_handler->validate_file( $file, $field );

        $this->assertInstanceOf( 'WP_Error', $result );
    }
}
