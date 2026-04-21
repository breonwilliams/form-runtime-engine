# FRE Connector — Hardening Plan (mirrors Promptless Phase 2A pattern)

**Date:** 2026-04-21
**Based on:** `FRE_KNOWLEDGE_MAP.md` (just authored) + Promptless Phase 2A lessons
**Goal:** Close the same session-context-asymmetry gaps in FRE that Promptless already closed. Make a fresh Claude session using the FRE connector productive without having to fetch external docs via terminal.

## Ground rules

- **Do not change form-schema.json conventions.** The JSON schema is the authoritative machine contract. Keep it intact.
- **Do not modify `includes/Security/class-fre-json-schema-validator.php` logic.** Schema enforcement works; leave the validator's rules alone.
- Do not modify anything under `includes/Twilio/`. That's a separate subsystem.
- Changes ride together in one coherent patch. One rebuild, one deploy.

## Current state vs. target state

| Capability | Current state | Target (matches Promptless 2A) |
|---|---|---|
| Preflight returns `schema_document_url` | ✓ | ✓ (keep) |
| Preflight returns inline `critical_rules` | ✗ | **ADD** |
| Preflight returns `read_first` instruction | ✗ | **ADD** |
| Preflight returns `field_hints` | ✗ | **ADD** |
| Preflight returns `schema_reference_url` (human-friendly) | ✗ | **ADD** |
| Tool descriptions mandate schema-doc read | ✗ (mentions doc, not mandatory) | **STRENGTHEN** |
| Markdown knowledge map exists | ✓ (just created) | ✓ (keep) |
| `/schema` endpoint serves human-friendly doc | ✗ | **ADD** (optional — could just return URL from preflight) |

## Prioritization

**P0 — Consumer-blocking gaps.** Ship in this hardening pass.
**P1 — High-value quality-of-life.** Ship if scope allows.
**P2 — Deferred to a later cycle.**

---

## P0 changes

### P0.1 — Expand `/preflight` response with inline critical_rules + field_hints + read_first

**File:** `includes/Connector/class-fre-connector-api.php`, `handle_preflight()` method

**Change:** Extend the response object. In addition to the current diagnostic payload, include:

- `read_first` — short instruction telling clients to always WebFetch `schema_reference_url` for the full rulebook
- `schema_reference_url` — URL to the markdown knowledge map (see P0.2)
- `critical_rules` — object containing the handful of non-negotiable rules:
  - `config_is_string` — reminder that `config` parameter must be a JSON string, not an object
  - `column_values` — reminder that `column` accepts only `1/2`, `1/3`, `2/3`, `1/4`, `3/4`
  - `options_required` — reminder that select/radio fields require non-empty `options` array
  - `form_id_regex` — reminder of the URL-safe format
  - `theme_variant_for_dark_backgrounds` — remind to set `settings.theme_variant: "dark"` when embedding in dark sections
  - `webhook_secret_rotation` — secrets are admin-only
  - `managed_by_immutable` — after create, can't change origin tag
- `field_hints` — object mapping each of the 13 field types to its required properties:
  - `select: ["options (min 1)"]`
  - `radio: ["options (min 1)"]`
  - `file: ["allowed_types (optional)", "max_size (optional, bytes)"]`
  - etc.
- `universal_field_properties` — grouped reference: `identity` (key, type, label, placeholder, required), `layout` (column, section, step), `behavior` (default, description, conditions), `constraints` (maxlength, minlength, min, max)

**Shape to mirror Promptless Phase 2A.** The goal is that a consumer session calling `formengine_preflight` sees a complete rulebook digest inline, and can fetch the full markdown for depth when needed.

**Test:** Add a new test file or extend existing (`tests/Unit/ConnectorAuthTest.php` already covers auth — likely a new `ConnectorPreflightTest.php`). Assert the response contains all the new keys and that `field_hints` includes all 13 field types.

### P0.2 — Add `/schema` endpoint serving FRE_KNOWLEDGE_MAP.md

**File:** `includes/Connector/class-fre-connector-api.php`

