# Changelog

All notable changes to Form Runtime Engine will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed
- **Email notifications now show option labels instead of raw values for select/radio/checkbox-with-options fields.** Previously a "What kind of business do you run?" select with a stored value of `home_services` rendered as `home_services` in the notification email; it now resolves to its option label (e.g. `Home services (HVAC, plumbing, roofing, etc.)`). Fix applied in **three** places: (1) `parse_template()` for `{field:key}` substitutions used in subjects, from-name, and reply-to; (2) `render_default_template()` inline fallback when no override template is present; (3) **`templates/email/notification.php`** — the actual override template that ships with the plugin. The original audit pass missed (3) because the inline fallback uses identical-looking code, leading to a partial first attempt where the email subject resolved labels but the body still showed raw values. Both code paths now route through `FRE_Field_Type_Abstract::resolve_display_value()`. (Reported during FlowMint pressure test of `flowmint-book-a-demo`.)
- **Admin "Form Entries" list summary now shows option labels** for the first two summary fields shown in the Entry column. Previously rendered raw values for the same reason as the email notification bug. Now passes the form config through to `build_entry_summary()` and resolves via the central helper.
- **CSV export of select / radio fields now contains labels.** The abstract base class's `format_csv_value()` returned raw values, and `FRE_Field_Select` / `FRE_Field_Radio` did not override it (only `FRE_Field_Checkbox` did, and only for the single-checkbox Yes/No case — the checkbox-group path also returned raw values). All three field types now produce label-resolved CSV output.

### Added
- `FRE_Field_Type_Abstract::resolve_display_value( $value, $field )` — single source of truth for translating a stored value to its human-readable display string. Plain-text output (callers in HTML contexts wrap in `esc_html`). Handles select / radio / checkbox-with-options (option-label lookup), single checkbox (Yes/No), and falls back to plain stringification for other field types. Orphan values (option deleted/renamed after submission) fall back to the raw value rather than empty so admins still see something.
- `fre_field_display_value` filter — hooked from `resolve_display_value()` so extensions can localize labels, redact sensitive option values from notifications, or inject custom formatting without forking the field classes.
- **Preset-aware option-label resolution in webhook payloads.** The webhook dispatcher now resolves option values to human-readable labels for the `google_sheets` preset by default, while the `zapier`, `make`, and `custom` presets continue to emit raw values by default (machines that filter on stable identifiers prefer that). The smart default reflects the typical destination: Google Sheets is overwhelmingly used as a human-reviewed lead tracker where labels are easier to scan, whereas the other presets typically feed integrations that prefer stable IDs. Storage and the admin entries table always continue to hold raw values — only the outbound webhook payload changes.
- New per-form setting `settings.webhook_resolve_option_labels` (boolean) — explicit override for the smart default. Set `true` to force label resolution regardless of preset, `false` to force raw values regardless of preset. Omit to use the preset-aware default.
- New filter `fre_webhook_resolve_option_labels` — gives sites a programmatic override for the resolution decision. Receives `$resolve_labels`, `$form_config`, `$webhook_preset`, and `$data`. Useful for running different logic per-form or applying a global policy across all forms on a site without editing every form's settings.

### Changed
- `FRE_Webhook_Dispatcher::sanitize_data_for_payload()` signature expanded from `($data)` to `($data, $form_config = array(), $webhook_preset = 'custom')` to support the new resolution logic. Callers within the dispatcher have been updated. Direct external callers (rare — this is a private static method) would need to pass the new args. The default-argument-values keep it backward-compatible at the call-site level.

### Changed
- `FRE_Field_Select::format_value()`, `FRE_Field_Radio::format_value()`, `FRE_Field_Checkbox::format_value()` and `FRE_Field_Checkbox::format_csv_value()` now delegate to `FRE_Field_Type_Abstract::resolve_display_value()`. Output is identical for existing callers; the refactor removes three independent value→label implementations in favor of one. New filter point applies uniformly across all four call sites.

### Architecture notes
- Webhook payload (`FRE_Webhook_Dispatcher::sanitize_data_for_payload()`) intentionally still emits raw values, not labels — webhooks feed downstream integrations (Google Sheets, CRMs, custom endpoints) that typically prefer stable machine identifiers. Sites that want resolved labels in webhook payloads can hook the existing `fre_webhook_payload` filter and run their own resolution against `FRE_Field_Type_Abstract::resolve_display_value()`.

## [1.4.0] - 2026-04-21

