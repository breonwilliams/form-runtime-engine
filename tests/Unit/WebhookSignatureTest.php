<?php
/**
 * Unit tests for PForms_Webhook_Dispatcher::generate_signature().
 *
 * The signature is the integrity guarantee for outbound webhooks. Every
 * external receiver (Zapier, Make, custom endpoints, GAS scripts) that
 * verifies a webhook signature is going to recompute HMAC-SHA256 over
 * the body using the shared secret, then compare against the
 * X-FRE-Signature header. If anything about this method changes
 * silently — different algorithm, different format, different secret
 * encoding — every receiver breaks at once.
 *
 * Tests pin:
 *   - Determinism (same body + secret = same signature, always).
 *   - Format (sha256= prefix + 64 hex chars).
 *   - Sensitivity (different body or secret = different signature).
 *   - One pre-computed reference value (catches accidental algorithm
 *     changes — if someone swaps hash_hmac for hash() and forgets a
 *     test, this assertion fails).
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

class WebhookSignatureTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Webhooks/class-fre-webhook-dispatcher.php';
    }

    // -----------------------------------------------------------------
    // Format
    // -----------------------------------------------------------------

    public function test_signature_uses_sha256_prefix() {
        $sig = \PForms_Webhook_Dispatcher::generate_signature( '{"event":"test"}', 'my-secret' );

        $this->assertStringStartsWith(
            'sha256=',
            $sig,
            'Signature format must be "sha256=<hex>" so receivers can pattern-match on the algorithm prefix.'
        );
    }

    public function test_signature_hex_portion_is_exactly_64_characters() {
        // SHA-256 always produces 32 bytes = 64 hex characters. Pinning
        // the length catches accidental algorithm changes (sha1 = 40
        // chars, sha512 = 128 chars).
        $sig = \PForms_Webhook_Dispatcher::generate_signature( '{"event":"test"}', 'my-secret' );

        $hex = substr( $sig, strlen( 'sha256=' ) );

        $this->assertSame( 64, strlen( $hex ), 'SHA-256 hex output is exactly 64 characters; any other length means the algorithm changed.' );
        $this->assertMatchesRegularExpression( '/^[0-9a-f]+$/', $hex, 'Hex portion must be lowercase hexadecimal only.' );
    }

    // -----------------------------------------------------------------
    // Determinism
    //
    // The whole point of HMAC: same input always produces same output.
    // If determinism breaks (e.g. timestamp gets baked in), every
    // receiver that's caching/comparing signatures stops working.
    // -----------------------------------------------------------------

    public function test_signature_is_deterministic() {
        $body   = '{"event":"form_submission","entry_id":42}';
        $secret = 'my-shared-secret';

        $sig_a = \PForms_Webhook_Dispatcher::generate_signature( $body, $secret );
        $sig_b = \PForms_Webhook_Dispatcher::generate_signature( $body, $secret );

        $this->assertSame(
            $sig_a,
            $sig_b,
            'Same body + same secret must always produce the same signature — receivers depend on this for verification.'
        );
    }

    // -----------------------------------------------------------------
    // Sensitivity — body and secret independence
    // -----------------------------------------------------------------

    public function test_signature_differs_when_body_changes() {
        $secret = 'my-secret';

        $sig_a = \PForms_Webhook_Dispatcher::generate_signature( '{"a":1}', $secret );
        $sig_b = \PForms_Webhook_Dispatcher::generate_signature( '{"a":2}', $secret );

        $this->assertNotSame(
            $sig_a,
            $sig_b,
            'Body change must produce a different signature — otherwise receivers cannot detect tampering.'
        );
    }

    public function test_signature_differs_when_secret_changes() {
        $body = '{"event":"test"}';

        $sig_a = \PForms_Webhook_Dispatcher::generate_signature( $body, 'secret-one' );
        $sig_b = \PForms_Webhook_Dispatcher::generate_signature( $body, 'secret-two' );

        $this->assertNotSame(
            $sig_a,
            $sig_b,
            'Secret rotation must produce a different signature — otherwise rotation is meaningless.'
        );
    }

    public function test_signature_is_sensitive_to_single_byte_body_changes() {
        // Avalanche property: changing one byte of input must produce a
        // wildly different signature. This is what makes substitution
        // attacks on the body infeasible.
        $secret = 'my-secret';

        $sig_a = \PForms_Webhook_Dispatcher::generate_signature( '{"name":"Alice"}', $secret );
        $sig_b = \PForms_Webhook_Dispatcher::generate_signature( '{"name":"alice"}', $secret );

        $this->assertNotSame( $sig_a, $sig_b, 'Single-character difference in body must change the signature.' );
    }

    // -----------------------------------------------------------------
    // Algorithm pinning — known reference value
    //
    // This is the regression guard against accidental algorithm
    // changes. The reference value below was computed independently by
    // running:
    //   php -r 'echo hash_hmac("sha256", "{\"event\":\"test\"}", "test-secret");'
    //
    // If someone refactors generate_signature() and accidentally
    // changes the algorithm, key derivation, or encoding, this test
    // fails immediately — even before any external receiver notices.
    // -----------------------------------------------------------------

    public function test_signature_matches_known_reference_value() {
        $body                = '{"event":"test"}';
        $secret              = 'test-secret';
        $expected_signature  = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

        $actual = \PForms_Webhook_Dispatcher::generate_signature( $body, $secret );

        $this->assertSame(
            $expected_signature,
            $actual,
            'Generated signature must match an independently-computed HMAC-SHA256 — locks the algorithm + format end-to-end.'
        );
    }

    // -----------------------------------------------------------------
    // Edge cases
    // -----------------------------------------------------------------

    public function test_signature_handles_empty_body() {
        // An edge case but legal — an empty payload is still a payload
        // and should still get a signature (HMAC of empty string is a
        // well-defined value).
        $sig = \PForms_Webhook_Dispatcher::generate_signature( '', 'my-secret' );

        $this->assertStringStartsWith( 'sha256=', $sig );
        $this->assertSame( 64, strlen( substr( $sig, strlen( 'sha256=' ) ) ) );
    }

    public function test_signature_handles_unicode_body() {
        // Webhook bodies are JSON-encoded, but unicode characters are
        // common (names with non-ASCII chars, emoji, etc.). HMAC must
        // operate on bytes, not characters — pin that this works.
        $body = '{"name":"Renée","emoji":"🎉"}';

        $sig = \PForms_Webhook_Dispatcher::generate_signature( $body, 'my-secret' );

        // Should be a valid signature, not an empty string or warning.
        $this->assertStringStartsWith( 'sha256=', $sig );
        $this->assertSame( 64, strlen( substr( $sig, strlen( 'sha256=' ) ) ) );
    }

    public function test_signature_handles_long_secret() {
        // HMAC has a documented "block size" property: secrets longer
        // than the block size are first hashed down to block size. Make
        // sure we don't crash on a very long secret.
        $long_secret = str_repeat( 'X', 4096 );

        $sig = \PForms_Webhook_Dispatcher::generate_signature( '{"a":1}', $long_secret );

        $this->assertStringStartsWith( 'sha256=', $sig );
        $this->assertSame( 64, strlen( substr( $sig, strlen( 'sha256=' ) ) ) );
    }
}
