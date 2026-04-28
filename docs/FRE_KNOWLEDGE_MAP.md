# Form Runtime Engine — Connector Knowledge Map

**Date:** 2026-04-21
**Status:** Draft
**Audience:** Any Claude (or other LLM) consumer session using the FRE connector to create, update, or read forms on a WordPress site. Also: plugin engineers maintaining the connector rules.
**Companion docs:**
- `docs/CONNECTOR_SPEC.md` — API contract
- `docs/form-schema.json` — machine-readable JSON Schema for form config validation
- `docs/MCP_CONNECTOR_SETUP.md` — setup flow, bugs, workarounds
- `docs/CONNECTOR_TESTING_REPORT.md` — test evidence

---

## Why this document exists

The FRE connector works. The CRUD operations are solid, the MCP server is wired, the JSON schema is comprehensive. What's missing is the same thing that was missing for Promptless before its Phase 2A hardening: **a consumer-friendly operating manual that a fresh Claude session can read as a single authoritative source** rather than having to fetch and parse the raw JSON Schema document to learn the rules.

The JSON Schema at `docs/form-schema.json` is excellent for programmatic validators. It's dense for humans. A Claude session that opens the FRE connector for the first time needs a rulebook that covers not only what the schema accepts but also: the common drift patterns, which fields need which properties, how column layouts actually work, how conditions are structured, how multistep flows are assembled, and where the gotchas lie.

This document is that rulebook. It's what the connector's preflight `schema_document_url` should point at — or at least, a companion `schema_reference_url` a consumer fetches before any form creation work.

**Scope boundary.** This is authoritative for **connector workflows only**. The FRE admin UI has its own interaction patterns. This doc describes the API as seen through `fre/v1/connector/*`.

---

## 1. Form anatomy

A form is a JSON object with three top-level keys:

```json
{
  "title": "Book a Demo",
  "fields": [ ... ],
  "settings": { ... },
  "steps": [ ... ]
}
```

- `title` — human-readable, shown in admin listings; optionally rendered above the form when `settings.show_title: true`
- `fields` — required, non-empty array. Order drives render order in the default template.
- `settings` — form-level behavior and presentation (optional but almost always wanted)
- `steps` — optional array; only present for multi-step forms

Forms are identified by an `id` (URL-safe slug matching `^[a-z0-9\-_]+$`). The shortcode used to embed a form on a WordPress page is `[fre_form id="<id>"]`.

---

## 2. Field types

FRE supports thirteen field types. All accept the universal base properties in Part 3 plus type-specific extensions.

| Type | Purpose | Type-specific required/common properties |
|------|---------|------------------------------------------|
| `text` | Single-line text input | `placeholder`, `maxlength`, `minlength` |
| `email` | Email input with built-in format validation | `placeholder` |
| `tel` | Phone input | `placeholder`, `description` (helpful for format hints) |
| `textarea` | Multi-line text | `rows`, `cols`, `maxlength` |
| `select` | Dropdown | `options` (required, at least 1), `multiple` |
| `radio` | Radio button group | `options` (required, at least 1), `inline` (render horizontally) |
| `checkbox` | Single boolean OR checkbox group | `options` (optional — present for group, omitted for single boolean), `inline` |
| `file` | File upload | `allowed_types` (array of extensions), `max_size` (bytes, default 5MB), `multiple` |
| `hidden` | Hidden input | `default` (the hidden value) |
| `message` | Inline instructional content | `content` (HTML, sanitized with `wp_kses_post`) OR `label`; `style` (info/warning/success/error) |
| `section` | Visual section header to group fields | `label` (the header text) |
| `date` | Date picker | `min`, `max` (both as YYYY-MM-DD strings) |
| `address` | Google Places autocomplete address | `country_restriction` (array of lowercase ISO 3166-1 alpha-2 codes) |

**Required-options rule:** `select` and `radio` MUST include an `options` array with at least one entry. `checkbox` may or may not — with options it renders as a group, without it renders as a single boolean.

**Message-field rule:** `message` fields must have either `label` or `content` (or both). They exist to display information; neither being present would make the field meaningless.

