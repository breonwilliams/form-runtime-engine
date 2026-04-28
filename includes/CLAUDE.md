# Form Runtime Engine - Internal Architecture

This file covers security features, validation details, and internal implementation notes.
For core API reference, see the root `CLAUDE.md`. For form patterns and examples, see `docs/CLAUDE.md`.

---

## Security Features

### CSS Validation

Custom CSS is validated and sanitized to prevent malicious code injection. The following patterns are **blocked**:

| Pattern | Reason |
|---------|--------|
| `expression()` | IE JavaScript execution via CSS expressions |
| `behavior:` | IE HTC files (HTML Components) |
| `-moz-binding:` | Firefox XBL bindings |
| `javascript:` | JavaScript URLs in CSS |
| `@import` | External stylesheet loading (data exfiltration risk) |
| `data:` | Data URIs (can contain embedded scripts) |
| `vbscript:` | VBScript URLs |
| `base64` | Base64 encoded content (often hides malicious code) |

**Syntax Validation:**
- Balanced braces `{ }` are required
- Balanced parentheses `( )` are required
- URLs with dangerous protocols are blocked

**Example Errors:**
```
"CSS contains potentially unsafe pattern: expression()"
"CSS contains potentially unsafe pattern: @import"
"Invalid CSS syntax: unbalanced braces. Check that all { have matching }."
```

### JSON Schema Validation

Form configurations are validated for structure and field types. This prevents invalid configurations and catches common errors early.

**Validated:**
- `fields` array is present and non-empty
- Each field has required `key` and `type` properties
- Field types are valid (text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, address)
- No duplicate field keys
- Select/radio fields have `options` array
- Steps configuration (if multi-step form)

**Warnings (non-fatal):**
- Unknown field properties (logged but allowed for flexibility)
- Unknown settings properties
- Invalid condition rule structure
- Fields referencing non-existent steps

**Example Errors:**
```
"Field at index 2 is missing required 'key' property."
"Invalid field type 'dropdown' for field 'country'. Valid types: text, email, tel, ..."
"Duplicate field keys found: email, name"
"Field 'service' requires an 'options' array."
```

### Allowed HTML in Message Fields

The `message` field type supports HTML content for display. The HTML is sanitized using WordPress's `wp_kses_post()` which allows safe tags:

**Allowed Tags:**
- Headings: `<h1>` - `<h6>`
- Paragraphs: `<p>`
- Lists: `<ul>`, `<ol>`, `<li>`
- Inline: `<strong>`, `<em>`, `<a>`, `<span>`, `<br>`
- Structural: `<div>`, `<blockquote>`

**Stripped:**
- `<script>`, `<style>`, `<iframe>` tags
- Event attributes (`onclick`, `onerror`, etc.)
- JavaScript URLs in `href`

### Spam Protection

Built-in spam protection (enabled by default):
- **Honeypot field** - Hidden field that bots fill out
- **Timing check** - Rejects submissions faster than 3 seconds
- **Rate limiting** - Max 5 submissions per hour per IP

Configure in form settings:
```php
'settings' => array(
    'spam_protection' => array(
        'honeypot'            => true,
        'timing_check'        => true,
        'min_submission_time' => 3,
        'rate_limit'          => array(
            'max'    => 5,
            'window' => 3600,
        ),
    ),
),
```

### File Upload Validation

Defense-in-depth chain run on every uploaded file (in order, fail-closed at any step):