### Added
- Connector hardening Phase 2A — the `formengine_preflight` response now returns a comprehensive inline rules digest so fresh Claude/LLM consumer sessions can create and update forms correctly without having to fetch external documentation via shell.
  - New preflight fields: `read_first` (instructs consumers to WebFetch the rulebook), `schema_reference_url` (points at the new `/schema` endpoint), `critical_rules` (11 drift patterns including config-is-string, column-value enumeration, options-required-for-select-and-radio, form_id regex, theme_variant for dark backgrounds, webhook-secret-rotation, managed_by immutability, entry-read gate, test-submit dry_run), `field_hints` (required/optional properties + notes for every one of the 13 supported field types), `universal_field_properties` (grouped by identity/layout/behavior/constraints), and `settings_hints` (theme_variant, webhook, notifications, spam_protection, multistep, success_behavior).
  - New public endpoint `GET /wp-json/fre/v1/connector/schema` serving `docs/FRE_KNOWLEDGE_MAP.md` as raw `text/markdown; charset=utf-8` for consumer sessions to WebFetch as a rulebook before any form operation. No auth required (content is public plugin documentation).
- `FRE_Connector_API::get_connector_rulebook()` — public static method that builds the inline preflight digest. Extracted so the shape can be unit-tested without standing up the full REST stack.
- `docs/FRE_KNOWLEDGE_MAP.md` — comprehensive 10-section human-friendly rulebook covering form anatomy, all 13 field types, universal field properties, column layout, conditional visibility, multi-step forms, settings, critical rules, quick reference, and known gaps. Canonical source served by the `/schema` endpoint.
- `docs/FRE_CONNECTOR_HARDENING_PLAN.md` — execution plan mirroring Promptless Phase 2A, retained for future reference.
- `tests/Unit/ConnectorPreflightTest.php` — regression tests asserting the rulebook shape, critical rules coverage, field hints coverage of all 13 types, schema document existence, and MCP tool description mandatory-read framing.

### Changed
- `formengine_preflight` MCP tool description now mandates the tool be called first in any session and instructs consumers to WebFetch the returned `schema_reference_url` before further tool calls.
- `formengine_create_form` and `formengine_update_form` MCP tool descriptions lead with "BEFORE your first create/update, call `formengine_preflight` and WebFetch the returned `schema_reference_url`," and explicitly restate that `config` must be a JSON STRING (not an object) to surface the top drift pattern at point-of-use.
- **Column layout now uses CSS Grid + subgrid instead of flexbox.** Previously, the default `align-items: stretch` on `.fre-row` stretched each column to the tallest cell's height but had no mechanism to align internal children (label / input / description / error) across siblings — so a 2-line label in one column would push its input down while a 1-line label in the adjacent column kept its input up, breaking horizontal alignment. The new layout makes `.fre-row` a 12-column grid; each `.fre-field` inherits 4 rows via `grid-template-rows: subgrid`, and label/description/error are pinned to specific rows. Inputs auto-place into row 2 and now align across all columns regardless of label length or which fields have descriptions. No JS, no runtime measurement, no `:has()` dependency — CSS-only and correct by construction. Requires subgrid support (baseline in Firefox 71+, Safari 16+, Chrome/Edge 117+).
- **New form setting `settings.appearance.surface`** — opt-in form-level card wrapper. Accepts `"none"` (default, unchanged behavior) or `"card"` (wraps the whole form in a token-aware card: background, border, radius, padding). Works uniformly for every form type because the class sits on the `<form>` root, outside the progress indicator and step nav of multi-step forms. When set to `"card"`, inner `section` field cards are automatically flattened to avoid nested-card artifacts — the section field type still works structurally for grouping fields, it just drops its visual treatment. Renderer adds `.fre-form--surface-card`; CSS in `frontend.css` paints it with existing `--fre-surface-color` / `--fre-border-color` / `--fre-border-radius-lg` / `--fre-spacing` tokens so light/dark adaptation is automatic.
- **Preflight rulebook expansion.** Post-audit sweep of the FRE codebase surfaced several capabilities that existed in code but weren't discoverable via the connector. Added four new critical rules (`form_surface_options` documenting the two card-producing routes and the vocabulary users reach for; `honeypot_field_name_dynamic` warning that the honeypot field name is HMAC-suffixed per form; `min_submission_time_enforcement` calling out the 3-second silent-reject window; `aisb_token_inheritance` explaining how FRE auto-adopts AISB brand tokens when Promptless WP is active). Expanded `field_hints` to include the text-field `pattern` property, tel-field auto-pattern behavior, section-field visual-card semantics, and message-field label-OR-content requirement. Expanded `settings_hints` with `appearance`, richer `notifications` / `spam_protection` / `multistep` / `presentation_flags` coverage. Fixed a latent bug where `settings_hints` was built by `get_connector_rulebook()` but never actually returned in the preflight response.
- **Knowledge map companion updates.** `FRE_KNOWLEDGE_MAP.md` gained §7.2 "Form surface / visual treatment" (vocabulary + when-to-use-which-route decision matrix + conflict handling + token inheritance), four new critical-rule subsections (8.10–8.13), and a worked card-wrapped form example in §9 Quick reference. Spam-protection subsection updated to explain honeypot dynamic naming and the 3-second silent-reject window.
- **Regression tests.** `ConnectorPreflightTest` now asserts the four new critical rules are present, the surface rule names both routes plus the "card / surface / wrapper" vocabulary, `settings_hints.appearance` exists, text-field hints include `pattern`, and section-field notes explain the visual card treatment.