---

## 3. Universal field properties

Every field supports these properties regardless of type:

| Property | Type | Notes |
|----------|------|-------|
| `key` | string (required) | Unique within the form. Regex `^[a-zA-Z][a-zA-Z0-9_-]*$`. Snake_case is the project convention. |
| `type` | string (required) | One of the 13 values above. |
| `label` | string | Displayed above/next to the input |
| `placeholder` | string | Ghost text inside input |
| `required` | boolean | Default false |
| `default` | string/array | Pre-filled value; type matches field value shape |
| `description` | string | Help text rendered below the field |
| `css_class` | string | Custom CSS class added to the field wrapper |
| `maxlength` / `minlength` | integer | Character length constraints (text/textarea) |
| `readonly` / `disabled` | boolean | Input state |
| `autocomplete` | string | HTML `autocomplete` attribute value (e.g. `"name"`, `"email"`, `"tel"`) |
| `column` | string | Fractional column width — **see Part 4** |
| `section` | string | Groups field under a section-type field by key |
| `step` | string | Assigns field to a multi-step step by key |
| `conditions` | object | Conditional visibility — **see Part 5** |

---

## 4. Column layout — side-by-side fields

FRE supports column-based layouts via the `column` property on fields. This is how you make fields appear side-by-side instead of stacked.

**Valid values:** `"1/2"`, `"1/3"`, `"2/3"`, `"1/4"`, `"3/4"`

**Behavior:** Adjacent fields with column values that sum to 1 (or fit in a row) render side-by-side. The renderer handles row wrapping automatically — when a field's column width doesn't fit with the previous row, it starts a new row. Fields without a `column` property are full-width by default.

**Example — 3-row compact form from 5 fields:**

```json
"fields": [
  { "key": "name",  "type": "text",  "column": "1/2" },
  { "key": "email", "type": "email", "column": "1/2" },
  { "key": "phone", "type": "tel" },
  { "key": "business", "type": "select", "column": "1/2", "options": [...] },
  { "key": "best_time", "type": "select", "column": "1/2", "options": [...] }
]
```

Renders as:
- Row 1: Name | Email (1/2 + 1/2)
- Row 2: Phone (full width)
- Row 3: Business | Best Time (1/2 + 1/2)

**Important:** `column` controls visual width only. Validation rules, submitted data, and the field's logical order in submission payloads are unchanged.

**Responsive handling:** column layouts collapse to single column on narrow viewports automatically. Consumers don't need to handle breakpoints.

---

## 5. Conditional visibility

Fields can be shown or hidden based on other fields' values using the `conditions` property.

```json
{
  "key": "other_source",
  "type": "text",
  "label": "Please specify",
  "conditions": {
    "rules": [
      { "field": "source", "operator": "equals", "value": "other" }
    ],
    "logic": "and"
  }
}
```

**Rule structure:**
- `field` (required) — key of another field in this form
- `operator` — one of: `equals`, `not_equals`, `contains`, `not_contains`, `is_empty`, `is_not_empty`, `is_checked`, `is_not_checked`, `greater_than`, `less_than`, `>=`, `<=`, `in`, `not_in`
- `value` — scalar for equals/contains/>, array for `in`/`not_in`, absent for the `_empty`/`_checked` operators

**Rule combination:**
- `logic: "and"` — all rules must match for the field to show (default)
- `logic: "or"` — any rule matching shows the field

**Common pattern:** "Other" option on a select that reveals a text field for the user to specify. Above example does exactly that.

---

## 6. Multi-step forms

Forms can be split into multiple steps using a top-level `steps` array plus per-field `step` assignment.

```json
{
  "fields": [
    { "key": "name",  "type": "text",  "step": "contact" },
    { "key": "email", "type": "email", "step": "contact" },
    { "key": "service", "type": "select", "step": "details", "options": [...] },
    { "key": "budget",  "type": "select", "step": "details", "options": [...] }
  ],
  "steps": [
    { "key": "contact", "title": "Contact info" },
    { "key": "details", "title": "Project details" }
  ],
  "settings": {
    "multistep": {
      "show_progress": true,
      "progress_style": "steps",
      "validate_on_next": true,
      "show_step_titles": true
    }
  }
}
```