1. **Filename sanitization** — null-byte stripping, RTL/LTR/zero-width Unicode character removal (prevents `image.gpj[RTL]php.` visual spoofing), homograph detection in extensions (Cyrillic vs Latin lookalikes), 255-character cap.
2. **Blocked extensions** — `.php`, `.phtml`, `.phar`, `.shtml`, `.htaccess`, `.htpasswd`, `.env`, `.ini`, `.conf`, etc., **including double-extension detection** (`shell.php.dst` is blocked even though `.dst` is allowed).
3. **MIME validation** — extension must match the file's detected MIME type via `finfo`.
4. **Dangerous-pattern scan** — chunked file scan for `<?php`, `<?=`, `<%`, `<script`, `eval(`, `exec(`, `system(`, `passthru(`, base64-encoded PHP (`PD9waHA`), `preg_replace` with `/e` modifier, `mb_ereg_replace` with `e`, and other code-execution patterns. Catches polyglot uploads regardless of how the magic bytes look.
5. **Magic-byte verification** — first N bytes must match a known signature for the extension. Built-in coverage for images (jpg/jpeg/png/gif/webp/bmp), documents (pdf/doc/docx/xls/xlsx/ppt/pptx/odt/ods), archives (zip/rar), audio/video (mp3/mp4/wav), and design formats (`.ai` accepts both PDF and PostScript headers, `.eps` matches `%!PS-Adobe`, `.dst` matches the Tajima `LA:` label header).
6. **Minimum file size** — defense-in-depth against polyglot uploads with short magic bytes. Defaults: `.ai` ≥ 1024 B, `.eps` ≥ 100 B, `.dst` ≥ 500 B. Filterable via `fre_min_file_sizes` for sites that register custom formats (e.g., `.pes` Brother embroidery, `.cdr` CorelDRAW).
7. **Maximum file size** — per-field `max_size` enforcement (default 5 MB).
8. **SVG content scan** — when SVGs are allowed, scans for `<script>`, event handlers, `javascript:` URLs, `<foreignObject>`, `<embed>`, `<object>`, `<iframe>`, XXE entities, and similar XSS vectors.
9. **TOCTOU-protected rename** — file moves from quarantine to its final UUID-named location; rename fails closed if the target path already exists or is a symlink.
10. **Restrictive permissions** — uploaded files get mode `0644` (matches WordPress core media; web-server readable so direct URL access works for emailed download links and webhook consumers like Zapier). Override via the `fre_uploaded_file_permissions` filter — sites running suEXEC that prefer the stricter `0600` can return that mode.

The quarantine directory itself (`uploads/fre-uploads/`) ships with a generated `.htaccess` denying all access plus an `index.php` silencer plus a `web.config` for IIS — even if a malicious file somehow lands there, it cannot be served or executed by URL.

**Extending validation for custom formats:**

```php
// Architects accepting AutoCAD .dwg files
add_filter( 'fre_mime_map', function ( $map ) {
    $map['dwg'] = array( 'application/acad', 'image/vnd.dwg', 'application/octet-stream' );
    return $map;
} );

add_filter( 'fre_min_file_sizes', function ( $min ) {
    $min['dwg'] = 2048; // 2KB — minimal valid DWG header is well over this
    return $min;
} );
```

When an extension has no stable magic-byte signature (truly arbitrary binary), return an empty array from the `fre_magic_bytes` filter to skip strict signature verification — validation falls back to extension+MIME match plus the dangerous-pattern scan, which is still strong.

### Webhook Security

- **HMAC-SHA256 signing**: Per-form secrets, sent as `X-FRE-Signature: sha256={hash}` header
- **SSRF protection**: Blocks webhook URLs targeting private IP ranges
- **URL validation**: At both save and dispatch time
- **Payload filtering**: Honeypot/timing fields excluded from webhook payloads
- **Non-blocking dispatch**: Webhooks sent asynchronously to avoid slowing form submission
- **Retry with backoff**: Failed webhooks retry up to 3 times (5min, 30min, 2hr)
- **File URL exposure**: Each `files[]` entry includes a `file_url` for downstream automations to fetch. Filenames are randomized UUIDs — URLs aren't enumerable, but they ARE permanent until the file is deleted. Webhook destinations (Zapier especially) log payloads, so URLs persist in those destinations' history. For sensitive uploads, use the `fre_webhook_file_url` filter to generate signed/expiring URLs (see the "Sensitive uploads" section in the root `CLAUDE.md` for a working `hash_hmac()` example).

### Webhook Log Database Table

The `{prefix}_fre_webhook_log` table tracks all webhook dispatches:

| Column | Type | Description |
|--------|------|-------------|
| `id` | bigint | Auto-increment primary key |
| `entry_id` | bigint | Form entry ID |
| `form_id` | varchar(100) | Form identifier |
| `url` | text | Webhook endpoint URL |
| `status` | varchar(20) | pending, success, failed, retrying |
| `attempts` | int | Number of delivery attempts |
| `response_code` | int | Last HTTP response code |
| `response_body` | text | Last response body (truncated to 1000 chars) |
| `error_message` | text | Last error message if failed |
| `created_at` | datetime | When the webhook was first dispatched |
| `updated_at` | datetime | When the record was last updated |
