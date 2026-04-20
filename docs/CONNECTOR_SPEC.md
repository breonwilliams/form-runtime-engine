# Form Runtime Engine — Cowork Connector API Specification

**Status:** Phase 2 (REST API + auth) — active
**Version:** v1 (URL-namespaced; breaking changes will bump to `/v2/`)
**Audience:** Claude Cowork and any external integration that needs to programmatically manage forms and read submission entries on a WordPress site running this plugin.

---

## 1. Purpose

This document is the stable, versioned contract between the Form Runtime Engine's REST API and any external consumer — primarily the Claude Cowork connector MCP server (Phase 3+). It complements:

- **`docs/form-schema.json`** — the JSON Schema for form configurations that the `POST /forms` and `PATCH /forms/{id}` endpoints accept.
- **`docs/COWORK_CONNECTOR_ASSESSMENT.md`** — the design document that explains why the API is shaped this way.
- **`docs/AISB_TOKEN_CONTRACT.md`** — the separate design-token contract with Promptless WP (unrelated to this API but part of the same architectural family).

Consumers should pin to the API version in the URL path (`/v1/`). Anything not documented here is not part of the contract and may change at any time.

---

## 2. Base URL and namespace

All connector endpoints live under a single namespace:

```
https://{site_url}/wp-json/fre/v1/connector/
```

The `/connector/` prefix is intentional. It separates connector endpoints from any future non-connector REST surface the plugin might add (for example, a public form-render API) and makes connector traffic easy to spot in access logs.

The Twilio subsystem uses its own namespace (`/wp-json/fre-twilio/v1/`) and is not related to this contract.

---

## 3. Authentication

**Method:** HTTP Basic Auth using WordPress Application Passwords.

```
Authorization: Basic base64(username:application_password)
```

The credential is a WordPress Application Password created through the plugin's "Claude Connection" admin page (see §8). Application Passwords are a built-in WordPress 5.6+ feature; this plugin does not define its own authentication scheme.

**Requirements:**

- The site must run over HTTPS. Application Passwords require HTTPS by WordPress core policy (local-development sites may bypass via `wp_is_application_passwords_available` filter).
- The authenticating user must have the `fre_manage_forms` capability (added by the plugin — see the Phase 1 capability introduction). The administrator role receives this capability automatically on plugin install or upgrade.
- The connector must be enabled site-wide via the toggle on the "Claude Connection" admin page. Disabled connector returns `403 connector_disabled` on every route (see §7).

**Credential rotation:** Generating a new App Password through the admin UI revokes any prior Form Runtime Engine App Password for the same user. At most one connector credential exists per user per site at any time.

**What the API will never do:**

- Ask for or accept cookies for cross-origin use. Cookies exist for the admin UI only.
- Accept tokens in URL query strings.
- Expose a password reset flow.

---

## 4. Permission stack

Every request to a connector endpoint passes through three concentric checks, in order:

1. **Connector enabled?** Site owner must have toggled "Enable Claude Cowork Connection" on. Default is off. Failing this check returns `403 connector_disabled` before any authentication happens.
2. **Authenticated?** Must present valid App Password credentials. Failing returns `401 rest_not_logged_in`.
3. **Authorized?** Authenticated user must have the `fre_manage_forms` capability. Failing returns `403 rest_forbidden`.

A fourth check — entry-read toggle — applies only to the entries endpoints (`/entries`, `/entries/{id}`). If the site owner has not explicitly enabled entry read access, these two endpoints return `403 entry_access_disabled` even for an otherwise fully authenticated caller. All other endpoints are unaffected by this toggle. The toggle default is off. See §5 for threat-model reasoning.

---

## 5. Two-gate security design

The connector is protected by two distinct opt-in toggles, each addressing a different concern:

**Gate 1 — "Enable Claude Cowork Connection" (outer gate).** Controls whether the REST API namespace responds at all. Default off. This is the site-wide kill switch. Mature WordPress plugins with external API surfaces (Gravity Forms, Ninja Forms, WooCommerce REST) all implement an equivalent default-off toggle because default-on APIs multiply attack surface across every installation of the plugin, most of which do not need the feature.

**Gate 2 — "Allow Claude Cowork to read form submissions" (entry gate).** Controls whether the two entry-read endpoints respond. Default off. Entry bodies contain end-user PII (names, emails, phone numbers, free-text message content, sometimes address data). This gate is distinct from the outer gate because form creation and administration workflows are common, whereas reviewing submission data is a narrower use case with a larger privacy footprint.