**Change:** Register a new route `GET /fre/v1/connector/schema` that serves `docs/FRE_KNOWLEDGE_MAP.md` as `text/markdown; charset=utf-8` with proper Content-Type and nosniff headers. Permission callback should be `__return_true` (public — rules aren't sensitive).

Pattern to copy from Promptless: `echo file_get_contents($path); exit;` to bypass WP REST JSON serialization.

Update the preflight response's new `schema_reference_url` to point at this endpoint.

**Test:** Integration test or at minimum a unit test that verifies the file exists and starts with the expected canonical title line.

### P0.3 — Rewrite MCP tool descriptions with mandatory schema-read framing

**File:** `includes/Connector/assets/form-engine-connector.js`

**Change:** Rewrite the `formengine_preflight` description to lead with:
> "Verify the Form Runtime Engine connector is reachable and report its state. MUST be called first in any session that will use this connector. Returns a digest of critical rules inline AND a `schema_reference_url` pointing at the comprehensive markdown rulebook. ALWAYS WebFetch that URL before creating or updating forms — it covers the 13 field types, column layout, conditional visibility, multistep, settings, and the drift patterns that cause silent failures."

Rewrite `formengine_create_form` description to add:
> "BEFORE your first create, call `formengine_preflight` and WebFetch the returned `schema_reference_url`. That document covers column layouts, field types, settings, and common drift patterns that the raw JSON schema doesn't explain."

Rewrite `formengine_update_form` with similar lead.

Keep the existing body of each description (field definitions, etc.) intact — just prepend the mandatory-read framing.

**Test:** Extend `ConnectorSettingsTest` or a new test to grep the JS file for required phrases like "MUST be called first" and "WebFetch".

### P0.4 — Regression tests

**File:** `tests/Unit/` (new test file or extensions)

Tests covering:
- Preflight response structure — asserts all new keys present
- Preflight `critical_rules.config_is_string` message present
- Preflight `field_hints` has entries for all 13 field types
- `/schema` endpoint returns the knowledge map (content check)
- MCP JS tool descriptions contain mandatory-read phrases

---

## P1 changes (if scope allows in this cycle)

### P1.1 — Actionable error messages on schema validation failures

When a form creation fails JSON Schema validation, the current error includes the violating field path. Enhancing it with a suggested correct shape (like Promptless's pricing features error does) would help consumers recover without trial-and-error.

### P1.2 — Add `theme_variant` hint to create/update tool descriptions

Remind consumers to set `settings.theme_variant: "dark"` when the form will be embedded in a dark section. This is a common oversight.

### P1.3 — Add WORKFLOW_PROMPTLESS_INTEGRATION.md cross-reference in tool descriptions

The existing `WORKFLOW_PROMPTLESS_INTEGRATION.md` documents how FRE + Promptless pair together. Calling it out in tool descriptions helps consumers connecting both plugins.

---

## P2 changes (deferred)

- **P2.1 — JSON Schema → human docs generator.** Generate `FRE_KNOWLEDGE_MAP.md` automatically from `form-schema.json` so the two stay in sync. Non-trivial; defer until the next schema change forces the question.
- **P2.2 — Form template library exposed via connector.** Pre-built form templates (contact, demo, quote, newsletter) addressable by name. Claude could `create_form_from_template(name: "demo-booking")`. Deferred because it's a new feature, not a hardening.
- **P2.3 — `dry_run` as default for `test_submit`.** Currently requires explicit opt-in. Consider flipping the default.

---

## Execution sequence

1. All P0 edits on a feature branch (`connector-phase-2a-hardening` or similar)
2. `phpunit` suite locally — all existing tests still pass, new ones green
3. Build plugin ZIP using existing release script (FRE has its own release script per `release/` directory)
4. User uploads ZIP to getflowmint.com → install → replace current plugin
5. User re-runs the FRE setup command on Mac to refresh `form-engine-connector.js` in `~/promptless-mcp/` (or wherever FRE's equivalent lives)
6. Restart Claude Desktop
7. Fresh Cowork session — call `formengine_preflight`; verify expanded response; WebFetch `schema_reference_url`; confirm it returns raw markdown
8. Attempt a form-create with a deliberately-bad config (invalid `column` value) to verify schema validation still catches it with the existing enforcement
9. Commit + push

## Success criteria

- `formengine_preflight` returns `read_first`, `schema_reference_url`, `critical_rules`, `field_hints`, `universal_field_properties` inline
- `/fre/v1/connector/schema` endpoint serves the knowledge map as raw markdown
- Tool descriptions for preflight + create + update all include the mandatory-WebFetch instruction
- All existing phpunit tests pass; new ones green
- OpenAI content-mapping pipeline (if FRE has any) is unaffected — this is purely additive

---

**END.**
