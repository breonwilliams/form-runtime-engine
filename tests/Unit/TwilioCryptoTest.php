<?php
/**
 * Unit tests for PForms_Twilio_Client::encrypt_value() and decrypt_value().
 *
 * Wave 1 of the credential encryption audit (items I1 + I3) changed the
 * return-type contract of these two methods: both now return WP_Error
 * on specific failure modes rather than silently degrading. Tests pin
 * that contract so a future Wave 2 refactor (random IV per encryption,
 * legacy migration on read) can't accidentally regress the failure
 * surfacing the admins now depend on.
 *
 * What's covered:
 *   - Round-trip identity (encrypt → decrypt = original)
 *   - Empty input handling (empty in, empty out, NOT WP_Error)
 *   - Plain-text passthrough (legacy values without prefix)
 *   - `b64:` legacy backward compatibility (existing sites still work)
 *   - `b64:` malformed value → WP_Error
 *   - `enc:` corrupted ciphertext → WP_Error
 *   - Format prefix (`enc:`) is consistently produced
 *
 * What's NOT covered here (deferred to Wave 2):
 *   - openssl-extension-missing path. PHP's openssl extension is
 *     enabled on essentially every modern WordPress host, and we can't
 *     cleanly unload it in a unit-test process. The path is straight-
 *     line code with one branch and is exercised in code review.
 *   - IV-uniqueness assertions (Wave 2 deliverable).
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

class TwilioCryptoTest extends UnitTestCase {

    /**
     * Stable salt values used by encrypt_value() / decrypt_value().
     * Tests use the same salts on both sides so encrypt → decrypt is
     * deterministic and reproducible.
     */
    const TEST_AUTH_SALT        = 'unit-test-auth-salt-fixed-value';
    const TEST_SECURE_AUTH_SALT = 'unit-test-secure-auth-salt-16ch'; // exactly 16 chars used as IV

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Twilio/class-fre-twilio-client.php';

        // Mock wp_salt to return our deterministic test salts. The
        // class reads two different salt namespaces ('auth' for the
        // key, 'secure_auth' for the IV).
        Functions\when( 'wp_salt' )->alias( function ( $scheme = 'auth' ) {
            if ( $scheme === 'secure_auth' ) {
                return self::TEST_SECURE_AUTH_SALT;
            }
            return self::TEST_AUTH_SALT;
        });
    }

    // -----------------------------------------------------------------
    // Round-trip identity — the basic correctness invariant
    // -----------------------------------------------------------------

    public function test_encrypt_then_decrypt_recovers_original_value() {
        // Shaped like a Twilio Account SID, but deliberately NOT hex. GitHub push
        // protection blocks any AC followed by 32 hex characters, and this file
        // has already had a push rejected for exactly that. The value is arbitrary
        // — these tests assert round-trip identity, not format — so keep the 'x'
        // padding. Making it look realistic will block the next push.
        $original = 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';

        $encrypted = \PForms_Twilio_Client::encrypt_value( $original );
        $decrypted = \PForms_Twilio_Client::decrypt_value( $encrypted );

        $this->assertSame(
            $original,
            $decrypted,
            'Round-trip must recover the original value exactly — that is the entire point of encrypt + decrypt.'
        );
    }

    public function test_encrypt_produces_enc_prefix() {
        $encrypted = \PForms_Twilio_Client::encrypt_value( 'test-token' );

        $this->assertIsString( $encrypted, 'Successful encryption must return a string, not WP_Error.' );
        $this->assertStringStartsWith(
            'enc:',
            $encrypted,
            'Encrypted value must use `enc:` prefix so decrypt_value can recognize it.'
        );
    }

    public function test_round_trip_handles_unicode_credentials() {
        // Twilio credentials are ASCII in practice, but the encryption
        // routine must not corrupt unicode — defense-in-depth so the
        // method can be reused for other secrets later.
        $original = 'tëst-tøken-with-emoji-🔑';

        $encrypted = \PForms_Twilio_Client::encrypt_value( $original );
        $decrypted = \PForms_Twilio_Client::decrypt_value( $encrypted );

        $this->assertSame( $original, $decrypted );
    }

    // -----------------------------------------------------------------
    // Empty input — NOT an error condition
    // -----------------------------------------------------------------

    public function test_encrypt_of_empty_string_returns_empty_string() {
        $result = \PForms_Twilio_Client::encrypt_value( '' );

        $this->assertSame(
            '',
            $result,
            'Empty input must return empty string, NOT WP_Error — empty means "no value to encrypt", a normal state.'
        );
    }

    public function test_decrypt_of_empty_string_returns_empty_string() {
        $result = \PForms_Twilio_Client::decrypt_value( '' );

        $this->assertSame(
            '',
            $result,
            'Empty input means "no stored value", which is the canonical "not configured" state, NOT a failure.'
        );
    }

    // -----------------------------------------------------------------
    // Legacy-compat paths — backward compatibility for existing sites
    // -----------------------------------------------------------------

    public function test_decrypt_passes_plain_text_through_unchanged() {
        // Values stored before the encryption layer was added have no
        // prefix. They must continue to be readable.
        $legacy_value = 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx';  // non-hex on purpose — see the note above

        $result = \PForms_Twilio_Client::decrypt_value( $legacy_value );

        $this->assertSame( $legacy_value, $result, 'Plain-text legacy values must pass through for backward compatibility.' );
    }

    public function test_decrypt_recovers_legacy_b64_prefixed_value() {
        // Sites that ran the plugin between the b64-fallback era and
        // the Wave 1 fix may have `b64:` values stored. Those must
        // continue to decode even though encrypt_value() no longer
        // produces them.
        $original   = 'auth-token-from-b64-era';
        $b64_stored = 'b64:' . base64_encode( $original );

        $result = \PForms_Twilio_Client::decrypt_value( $b64_stored );

        $this->assertSame( $original, $result, 'Legacy b64: values must continue to decode (backward compat).' );
    }

    public function test_decrypt_returns_wp_error_for_malformed_b64_value() {
        // A `b64:` blob that fails strict base64 decoding (corrupted in
        // storage, manually edited, etc.) must surface as WP_Error
        // rather than the empty string that historically got returned.
        $malformed = 'b64:!!!not-valid-base64!!!';

        $result = \PForms_Twilio_Client::decrypt_value( $malformed );

        $this->assertInstanceOf( '\\WP_Error', $result );
        $this->assertSame( 'twilio_decryption_failed', $result->get_error_code() );
    }

    // -----------------------------------------------------------------
    // Wave 1 audit fix item I3 — decryption failure surfaces explicitly
    // -----------------------------------------------------------------

    public function test_decrypt_returns_wp_error_when_enc_ciphertext_is_corrupted() {
        // Simulate a corrupted ciphertext — `enc:` prefix but garbage
        // body. openssl_decrypt will return false; our method must
        // turn that into WP_Error('twilio_decryption_failed').
        $corrupted = 'enc:not-real-aes-ciphertext-just-garbage-bytes';

        $result = \PForms_Twilio_Client::decrypt_value( $corrupted );

        $this->assertInstanceOf(
            '\\WP_Error',
            $result,
            'Corrupted ciphertext must surface as WP_Error, NOT empty string — that distinction is the whole point of audit item I3.'
        );
        $this->assertSame(
            'twilio_decryption_failed',
            $result->get_error_code(),
            'Error code must be specific (twilio_decryption_failed) so callers can match on it.'
        );
    }

    public function test_decrypt_returns_wp_error_when_salt_has_rotated() {
        // The classic host-migration scenario: a credential was
        // encrypted with one set of WP salts, the host migrates and
        // wp-config.php is regenerated, salts change, the old
        // ciphertext is unreadable. Audit item I3 specifically calls
        // this out as the scenario that used to look like "not
        // configured" to admins.
        $original = 'real-auth-token';

        // Encrypt with the standard test salts.
        $encrypted = \PForms_Twilio_Client::encrypt_value( $original );

        // Now simulate salt rotation by changing the wp_salt mock.
        Functions\when( 'wp_salt' )->alias( function ( $scheme = 'auth' ) {
            if ( $scheme === 'secure_auth' ) {
                return 'ROTATED-secure-salt-16c';
            }
            return 'ROTATED-auth-salt-fully-different-from-original';
        });

        $result = \PForms_Twilio_Client::decrypt_value( $encrypted );

        $this->assertInstanceOf(
            '\\WP_Error',
            $result,
            'Salt rotation must surface as WP_Error so the admin sees "credentials unreadable, please re-enter" instead of "not configured".'
        );
        $this->assertSame( 'twilio_decryption_failed', $result->get_error_code() );
    }
}