A site owner can enable gate 1 without gate 2 — the resulting connector can create, update, delete, list, and test-submit forms, but cannot read submission bodies. This is the recommended posture for most agency workflows.

---

## 6. Rate limits

Limits are per authenticated user, per endpoint, via WordPress transients. Window is one minute.

| Route | Limit/min | Rationale |
|---|---|---|
| `GET /preflight` | 60 | Cheap; connector may poll for health checks |
| `GET /forms` | 60 | Read-mostly listing |
| `GET /forms/{id}` | 60 | Read-mostly single fetch |
| `POST /forms` | 10 | Protects DB; allows batch form generation sessions |
| `PATCH /forms/{id}` | 10 | Same as create |
| `DELETE /forms/{id}` | 5 | Destructive; throttle harder |
| `GET /entries` | 60 | Read-mostly |
| `GET /entries/{id}` | 60 | Read-mostly |
| `POST /forms/{id}/submit` with `dry_run: true` | 30 | Validation-only, cheap |
| `POST /forms/{id}/submit` with `dry_run: false` | 5 | Real write, throttle harder |

Rate-limit failures return HTTP 429 with a `Retry-After` header and a JSON body including `code: "rate_limit_exceeded"` and `data.retry_after: <seconds>`.

These values are starting points. Future tuning will be based on telemetry from production use.

---

## 7. Response format

All responses are `Content-Type: application/json`.

**Success** responses follow one of two shapes depending on the endpoint semantic:

Resource representation (for read/write endpoints):
```json
{
  "success": true,
  "data": { ... endpoint-specific body ... }
}
```

Collection (for list endpoints):
```json
{
  "success": true,
  "data": [ ... items ... ],
  "meta": {
    "total": 42,
    "page": 1,
    "per_page": 20,
    "has_more": true
  }
}
```

**Error** responses follow WordPress's `WP_Error` convention:
```json
{
  "code": "error_code_identifier",
  "message": "Human-readable error message.",
  "data": {
    "status": 400,
    "...": "... optional context ..."
  }
}
```

**Error codes** are stable strings and consumers may branch on them. Messages are for humans and may change across versions.

| Code | HTTP | Meaning |
|---|---|---|
| `connector_disabled` | 403 | Outer gate is off. Administrator must enable the connector. |
| `entry_access_disabled` | 403 | Inner gate (entries) is off. Administrator must enable entry read access. |
| `rest_not_logged_in` | 401 | No or invalid credentials. |
| `rest_forbidden` | 403 | Authenticated but lacks `fre_manage_forms` capability. |
| `rate_limit_exceeded` | 429 | Per-user per-route limit hit. Retry after `data.retry_after` seconds. |
| `form_not_found` | 404 | No form exists with the given ID. |
| `entry_not_found` | 404 | No entry exists with the given ID. |
| `invalid_id` | 400 | Form ID violates the `^[a-z0-9\-_]+$` pattern. |
| `empty_config` | 400 | Form `config` field is empty on create or update. |
| `invalid_json` | 400 | Form `config` is not valid JSON. |
| `schema_error` | 400 | Form config fails JSON schema validation. `data.errors[]` contains the details. |
| `form_exists` | 409 | POST to `/forms` with an ID that already exists. Use PATCH for updates. |
| `invalid_form_id` | 400 | Submit endpoint received an empty `form_id` path parameter. |
| `validation_failed` | 400 | Submit endpoint data fails form field validation. `data.field_errors{}` maps field keys to error arrays. |
| `submission_failed` | 500 | Submit endpoint encountered a database error. |

HTTP status is always set via the `data.status` field of the `WP_Error`. Consumers may rely on both.

---

## 8. Admin UI

The "Claude Connection" admin page lives at:

```
Form Entries → Claude Connection
```

(Submenu slug: `fre-claude-connection`.)

The page exposes:

- **Enable Claude Cowork Connection** toggle. Default off. Controls gate 1.
- **Allow Claude Cowork to read form submissions** toggle. Default off. Controls gate 2.
- **Generate / Revoke Connection** button. Creates or removes the connector's WordPress Application Password. Any existing Form Runtime Engine App Password for the current user is revoked before a new one is created, so at most one active credential exists per user at any time.
- **Setup command section.** Placeholder in Phase 2. Phase 3 will fill this with the bash command that installs the MCP server into Claude Desktop's configuration.
- **Link to this specification document** so API consumers can find the contract without leaving the admin.