### Fixed
- `handle_preflight` now returns `settings_hints` alongside the other rulebook sections. Previously the hints were assembled by `get_connector_rulebook()` but omitted from the preflight response, effectively gating them behind the `/schema` markdown fetch.
- **Column-stack threshold lowered from 479px to 399px** so 2-column layouts no longer collapse prematurely when the form is inside a card (the card's internal padding reduces the inline-size the container query measures by ~72px, which at the old threshold caused sub-pixel-rounded boundary fires on ~550px-wide forms). The new threshold reflects the true "too narrow for 2-col" point (columns < 180px). The legacy `@media` fallback used by browsers without container-query support was adjusted from 600px to 500px to match.

### Phase 2A P1 polish (additive)
- **Actionable validation errors.** Save-path `WP_Error` responses (`empty_id`, `invalid_id`, `empty_config`, `invalid_json`, `schema_error`, `delete_failed`) now carry a `hint` field explaining how to correct the shape, alongside the existing `status` and `field` data. Mirrors Promptless WP's `invalid_pricing_features_shape` error pattern. A Cowork session reading the 400 can act on the hint without fetching external documentation.
- **Visual-settings reminder in create/update tool descriptions.** `formengine_create_form` now calls out `settings.theme_variant` (light/dark for parent-section match) and `settings.appearance.surface` (flat vs. card wrapper) as key visual decisions at point-of-call, so consumer sessions see the relevant knobs without having to WebFetch the full rulebook for every invocation. `formengine_update_form` reminds consumers to preserve both settings when replacing `config` (since config replacement is total).
- **Workflow cross-reference.** `formengine_create_form` and `formengine_update_form` descriptions now cite `docs/WORKFLOW_PROMPTLESS_INTEGRATION.md` so consumers working on sites with both plugins find the end-to-end cross-plugin flow.

## [1.3.0] - 2026-04-20

### Added
- Claude Cowork MCP connector — Claude Desktop can now create, update, delete, and read forms on this site, plus read submission entries when explicitly allowed. Full agency-pipeline integration with the Promptless WP plugin.
- REST API namespace `/wp-json/fre/v1/connector/` exposing 9 endpoints: preflight, list/get/create/update/delete forms, list/get entries, and programmatic test-submit.
- Claude Connection admin page under Form Entries with connector enable toggle, entry-read toggle, and App Password generate/revoke flow. Auto-generates a one-line bash setup command that installs the MCP server into Claude Desktop.
- MCP server script (`form-engine-connector.js`) — Node.js stdio bridge between Claude Desktop and this plugin's REST API. Forked from the Promptless connector with ModSecurity-friendly User-Agent, explicit Content-Length, and Content-Length/newline message-framing auto-detection preserved verbatim.
- Custom capability `fre_manage_forms` granted to the administrator role on plugin activation/upgrade. Replaces hardcoded `manage_options` at 22 form and entry management call sites so access can be delegated to non-admin roles in the future without a cross-cutting refactor.
- `FRE_Forms_Repository` — pure CRUD data-access layer extracted from `FRE_Forms_Manager`. The admin UI and REST connector both route through it, so they can never diverge.
- `FRE_Upgrader` — version-check pattern that runs on every `plugins_loaded`. Handles fresh installs, upgrades, and downgrades; stamps `fre_plugin_version` option so the connector's preflight can surface it.
- `FRE_Capabilities` — centralised capability constant and grant/revoke helpers used across the plugin lifecycle.
- `FRE_Connector_Settings` — storage for the two-gate security design (connector-enabled toggle + entry-read toggle, both default off).
- `FRE_Connector_Auth` — REST permission callback stack with per-user per-route rate limiting via WP transients.
- `FRE_Connector_API` — REST controller registering all 9 routes under `rest_api_init`.
- `FRE_Connector_Admin` — Claude Connection admin page, AJAX handlers for toggles and Application Password management, MCP script download endpoint.
- `FRE_Connector_Log` — bounded ring buffer of recent connector REST calls (method, route, user_id, status, duration_ms). Captured automatically via `rest_pre_dispatch`/`rest_post_dispatch` filters scoped to the connector namespace. Opt-out via `fre_connector_log_enabled` filter.
- `FRE_Submission_Handler::process_submission()` — programmatic submission path used by the connector's test-submit endpoint. Supports `dry_run` (validate only, no DB write, no side effects) and `skip_notifications` (write entry but suppress email) options.
- Form records now carry a `managed_by` field (`admin` or `connector:cowork`) so hand-authored forms are distinguishable from connector-created ones.
- Form records now carry a `connector_version` integer that bumps monotonically on every save. Every submission is stamped with `_fre_form_version` entry meta matching the form's version at the time, enabling A/B analysis across form iterations.
- Enriched preflight response includes diagnostics block: stored plugin version, database table health check, and last 5 connector call outcomes. Designed for remote troubleshooting from a Cowork session.
- New documentation:
  - `docs/CONNECTOR_SPEC.md` — public REST API contract (v1).
  - `docs/MCP_CONNECTOR_SETUP.md` — operator setup guide and host-specific troubleshooting (Authorization header passthrough, ModSecurity, Node path detection).
  - `docs/CONNECTOR_TESTING_REPORT.md` — real artifact of the production pressure test on getflowmint.com.
  - `docs/WORKFLOW_PROMPTLESS_INTEGRATION.md` — end-to-end agency pipeline that emerges when both connectors are installed.
  - `docs/COWORK_CONNECTOR_ASSESSMENT.md` — architectural rationale document.
  - `docs/form-schema.json` — canonical JSON Schema for form configurations. Referenced by the MCP tool definitions so Cowork has a concrete target when generating form JSON.
  - `docs/AISB_TOKEN_CONTRACT.md` — design-token contract documenting which `--aisb-*` CSS custom properties this plugin consumes from the Promptless WP plugin.

### Changed
- Form CRUD now routes through `FRE_Forms_Repository`. The `FRE_Forms_Manager` static methods (`save_form`, `get_form`, `get_forms`, `delete_form`, `register_db_forms`) are preserved as thin delegators so every existing caller — including the `fre_save_db_form` wrapper functions, the renderer, and the webhook dispatcher — continues to work unchanged.
- `FRE_Entry::create()` now stamps the submitting form's `connector_version` onto the entry as `_fre_form_version` meta, inside the same DB transaction as the entry insert.
- `uninstall.php` now cleans up the new connector options (`fre_plugin_version`, `fre_client_forms`, connector toggles, rate-limit transients, call log) and revokes the `fre_manage_forms` capability from every role.

### Fixed
- `uninstall.php` previously leaked the `fre_client_forms` option on uninstall — a pre-existing bug discovered during the refactor and addressed as part of the cleanup sweep.
- Delete-form response message now uses `_n()` for proper pluralization ("1 associated entry has been preserved" vs "N associated entries have been preserved").

## [1.2.5] - 2026-04-16

### Fixed
- "Update available" notice persists after successful plugin update

## [1.2.4] - 2026-04-16

### Fixed
- Edit button on Client Phone Numbers table was non-functional
- Modal not scrollable on smaller viewports

## [1.2.3] - 2026-04-16

### Fixed
- Call-status callback fails signature validation due to query params in action URL, causing caller to hear "an error occurred" when the answering party hangs up first
- Removed query params from Dial action URL — caller and call SID are already in Twilio's POST body (From, CallSid fields), eliminating URL encoding and signature mismatch issues

## [1.2.2] - 2026-04-16

### Fixed
- Twilio webhook returns JSON-encoded TwiML instead of raw XML, causing Twilio "Document parse failure" (error 12100) on all incoming calls
- Added `rest_pre_serve_request` filter to bypass WordPress REST API JSON encoding for TwiML responses
- Error responses to Twilio now return valid TwiML (`<Say>` + `<Hangup/>`) instead of JSON, preventing secondary parse failures
- SSL detection behind reverse proxies (Bluehost, Cloudflare) now checks `X-Forwarded-Proto` and `X-Forwarded-SSL` headers for accurate signature validation URL construction

## [1.2.1] - 2026-04-16

### Fixed
- Database migration fails on fresh installs: migration_1_0_0 incorrectly validated all tables including ones created by later migrations
- Twilio clients table uses ON UPDATE CURRENT_TIMESTAMP which is incompatible with dbDelta on some MySQL versions

## [1.2.0] - 2026-04-16

### Added
- Twilio missed-call text-back integration module (6 new classes)
- Automatic SMS reply when a business owner misses a call
- Owner notification via SMS and email for every missed call
- Multi-client routing: map multiple Twilio numbers to different businesses
- REST API endpoints for Twilio webhooks (incoming call, call status, incoming SMS, SMS status)
- Twilio signature validation (HMAC-SHA1) on all incoming webhooks
- Encrypted credential storage (AES-256-CBC) for Twilio Account SID and Auth Token
- Rate limiting for outbound SMS (hourly per-client and daily global caps)
- Admin UI: Twilio Text-Back settings page under Form Entries menu
- Admin UI: Client management with add, edit, toggle, and delete operations
- Admin UI: Test Connection button for Twilio credential validation
- Virtual FRE form registration per Twilio client for unified lead pipeline
- SMS conversation logging in dedicated fre_twilio_messages table
- Inbound SMS forwarding from leads to business owners
- SMS delivery status tracking via Twilio status callbacks
- Missed-call leads appear in the same entries list and Google Sheets as form submissions
- Source type metadata (_source_type: missed_call / sms_inbound) on Twilio-originated entries

### Changed
- Autoloader updated with Twilio class mappings
- Main plugin initialization sequence now includes Twilio module bootstrap
- Plugin activation now runs Twilio database migrations alongside core migrations

## [1.1.0] - 2026-04-11

### Added
- HMAC-SHA256 webhook request signing with auto-generated per-form secrets
- Webhook destination presets (Google Sheets, Zapier, Make, Custom) with contextual setup help
- Test Connection button with rich response display (HTTP status, latency, response body)
- Preview Payload button showing sample JSON based on form field definitions
- Webhook secret management: auto-generate on first enable, regenerate, copy to clipboard
- Webhook delivery logging with database table, retry tracking, and status monitoring
- Google Sheets integration via Google Apps Script (free Zapier alternative)
- Google Apps Script template (`docs/google/apps-script-template.gs`) with signature verification support
- Google Sheets setup guide (`docs/google/google-sheets-setup.md`)
- Webhook secret field auto-populates in admin UI after server-side generation

### Changed
- Webhook dispatcher refactored to support HMAC signing and rich test responses
- Forms Manager admin UI expanded with webhook configuration panel
- Admin JS updated with handlers for preset switching, test, preview, regenerate, and copy actions
- Split CLAUDE.md into root (core reference) + `docs/CLAUDE.md` (examples) + `includes/CLAUDE.md` (security) for performance

## [1.0.1] - 2026-04-05

### Changed
- Comprehensive README.md rewrite with complete feature documentation
- Updated plugin description to reflect admin UI capabilities
- Added documentation for all 13 field types (including section, date, address)
- Added documentation for layout features (columns, sections, conditional logic)
- Added documentation for multi-step forms with progress styles
- Added documentation for admin features (Forms Manager, entries, CSV export)
- Expanded API functions documentation (4 → 8 functions)
- Added design system integration documentation

## [1.0.0] - 2026-04-05

### Added
- Initial stable release
- Form registration via PHP arrays and JSON configuration
- Admin UI for creating and managing forms (Forms Manager)
- Field types: text, email, tel, textarea, select, radio, checkbox, file, hidden, message, section, date, address
- Multi-step forms with progress indicators (steps, bar, dots styles)
- Conditional field logic (show/hide based on field values)
- Column layouts (1/2, 1/3, 2/3, 1/4, 3/4)
- Field sections/groups with headings
- File uploads with security validation and MIME type checking
- Email notifications with template variables
- Webhook integration for Zapier, Make, and custom endpoints
- Spam protection: honeypot fields, timing check, rate limiting
- Entry storage and admin management
- CSV export for form entries
- Google Places API integration for address fields
- Design system integration with AI Section Builder Modern
- Theme variants: light, dark, auto (inherits from AISB section)
- Neo-Brutalist mode support
- GitHub-based automatic updates

### Security
- SSRF protection for webhook URLs
- CSS validation for custom styles
- JSON schema validation for form configurations
- PHP execution disabled in upload directory
- Secure file uploads with extension and MIME validation
