# Twilio Credential Encryption — Security Audit Findings

Prepared 2026-05 as part of FORM_RUNTIME_AUDIT.md item I5. Scope: review the
decryption path for stored Twilio credentials (Account SID + Auth Token) to
verify there's no silent plaintext fallback.

**File audited:** `includes/Twilio/class-fre-twilio-client.php`
**Methods:** `from_settings()` (line 65), `encrypt_value()` (line 211), `decrypt_value()` (line 236)

The good news: there's no fallback that silently returns ciphertext as if it were plaintext on decryption failure. **The bad news: there are four other concerns ranging from "weakens the encryption guarantees" to "name says encryption but it's just base64." Each is documented below with the line numbers and recommended fix.**

---

## Critical

### CR1. Deterministic IV — same plaintext encrypts to same ciphertext

**Where:** lines 217–218 (encrypt) and 253–254 (decrypt).

```php
$key = wp_salt( 'auth' );
$iv  = substr( wp_salt( 'secure_auth' ), 0, 16 );  // ← same IV every time
$method = 'aes-256-cbc';
```

**The problem:** AES-CBC requires a unique IV per encryption operation. Reusing the IV defeats CBC's security guarantees. Practical consequences:

1. Two identical plaintext credentials (e.g., two sites both using Twilio account "ACxxx") encrypt to identical ciphertext. An attacker who reads one site's database sees the same blob in another site's DB and instantly knows they share an Account SID — no decryption needed.
2. Even within a single site, if the same value is encrypted twice (e.g., setting and then re-setting the same SID), the ciphertext is bit-identical. This is a known cryptanalysis weakness of CBC with reused IVs.
3. Combined with deterministic key (`wp_salt('auth')`, which is also static across the site's lifetime), the encryption is closer to a deterministic encoding than true encryption.

**Recommended fix:** generate a random IV per encryption operation and store it alongside the ciphertext. Format change: `enc:` → `enc2:<base64-iv>:<base64-ciphertext>`. Decryption checks the version prefix and chooses the appropriate path. Old `enc:` blobs continue to decrypt with the legacy method (so existing customers don't lose their credentials at upgrade), but anything re-saved gets the new format.

**Sketch:**
```php
$iv = openssl_random_pseudo_bytes( 16 );
$encrypted = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
return 'enc2:' . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
```

**Effort:** ~3 hours including a migration test (ensure old `enc:` credentials still decrypt correctly during the transition window).

---

## Important

### I1. `b64:` "fallback" is misleadingly labelled — it's encoding, not encryption

**Where:** lines 220–223 (encrypt fallback when `openssl_encrypt` unavailable), 247–249 (decrypt branch).

```php
if ( ! function_exists( 'openssl_encrypt' ) ) {
    // Fallback: base64 encoding (not true encryption, but better than plain text).
    return 'b64:' . base64_encode( $value );
}
```

**The problem:** the inline comment is honest (`not true encryption`) but the storage prefix `b64:` and the function name `encrypt_value()` make this look like encryption to anyone scanning the database or reading the code at a glance. Base64 is reversible by anyone who knows it's base64 — the security value is zero. Worse, it gives a false sense of safety: "the credentials are encrypted in the database" is technically true if you accept that base64 counts as encryption, but it's misleading in any audit context.

**Practical impact:** if a managed-host environment lacks `openssl_encrypt` (rare in PHP 7.4+, but possible on locked-down hosts), the plugin silently downgrades to base64 and never tells the admin. The admin believes their Twilio Auth Token is protected; it's not.

**Recommended fix:** when `openssl_encrypt` is unavailable, **refuse to save credentials** and surface a `WP_Error` to the admin with the message "Twilio integration requires PHP openssl extension. Please contact your host." Force the admin to make an informed choice rather than silently downgrade.

If you genuinely need a no-openssl fallback (very rare today), at minimum rename the storage prefix to `enc-disabled:` so anyone scanning the DB or reading the code can't mistake encoded values for encrypted ones, and surface an admin notice on every page load until openssl is available.

**Effort:** ~2 hours including admin-notice plumbing.

### I2. Plain-text passthrough for legacy values

**Where:** lines 241–244.

```php
// Plain text (not encrypted).
if ( strpos( $value, 'enc:' ) !== 0 && strpos( $value, 'b64:' ) !== 0 ) {
    return $value;
}
```

**The problem:** any credential stored without an `enc:` or `b64:` prefix is returned as-is. This is presumably for backwards compatibility with credentials saved before encryption was added — but the comment doesn't say so, and there's no migration path that re-encrypts plaintext values when they're loaded.

**Practical impact:** a credential entered via direct DB manipulation (or saved during a buggy build that skipped encryption) is silently treated as plaintext forever. There's no warning, no migration, no admin notice.

**Recommended fix:** when `decrypt_value()` encounters a non-prefixed value:

1. Log a warning (`error_log` gated by `WP_DEBUG`, or write to FRE's logger) noting that an unencrypted credential was encountered.
2. Re-encrypt the value and update the stored option in-place. This is a safe one-time migration — the next call returns the freshly-encrypted-then-decrypted value, transparently.

If migration on read is too aggressive, at minimum surface an admin notice: "Twilio credentials are stored unencrypted. Click here to re-save them with encryption."

**Effort:** ~3 hours including the migration logic + admin notice.

### I3. Decryption failure indistinguishable from "not configured"

**Where:** line 262 returns `''` on failure; the caller at line 75 only checks `empty( $account_sid )`.

```php
// In decrypt_value():
return $decrypted !== false ? $decrypted : '';

// In from_settings():
if ( empty( $account_sid ) || empty( $auth_token ) ) {
    return new WP_Error( 'twilio_not_configured', ... );
}
```

**The problem:** if decryption fails (corrupted ciphertext, salt rotation since encryption, openssl extension lost), the admin sees "Twilio credentials are not configured" instead of "Twilio credentials are present but cannot be decrypted, please re-enter them." The admin has no signal that credentials were ever set, so they don't know to investigate.

**Practical impact:** a perfectly fine setup that loses access (e.g., after a host migration that rotated wp_salt) silently looks like a fresh install. The admin re-enters credentials and may not realize the old saved blob is now garbage data sitting in `wp_options` until they audit the database.

**Recommended fix:** distinguish the two cases. `decrypt_value()` returns `WP_Error` (or a sentinel like `null`) on decryption failure. `from_settings()` checks the type and surfaces the right error: `twilio_credentials_unreadable` vs `twilio_not_configured`. Admin sees an explicit "credentials present but couldn't be decrypted — re-enter them" notice.

**Effort:** ~2 hours.

---

## Non-issue (verified working as intended)

### NI1. The original audit concern (silent plaintext fallback on decryption failure) does NOT exist

**Where:** lines 256–262.

```php
if ( ! function_exists( 'openssl_decrypt' ) ) {
    return '';
}
$decrypted = openssl_decrypt( substr( $value, 4 ), $method, $key, 0, $iv );
return $decrypted !== false ? $decrypted : '';
```

The decryption path returns empty string (not the encrypted blob) when openssl is missing OR when decryption fails. Encrypted ciphertext is never returned as if it were plaintext. The original FRE audit's concern (NI labelled in FORM_RUNTIME_AUDIT.md) is unfounded.

That said, **silently returning empty string** is what causes I3 above — so the fix for I3 also fixes the readability of this branch.

---

## Summary + recommended sequencing

The encryption isn't broken in the alarming "credentials leak as plaintext" sense — that audit concern was unfounded. **It is broken in the cryptographic-integrity sense: deterministic IV defeats CBC mode security, and there are silent-degradation paths (base64 fallback, plain-text passthrough, indistinguishable failure) that hide problems from admins.**

If you tackle this:

**Wave 1 (do first — pure win, no compatibility risk):**
1. **I1** — refuse base64 fallback, surface admin notice. ~2 hours.
2. **I3** — distinguish decryption failure from "not configured." ~2 hours.

**Wave 2 (bigger but more impactful):**
3. **CR1** — random IV per encryption with `enc2:` versioned format + legacy decryption preserved for transition. ~3 hours plus careful testing.
4. **I2** — re-encrypt on read for legacy plain-text values. ~3 hours.

**~10 hours total** to bring the encryption from "functional but cryptographically weak with multiple silent-degradation paths" to "industry standard with explicit error surfaces." All fixes are backwards-compatible — existing stored credentials continue to work through the transition.

**Architectural recommendation:** if the FRE codebase eventually adopts test infrastructure (Audit C3), add a focused unit test class for `class-fre-twilio-client.php::encrypt_value()` and `decrypt_value()` covering: round-trip identity, IV uniqueness across encryptions, behavior when openssl is unavailable, behavior when ciphertext is corrupted, behavior when key is rotated. Credential encryption is exactly the kind of code where a bug ships silently and surfaces at the worst possible time.