All actions require the `fre_manage_forms` capability.

---

## 9. Endpoints

### 9.1 `GET /preflight`

Health check. Returns information about the connector state from the caller's perspective.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "plugin_version": "1.2.5",
    "connector_api_version": "v1",
    "connector_enabled": true,
    "entry_read_enabled": true,
    "authenticated_as": "admin",
    "user_capabilities": {
      "fre_manage_forms": true
    },
    "schema_document_url": "https://{site_url}/wp-content/plugins/form-runtime-engine/docs/form-schema.json"
  }
}
```

Purpose: a connector can call this before other endpoints to confirm everything is aligned. If `entry_read_enabled` is false, the connector should not attempt entry-read endpoints.

### 9.2 `GET /forms`

List all database-stored forms.

**Query parameters:**

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `page` | integer | 1 | 1-based |
| `per_page` | integer | 20 | max 100 |
| `managed_by` | string | (all) | Filter to `admin` or `connector:cowork`. |

**Response 200:** Collection of form records (see §9.3 for shape).

Pagination metadata appears in the top-level `meta` object.

### 9.3 `GET /forms/{form_id}`

Get a single form.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": "contact",
    "title": "Contact Us",
    "config": "{\"fields\":[{\"key\":\"email\",\"type\":\"email\",...}],...}",
    "custom_css": "",
    "webhook_enabled": false,
    "webhook_url": "",
    "webhook_preset": "custom",
    "managed_by": "admin",
    "connector_version": 3,
    "created": 1712345678,
    "modified": 1712345999,
    "shortcode": "[fre_form id=\"contact\"]"
  }
}
```

The `shortcode` field is a convenience so consumers can embed forms without hand-constructing the shortcode. It is not stored — it is computed on read.

The `config` field is the raw JSON string the form was saved with, to preserve author intent. Consumers that want a parsed object should call `JSON.parse(response.data.config)`.

The `webhook_secret` field is intentionally omitted from responses. Reading secrets via the API would widen their exposure surface; they can only be set, preserved, or regenerated, never read back.

### 9.4 `POST /forms`

Create a new form.

**Request body:**
```json
{
  "id": "lead_capture",
  "title": "Lead Capture",
  "config": "{\"fields\":[...],\"settings\":{...}}",
  "custom_css": "",
  "webhook_enabled": false,
  "webhook_url": "",
  "webhook_preset": "custom"
}
```

- `id` is required and must match `^[a-z0-9\-_]+$`. Conflict with an existing ID returns 409 `form_exists`.
- `config` is required. Must be a JSON string matching `docs/form-schema.json`.
- `title` is optional; if absent, falls back to `config.title`, then to empty string.
- `webhook_*` fields are optional; webhook secret auto-generates when `webhook_enabled: true` and no prior secret exists.
- `managed_by` is automatically set to `connector:cowork` by the API — callers do not control it. This is how the plugin distinguishes connector-originated forms from admin-originated forms.

**Response 201:** Same shape as §9.3.

### 9.5 `PATCH /forms/{form_id}`

Update an existing form. Partial updates allowed; fields not in the request body retain their current values.

**Response 200:** Same shape as §9.3. `connector_version` is always incremented on update.

### 9.6 `DELETE /forms/{form_id}`

