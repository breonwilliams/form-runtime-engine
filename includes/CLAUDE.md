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

### Webhook Security

- **HMAC-SHA256 signing**: Per-form secrets, sent as `X-FRE-Signature: sha256={hash}` header
- **SSRF protection**: Blocks webhook URLs targeting private IP ranges
- **URL validation**: At both save and dispatch time
- **Payload filtering**: Honeypot/timing fields excluded from webhook payloads
- **Non-blocking dispatch**: Webhooks sent asynchronously to avoid slowing form submission
- **Retry with backoff**: Failed webhooks retry up to 3 times (5min, 30min, 2hr)

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