**Rules:**
- Every step needs a unique `key` matching `^[a-zA-Z][a-zA-Z0-9_-]*$`
- Fields without a `step` assignment render before the first step (rare; usually you assign every field)
- `multistep.progress_style` values: `"steps"` (default), `"bar"`, `"dots"`
- `validate_on_next: true` runs validation when clicking Next (prevents progress past invalid fields)

---

## 7. Settings

Form-level behavior is controlled via the `settings` object:

```json
"settings": {
  "submit_button_text": "Book My Demo",
  "success_message": "Thanks — we'll reach out within one business day.",
  "redirect_url": null,
  "show_title": false,
  "css_class": "",
  "store_entries": true,
  "theme_variant": "dark",
  "notification": { ... },
  "spam_protection": { ... },
  "multistep": { ... },
  "webhook_enabled": false,
  "webhook_url": "",
  "webhook_preset": "custom"
}
```

### 7.1 Presentation

- `submit_button_text` — default `"Submit"`
- `success_message` — default `"Thank you for your submission."` Shown inline after successful submit.
- `redirect_url` — if set, redirects to this URL after success instead of showing the inline message. Must be a valid URL. Use null or omit to keep inline behavior.
- `show_title` — whether to render the form's title above the form. Default false.
- `css_class` — custom class applied to the form's outer container.
- `theme_variant` — `"light"` | `"dark"` | `"auto"`. Default `"light"`. **Set to `"dark"` when the form renders inside a dark-background section** so input styling matches. Forms inside an `.aisb-section--dark` ancestor auto-inherit dark mode via CSS selector, but setting this explicitly is still the recommended pattern.

### 7.2 Form surface / visual treatment

Controls whether the form renders as a flat element directly on the parent section's background, or as a card with its own surface, border, and padding that inherits from the AISB design tokens.

```json
"appearance": {
  "surface": "card"
}
```

- `appearance.surface` — `"none"` (default) | `"card"`

**Vocabulary.** Users often describe this feature with words like *card*, *surface*, *wrapper*, *container around the form*, *box around the fields*. They all map to the same control. When a user asks to "wrap the form in a card" or "give the form a container" or "put it on its own surface," reach for `settings.appearance.surface = "card"`.

**When to use which route.** FRE supports two card-producing patterns and they exist for different use cases:

| Goal | Use |
|---|---|
| Whole form should look like one card on the section | `settings.appearance.surface = "card"` |
| Form needs multiple distinct grouped regions (e.g. "Contact info" card above a "Message" card) | Multiple `section` field types (one per group) |
| Form has a single logical group and should look like a card | Either approach works — prefer `appearance.surface` for simplicity |

**Conflict handling.** When `settings.appearance.surface = "card"` is set AND the form also contains `section` field types, the inner section cards are automatically flattened (visually — the structural grouping still works) to avoid nested-card artifacts. You don't have to pick one or the other; you can mix them and the outer card wins.

**Token inheritance.** The card's background uses `var(--fre-surface-color)` which resolves to `var(--aisb-color-surface)` in light mode and `var(--aisb-color-dark-surface)` in dark mode. Border uses `var(--fre-border-color)`. Radius uses the AISB card radius. Padding scales with `var(--fre-spacing)`. No dark-mode-specific configuration needed — the existing `theme_variant` or `.aisb-section--dark` inheritance handles the flip automatically.

**Multi-step compatibility.** The surface class sits on the `<form>` root, which is outside the progress indicator and step nav. Multi-step forms render as a single card containing all steps.

### 7.3 Notifications

Email notifications sent on successful submission.

```json
"notification": {
  "enabled": true,
  "to": "sales@example.com",
  "subject": "New demo request from {field:name}",
  "from_name": "FlowMint",
  "from_email": "noreply@example.com",
  "reply_to": "{field:email}"
}
```