Delete a form. Entries associated with the form are preserved — the form record is removed, but the `wp_fre_entries` rows keyed by `form_id` stay in the database. See `COWORK_CONNECTOR_ASSESSMENT.md` §11.2 for the reasoning.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "form_id": "lead_capture",
    "entries_preserved": 47,
    "message": "Form deleted. 47 associated entries have been preserved and remain accessible in the admin Entries view."
  }
}
```

### 9.7 `GET /entries`

List submission entries. Requires gate 2 (entry read toggle) to be enabled.

**Query parameters:**

| Parameter | Type | Default | Notes |
|---|---|---|---|
| `form_id` | string | (all) | Filter entries for a single form. |
| `status` | string | (all) | One of `unread`, `read`, `spam`. |
| `is_spam` | boolean | `false` | Set to `true` to include spam entries. |
| `date_from` | string | — | ISO 8601 date (`YYYY-MM-DD`). |
| `date_to` | string | — | ISO 8601 date. |
| `page` | integer | 1 | 1-based |
| `per_page` | integer | 20 | max 100 |

**Response 200:** Collection of entry records (see §9.8).

### 9.8 `GET /entries/{entry_id}`

Get a single entry. Requires gate 2.

**Response 200:**
```json
{
  "success": true,
  "data": {
    "id": 123,
    "form_id": "contact",
    "status": "unread",
    "is_spam": false,
    "created_at": "2026-04-20 14:32:10",
    "updated_at": "2026-04-20 14:32:10",
    "user_id": null,
    "ip_address": "203.0.113.5",
    "user_agent": "Mozilla/5.0 ...",
    "fields": {
      "name": "Jane Doe",
      "email": "jane@example.com",
      "message": "Hi there"
    },
    "form_version": 3,
    "files": []
  }
}
```

`form_version` is hoisted out of the internal `_fre_form_version` meta so consumers can correlate entries with the form version they were submitted against — the foundation of A/B testing and iterative form optimization.

### 9.9 `POST /forms/{form_id}/submit`

Submit a form programmatically. Primary use case: Claude Cowork verifying a form works end-to-end (validation rules, webhooks, notifications) before handing off to a client.

**Request body:**
```json
{
  "data": {
    "name": "Test User",
    "email": "test@example.com",
    "message": "Sample message"
  },
  "options": {
    "dry_run": false,
    "skip_notifications": false
  }
}
```

- `data` is required. A map of field key → value. Must include all fields marked `required: true` in the form config, unless `dry_run: true`.
- `options.dry_run`: When `true`, runs validation and sanitization and returns what would be stored, but does not create an entry, fire `fre_entry_created`, send email, or dispatch webhooks. Default `false`.
- `options.skip_notifications`: When `true`, creates the entry and fires `fre_entry_created` (so webhooks still dispatch), but skips the built-in email notification send. Default `false`. Ignored when `dry_run: true`.

**Response 200 (normal run):**
```json
{
  "success": true,
  "data": {
    "dry_run": false,
    "entry_id": 124,
    "sanitized": { "name": "Test User", "email": "test@example.com", "message": "Sample message" },
    "email_sent": true,
    "source": "connector"
  }
}
```

**Response 200 (dry run):**
```json
{
  "success": true,
  "data": {
    "dry_run": true,
    "entry_id": 0,
    "sanitized": { "name": "Test User", "email": "test@example.com", "message": "Sample message" },
    "email_sent": false,
    "source": "connector"
  }
}
```

**Response 400 (validation failed):**
```json
{
  "code": "validation_failed",
  "message": "Validation failed.",
  "data": {
    "status": 400,
    "field_errors": {
      "email": ["Email is required."]
    }
  }
}
```

---

## 10. Versioning and breaking change policy

- The API is versioned in the URL path (`/v1/`).
- Within `v1`, fields may be added to responses (consumers must ignore unknown fields) and optional request parameters may be added.
- Field removal, field renaming, semantic changes to existing fields, error-code renaming, and route removal are all breaking changes and require a new version.
- When `v2` ships, `v1` will be maintained for at least two subsequent plugin minor releases before deprecation. Deprecated endpoints emit a `Warning: ...` header.
- The connector may inspect `GET /preflight` `data.connector_api_version` to negotiate. Connectors that do not recognize the version returned should refuse to proceed and surface a clear error to the user.

---

## 11. What is out of scope for v1

The following are explicitly not part of this contract in v1:

- File upload via connector. Forms may be defined with file fields, but submitting files via `POST /forms/{id}/submit` is not supported.
- Entry deletion via connector. Entries can only be deleted through the admin UI, gated behind `fre_manage_forms` and an explicit user action.
- Bulk operations. Every endpoint acts on a single resource; batch creation or batch update is not part of v1. Consumers loop calls.
- Multisite. Tested on single-site WordPress installs only in v1.
- Webhook-secret read. The secret is write-only through the API.

Consumers that need any of these should not build them on top of the v1 surface — they will come through a future version with proper semantics.

---

## 12. Related documents

- **`docs/form-schema.json`** — JSON Schema for form configurations.
- **`docs/COWORK_CONNECTOR_ASSESSMENT.md`** — architecture and design reasoning.
- **`docs/AISB_TOKEN_CONTRACT.md`** — design-token contract with Promptless WP (adjacent, not dependent).
- **`CLAUDE.md`** — AI agent reference for the whole plugin.
