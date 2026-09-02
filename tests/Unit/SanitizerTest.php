<?php
/**
 * Unit tests for PForms_Sanitizer.
 *
 * Focus: the security-critical methods. Sanitization is the boundary
 * between untrusted user input and storage / outbound communication.
 * Two methods on this class carry concrete security guarantees:
 *
 *   - sanitize_email_header() — prevents email header injection. A
 *     submitted "name" field that contained a newline followed by
 *     "Bcc: attacker@example.com" would, without this method, append
 *     a hidden recipient to every outbound notification.
 *
 *   - sanitize_filename() — prevents null-byte attacks (truncate the
 *     extension in older PHP) and path traversal (../) in upload
 *     filenames.
 *
 * The remaining methods (sanitize_ip, sanitize_url, deep_sanitize,
 * strip_tags) are tested for happy paths and the most likely failure
 * modes, but their security stakes are lower because they sit behind
 * other validation layers.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

class SanitizerTest extends UnitTestCase {

    /**
     * @var \PForms_Sanitizer
     */
    private $sanitizer;

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-sanitizer.php';

        $this->sanitizer = new \PForms_Sanitizer();
    }

    // -----------------------------------------------------------------
    // sanitize_email_header — header injection prevention
    //
    // Reference: https://www.owasp.org/index.php/Email_Header_Injection
    //
    // The vulnerability: WordPress's wp_mail() uses an attacker-supplied
    // string as the From / Reply-To / etc. value. If that string contains
    // a literal newline, the SMTP layer treats anything after the newline
    // as a NEW HEADER. Submitting "alice@example.com\nBcc: hacker@evil.com"
    // as a Reply-To turns into a hidden Bcc to the attacker on every
    // notification email.
    //
    // The defense: strip ALL newlines (\r and \n) AND strip any leading
    // header-name prefix that an attacker might use to inject a new
    // header even without a newline.
    // -----------------------------------------------------------------

    public function test_sanitize_email_header_strips_linefeed_characters() {
        $injected = "alice@example.com\nBcc: attacker@evil.com";

        $clean = $this->sanitizer->sanitize_email_header( $injected );

        $this->assertStringNotContainsString( "\n", $clean, 'Linefeeds must be stripped — they are the primary header-injection vector.' );
        $this->assertStringNotContainsString( "\r", $clean, 'Carriage returns must also be stripped (CRLF is also a header separator).' );
    }

    public function test_sanitize_email_header_strips_carriage_returns() {
        $injected = "alice@example.com\rBcc: attacker@evil.com";

        $clean = $this->sanitizer->sanitize_email_header( $injected );

        $this->assertStringNotContainsString( "\r", $clean );
    }

    public function test_sanitize_email_header_strips_crlf_combination() {
        $injected = "alice@example.com\r\nBcc: attacker@evil.com\r\nCc: another@evil.com";

        $clean = $this->sanitizer->sanitize_email_header( $injected );

        $this->assertStringNotContainsString( "\r", $clean );
        $this->assertStringNotContainsString( "\n", $clean );
    }

    public function test_sanitize_email_header_strips_leading_to_prefix() {
        // Even without newlines, an attacker might submit something like
        // "To: target@victim.com" hoping it gets concatenated. The
        // sanitizer strips known leading header-name prefixes (case
        // insensitive) to cover that vector.
        $injected = "To: target@victim.com";

        $clean = $this->sanitizer->sanitize_email_header( $injected );

        $this->assertStringStartsNotWith( 'To:', $clean );
        $this->assertStringStartsNotWith( 'to:', $clean );
    }

    public function test_sanitize_email_header_strips_leading_cc_bcc_from_replyto_prefixes() {
        foreach ( array( 'Cc:', 'BCC:', 'From:', 'Reply-To:', 'reply-to:' ) as $prefix ) {
            $clean = $this->sanitizer->sanitize_email_header( $prefix . ' attacker@evil.com' );

            $this->assertStringNotContainsString(
                ':',
                substr( $clean, 0, strlen( $prefix ) ),
                "Header prefix '{$prefix}' must be stripped from the start of the value."
            );
        }
    }

    public function test_sanitize_email_header_preserves_normal_email_addresses() {
        // Sanity check — the sanitizer must not mangle ordinary input.
        $clean = $this->sanitizer->sanitize_email_header( 'alice@example.com' );

        $this->assertSame( 'alice@example.com', $clean );
    }

    // -----------------------------------------------------------------
    // sanitize_filename — null-byte and traversal prevention
    //
    // The vulnerability: a filename like "innocent.jpg\x00.php" used to
    // get truncated at the null byte by PHP's filesystem calls — leaving
    // a file named "innocent.jpg" but actually executable as PHP. The
    // null-byte issue is fixed in modern PHP, but defense in depth says
    // strip the byte rather than rely on PHP version.
    //
    // The defense: strip null bytes, then restrict to a safe character
    // class.
    // -----------------------------------------------------------------

    public function test_sanitize_filename_strips_null_bytes() {
        $injected = "innocent.jpg\x00.php";

        $clean = $this->sanitizer->sanitize_filename( $injected );

        $this->assertStringNotContainsString(
            "\x00",
            $clean,
            'Null bytes must be stripped from filenames — historically used to truncate the extension and bypass type checks.'
        );
    }

    public function test_sanitize_filename_restricts_to_safe_charset() {
        // The sanitizer's allow-list is alnum + . _ -. Anything else
        // should be removed.
        $clean = $this->sanitizer->sanitize_filename( 'my file with spaces!@#$.jpg' );

        // Spaces, ! @ # $ should all be gone. Period and the alnum
        // chars stay.
        $this->assertMatchesRegularExpression(
            '/^[a-zA-Z0-9._-]+$/',
            $clean,
            'Sanitized filename must only contain alnum, period, underscore, hyphen.'
        );
    }

    public function test_sanitize_filename_preserves_extension_and_stem() {
        $clean = $this->sanitizer->sanitize_filename( 'document_v1.pdf' );

        $this->assertStringContainsString( 'document_v1', $clean );
        $this->assertStringContainsString( '.pdf', $clean );
    }

    // -----------------------------------------------------------------
    // sanitize_ip
    //
    // Used for storing the submitter's IP in entry meta and for rate
    // limiting. Bad input should resolve to empty rather than ever
    // bypass an IP-based rate limit.
    // -----------------------------------------------------------------

    public function test_sanitize_ip_accepts_valid_ipv4() {
        $this->assertSame( '192.168.1.1', $this->sanitizer->sanitize_ip( '192.168.1.1' ) );
    }

    public function test_sanitize_ip_accepts_valid_ipv6() {
        $this->assertSame( '2001:db8::1', $this->sanitizer->sanitize_ip( '2001:db8::1' ) );
    }

    public function test_sanitize_ip_returns_empty_for_invalid_input() {
        $this->assertSame( '', $this->sanitizer->sanitize_ip( 'not-an-ip' ) );
        $this->assertSame( '', $this->sanitizer->sanitize_ip( '999.999.999.999' ) );
        $this->assertSame( '', $this->sanitizer->sanitize_ip( '<script>alert(1)</script>' ) );
    }

    // -----------------------------------------------------------------
    // deep_sanitize — recursive array sanitization
    //
    // Used to clean structured submission data before it touches storage
    // or hooks. Must recurse fully, not just the top level.
    // -----------------------------------------------------------------

    public function test_deep_sanitize_recurses_into_nested_arrays() {
        $input = array(
            'top'    => '<script>top</script>',
            'nested' => array(
                'inner'    => '<b>bold</b>',
                'deeper'   => array(
                    'leaf' => '<img src=x onerror=alert(1)>'
                ),
            ),
        );

        $clean = $this->sanitizer->deep_sanitize( $input );

        $this->assertStringNotContainsString( '<script>', $clean['top'] );
        $this->assertStringNotContainsString( '<b>', $clean['nested']['inner'] );
        $this->assertStringNotContainsString( '<img', $clean['nested']['deeper']['leaf'] );
    }

    public function test_deep_sanitize_sanitizes_keys_too() {
        $input = array(
            'normal_key'        => 'value',
            'key with spaces!@' => 'value',
        );

        $clean = $this->sanitizer->deep_sanitize( $input );

        // sanitize_key strips non-alnum-underscore-hyphen chars and
        // lowercases. The "key with spaces" key should be reduced.
        $this->assertArrayHasKey( 'normal_key', $clean );
        $this->assertArrayNotHasKey( 'key with spaces!@', $clean, 'Unsanitized key must not survive deep_sanitize.' );
    }

    // -----------------------------------------------------------------
    // strip_tags — both string and array inputs
    // -----------------------------------------------------------------

    public function test_strip_tags_removes_html_from_strings() {
        $clean = $this->sanitizer->strip_tags( '<p>Hello <strong>world</strong></p>' );

        $this->assertSame( 'Hello world', $clean );
    }

    public function test_strip_tags_recurses_into_arrays() {
        $input = array( '<p>one</p>', '<b>two</b>', array( '<i>three</i>' ) );

        $clean = $this->sanitizer->strip_tags( $input );

        $this->assertSame( 'one', $clean[0] );
        $this->assertSame( 'two', $clean[1] );
        $this->assertSame( 'three', $clean[2][0] );
    }

    public function test_strip_tags_kills_script_content_not_just_tags() {
        // wp_strip_all_tags doesn't just remove <script>, it removes
        // the script CONTENT too — a critical difference from PHP's
        // built-in strip_tags. Inline script payload would otherwise
        // leak into the rendered output.
        $clean = $this->sanitizer->strip_tags( 'Hello<script>alert(1)</script>World' );

        $this->assertStringNotContainsString( 'alert(1)', $clean, 'Script body content must be removed, not just the surrounding tags.' );
        $this->assertStringContainsString( 'Hello', $clean );
        $this->assertStringContainsString( 'World', $clean );
    }
}