- `to` — single address (comma-separated for multiple) or array of addresses. `{admin_email}` template token supported.
- `subject`, `from_name`, `from_email` — standard email headers
- `reply_to` — supports `{field:<key>}` template tokens to pull values from the submission (example above sets Reply-To to the submitter's email)
- Template tokens (`{field:<key>}`) resolve **option labels** for select/radio/checkbox-with-options fields, not raw values — so `{field:business_type}` substitutes `Home services (HVAC, plumbing, roofing, etc.)` rather than `home_services`.

**Empty-field rendering** — by default, optional fields with empty values are skipped from the body table (keeps notifications scannable). Per-form override:

```json
"hide_empty_fields": false
```

When `false`, every field renders with an em-dash (`—`) placeholder for empty values. Useful when emails feed downstream tooling that expects a fixed table shape (e.g., a parser that pulls field values by row index). Required fields with empty values are always rendered — they signal a data integrity issue. Conditionally-hidden fields (whose `conditions` block evaluates false) are ALWAYS skipped regardless of this flag because they were never visible to the submitter.

### 7.4 Spam protection

```json
"spam_protection": {
  "honeypot": true,
  "timing_check": true,
  "min_submission_time": 3,
  "rate_limit": { "max": 5, "window": 300 }
}
```

- `honeypot` — hidden field bots fill in. Default true. The field name is **dynamically generated per form** as `_fre_website_url_<hmac-suffix>` — do not hard-code it into test submissions. `formengine_test_submit` via the connector handles this correctly; the warning is for any direct REST consumer that might imitate form posts.
- `timing_check` + `min_submission_time` — blocks submissions made faster than this many seconds after form load. Default 3 seconds. Submissions under this window are **silently rejected** (no error surfaced to the user). Relevant for automated test flows that call `formengine_test_submit` immediately after create — either pass `options.dry_run = true` or insert a small delay.
- `rate_limit` — max N submissions per W seconds per IP. Default max=5, window=3600 (1 hour). Omit for no rate limit.

### 7.5 Webhook

```json
"webhook_enabled": true,
"webhook_url": "https://hooks.zapier.com/...",
"webhook_preset": "zapier"
```

- `webhook_url` — HTTPS endpoint that receives submission payload POSTed as JSON. SSRF-protected at send time (private IP ranges rejected).
- `webhook_preset` — `"custom"` (default), `"google_sheets"`, `"zapier"`, `"make"`. Preset drives the smart default for option-label resolution: **`google_sheets` resolves option values to labels** in the payload (the destination is typically a human-reviewed lead tracker where labels are easier to scan), while **`zapier`, `make`, `custom` emit raw values** by default (those typically feed machine-readable integrations that prefer stable identifiers that don't break when option labels are renamed).

**Per-form override:**

```json
"webhook_resolve_option_labels": true
```

`true` forces label resolution regardless of preset. `false` forces raw values regardless of preset. Omit to use the preset-aware default.

**File uploads in webhook payloads** — the payload's `files` array carries one entry per uploaded file with `field_key`, `file_name`, `file_size`, `mime_type`, and `file_url`. The URL is publicly fetchable (uses randomized UUID filenames for non-enumerability) so downstream automations can copy the file into Drive, S3, etc. The webhook fires on the `fre_submission_complete` action — AFTER files are attached to the entry — so `file_url` is always populated. For sensitive industries (healthcare, legal, financial), generate signed/expiring URLs via the `fre_webhook_file_url` filter.

**Webhook secrets:** NOT exposed via API. Use the admin UI for secret rotation.

---

## 8. Critical rules and drift patterns

The most important rules a consumer session should internalize. These are the patterns where mistakes silently fail or produce unexpected behavior:

### 8.1 Config is a STRING, not an object

When calling `formengine_create_form` or `formengine_update_form`, the `config` parameter must be a **JSON string**, not a JavaScript/Python object. The MCP tool layer does NOT stringify for you.

```javascript
// WRONG
formengine_create_form({ config: { fields: [...] } })

// RIGHT
formengine_create_form({ config: JSON.stringify({ fields: [...] }) })
```

The error if you get this wrong is `invalid_config_json`.

### 8.2 Required `options` on select/radio

Passing a `select` or `radio` field without an `options` array fails JSON Schema validation. This is enforced server-side — you get a 400 `schema_validation_failed`.

### 8.3 `column` values are strings, not numbers

Use `"1/2"` not `0.5` or `"50%"`. Only these five values are accepted: `1/2`, `1/3`, `2/3`, `1/4`, `3/4`. Anything else fails schema validation.

### 8.4 `form_id` is URL-safe lowercase

Must match `^[a-z0-9\-_]+$`. No spaces, no caps. This becomes the shortcode attribute, so it's also the form's canonical public identifier.

### 8.5 Entry reads require separate capability

Reading submission entries via `formengine_list_entries` or `formengine_get_entry` requires the connector's entry-read toggle to be enabled in the admin UI AND the authenticated user to have `fre_manage_forms`. Entry-read is a separate setting from "connector enabled" because entry data is more sensitive.

### 8.6 `managed_by` is immutable post-create

Forms created via the connector are tagged `managed_by: "connector:cowork"`. Forms created via admin UI are tagged `managed_by: "admin"`. This tag cannot be changed through the API. Use it via `managed_by` filter on `formengine_list_forms` to avoid editing admin-authored forms.

### 8.7 Webhook secrets and rotation

Webhook secrets are intentionally omitted from all API responses. To rotate a secret, the user must use the admin UI. The API does not support secret management because exposing secrets through a remote connector is a security anti-pattern.

### 8.8 Theme variant on dark-background forms

If the form renders inside a section with a dark background (common when embedded in a Promptless hero with `theme_variant: "dark"`), set the FRE form's `settings.theme_variant: "dark"` so inputs have appropriate contrast. Forgetting this produces light-theme inputs on a dark background, which looks wrong and may fail WCAG AA.

### 8.9 Test-submit vs. real submit

The `formengine_test_submit` tool runs the full validation pipeline but does NOT write entries, does NOT send notifications, and does NOT fire webhooks (when `dry_run: true`). Use this for verifying form setup before exposing to users. `dry_run: false` performs a real submission with all side effects.

### 8.10 Form surface / card vocabulary

When a user asks to "wrap the form in a card," "put a container around the form," "add a box around the fields," or similar — they are referring to the form-level surface treatment documented in §7.2. The default for every form is `surface: "none"` (flat fields on the parent section background). Setting `settings.appearance.surface = "card"` turns the whole form into a design-token-aware card. Use that in preference to introducing a `section` field wrapper when the form is a single logical group.

If the form has multiple distinct logical groups (e.g. "Contact info" and "Message") use one `section` field per group — those render as their own cards. Both mechanisms can coexist, but the outer `appearance.surface` card automatically flattens the inner section cards to avoid nested-card visual artifacts.

### 8.11 Dynamic honeypot field name

The spam-protection honeypot field name is **not static**. FRE generates a per-form name of the form `_fre_website_url_<hmac-suffix>` at render time. Never hard-code it into a test submission: the server rejects any submission that fills it. When using `formengine_test_submit` via this connector, the handling is correct automatically — this warning is for any direct REST consumer that might imitate form posts.

### 8.12 Timing check silent-reject window

`settings.spam_protection.min_submission_time` defaults to 3 seconds. Submissions posted faster than that are **silently rejected** (no error surfaced). Relevant for automated test flows that call `formengine_test_submit` immediately after create — either pass `options.dry_run: true` or insert a small delay before the test submit.

### 8.13 AISB token inheritance

When AI Section Builder Modern (Promptless WP) is active, FRE forms automatically inherit brand design tokens — primary / text / background / border colors, heading and body fonts, button and card border-radius, and neo-brutalist mode if enabled — via CSS custom properties (`--aisb-*`). Forms inside an `.aisb-section--dark` ancestor auto-inherit dark mode even without `settings.theme_variant = "dark"` (though setting it is still recommended for clarity). See `docs/AISB_TOKEN_CONTRACT.md` for the complete list of consumed tokens, fallbacks, and the minimum AISB version required. Do NOT introduce new `--aisb-*` token references in FRE CSS without updating that contract first.

---

## 9. Quick reference

**Minimum valid form:**

```json
{
  "fields": [
    { "key": "email", "type": "email", "label": "Your email", "required": true }
  ]
}
```

**Two-column demo form (5 fields → 3 rows):**

```json
{
  "title": "Book a Demo",
  "fields": [
    { "key": "name", "type": "text", "label": "Full name", "required": true, "column": "1/2" },
    { "key": "email", "type": "email", "label": "Email", "required": true, "column": "1/2" },
    { "key": "phone", "type": "tel", "label": "Phone", "required": true, "description": "We'll text or call to confirm." },
    { "key": "business", "type": "select", "label": "Business type", "required": true, "column": "1/2",
      "options": [{"value":"services","label":"Services"},{"value":"retail","label":"Retail"}] },
    { "key": "best_time", "type": "select", "label": "Best time", "column": "1/2",
      "options": [{"value":"morning","label":"Morning"},{"value":"afternoon","label":"Afternoon"}] }
  ],
  "settings": {
    "submit_button_text": "Book My Demo",
    "success_message": "Thanks — we'll reach out within one business day.",
    "theme_variant": "dark",
    "notification": {
      "enabled": true,
      "to": "{admin_email}",
      "subject": "New demo request from {field:name}",
      "reply_to": "{field:email}"
    }
  }
}
```

**Multi-step form (2 steps):**

```json
{
  "fields": [
    { "key": "name", "type": "text", "step": "s1", "required": true },
    { "key": "email", "type": "email", "step": "s1", "required": true },
    { "key": "service", "type": "select", "step": "s2", "required": true, "options": [...] }
  ],
  "steps": [
    { "key": "s1", "title": "Contact" },
    { "key": "s2", "title": "Service" }
  ],
  "settings": {
    "multistep": { "show_progress": true, "validate_on_next": true }
  }
}
```

**Card-wrapped form (form-level surface):**

```json
{
  "title": "Request a Demo",
  "fields": [
    { "key": "name", "type": "text", "label": "Full name", "required": true, "column": "1/2" },
    { "key": "email", "type": "email", "label": "Email", "required": true, "column": "1/2" },
    { "key": "message", "type": "textarea", "label": "What are you hoping to solve?" }
  ],
  "settings": {
    "appearance": { "surface": "card" },
    "theme_variant": "dark",
    "submit_button_text": "Request Demo"
  }
}
```

Reach for this pattern when the whole form should look like a single card on its parent section. Works for single-step, multi-step, and conditional forms alike — the wrapper sits on the `<form>` root, outside the step nav.

---

## 10. What's NOT yet codified in the connector

As of 2026-04-21, these are known gaps between the FRE connector and the matured Promptless connector pattern. They're the subjects of the forthcoming Phase 2 hardening plan.

1. **No inline `critical_rules` in preflight.** Consumers must fetch `schema_document_url` to learn the rules. This is fine for a curious session but adds friction for a task-focused one. Promptless returns its rules inline; FRE should too.

2. **MCP tool descriptions don't mandate schema-doc reads.** `formengine_create_form` says "see docs/form-schema.json" but doesn't say "MUST fetch first." Fresh sessions may improvise field shapes and hit validation errors they could have avoided.

3. **No companion human-readable schema_reference_url.** The existing `schema_document_url` returns the JSON Schema which is machine-optimal but human-dense. A parallel markdown knowledge map (this document, once served from an endpoint) would be higher-value for consumer sessions.

4. **No field_hints summary in preflight.** Promptless includes `field_name_hints` per section type. FRE could include a similar map of field types with their required properties.

5. **Schema validation is strict but error messages could be more actionable.** The current `schema_validation_failed` errors include field paths but don't always suggest the correct shape. Promptless's pricing-features error message includes a corrective example.

---

**END.**

This document is the canonical consumer rulebook for the FRE connector. When field schema, settings, or behavior changes in ways that affect consumer usage, update the relevant section and bump the header date.
