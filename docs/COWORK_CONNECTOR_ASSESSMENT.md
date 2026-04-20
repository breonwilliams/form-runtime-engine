# Cowork Connector — Architectural Assessment

**Status:** Planning / pre-implementation
**Reference implementation:** Promptless WP plugin (`ai-section-builder-modern/`)
**Date:** 2026-04-20
**Owner:** Breon Williams

---

## 1. Why This Document Exists

The Form Runtime Engine will grow a Claude Cowork connector analogous to the one Promptless WP already ships. The purpose is to let Claude Cowork — which already has project context, client research, competitor analysis, and now direct WordPress site access via Promptless — also create, read, update, delete, and observe forms on the same site. This closes the loop on a full lead-flow pipeline: Cowork researches the client, Promptless builds the site's pages and copy, and the form engine connector generates the conversion surfaces (lead forms, quote requests, booking flows) and lets Cowork iterate on them using real submission data.

This document is the architectural assessment that must be agreed on before any code is written. It is based on a deep read of both codebases.

---

## 2. Target Capabilities

Cowork, via the connector, must be able to:

1. **Create, update, and delete forms** — the primary "build" capability that makes this a self-serve pipeline.
2. **List and read existing forms** — so Cowork can introspect what is already on the site before deciding to add, modify, or replace.
3. **Read submission entries** — to close the optimization loop (A/B variant analysis, lead quality review, conversion diagnostics driven by analytics data Cowork also has access to).
4. **Test-submit a form** — dry-run validation so Cowork can verify a form works end-to-end (validation rules, webhooks, notifications) before handing off to the client.

Forms bind to Promptless-built pages exclusively via shortcode (`[fre_form id="..."]`). No changes to Promptless are required. This keeps the two plugins loosely coupled.

---

## 3. Reference Implementation — What Promptless Established

The Promptless connector is our reference because it is already shipping and has been pressure-tested in production (17 and 43 section deploys, zero failures per `CONNECTOR_TESTING_REPORT.md`). The form engine connector should mirror its architectural choices unless there is a specific reason to diverge.

The summarized pattern is:

**Auth.** HTTP Basic Auth using WordPress Application Passwords. Credentials generated inside the plugin's admin UI, stripped of whitespace, passed to an MCP server process via environment variables. Every REST endpoint gates behind `current_user_can('edit_pages')` plus a premium license check (`LicenseManager::can_use_premium()`). No custom token scheme; no OAuth. App Passwords are a standard WordPress 5.6+ feature and already hardened by core.

**Transport.** REST API only, namespaced under `/wp-json/promptless/v1/site/*`. Nine routes, versioned in the URL. `WP_Error` with HTTP status codes for errors. No admin-ajax for connector traffic — that's reserved for the admin UI.

**MCP server.** Not embedded server-side. The plugin ships a Node.js script (`wordpress-connector.js`) that is downloaded to the user's local machine during setup and registered in Claude Desktop's `claude_desktop_config.json`. The script bridges Claude Desktop's stdio JSON-RPC protocol to the plugin's REST API. This design circumvents Cowork's sandbox outbound-HTTP restrictions. Seven MCP tools map 1:1 to seven REST endpoints.

**Admin UI.** A single "Claude Connection" tab that generates the App Password (revoking any previous one), displays a one-line bash setup command, and provides a revoke action. The bash command handles nvm detection, Node path resolution, and writes the Claude Desktop config inline via Node's own runtime.

**Rate limiting.** Per-user, per-endpoint, via WordPress transients keyed `aisb_connector_rate_{route}_{user_id}`. Scaffold and reset get the strictest limits; status and preflight the loosest.

**Field normalization.** API uses intuitive field names; internal renderer expects specific internal names. A normalization layer (`ContentDeployer::normalize_field_names()`) maps between them so the public API can evolve independently of internal rendering.

**Documentation.** Three companion files — `CONNECTOR_SPEC.md` (the public API spec), `MCP_CONNECTOR_SETUP.md` (setup, bugs, workarounds), `CONNECTOR_TESTING_REPORT.md` (test evidence). These are versioned with the plugin and serve as the contract.

**Hard-earned gotchas documented there:**

| Problem | Fix |
|---|---|
| Claude Desktop uses different MCP protocol version than server expected | Echo client's `protocolVersion` in the `initialize` response |
| Claude Desktop uses Content-Length message framing, not newline-delimited | Auto-detect framing by peeking first 20 bytes |
| Claude Desktop picks the wrong `node` from PATH when nvm is installed | Use full path `/Users/.../v22.18.0/bin/node` in config |
| ModSecurity WAF on shared hosts blocks Node.js requests | Set `User-Agent: WordPress/...`, `Connection: close`, explicit `Content-Length` |
| `const NAMESPACE` is a PHP reserved word | Rename to `API_NAMESPACE` |
| Shell: bash comment lines interpreted as zsh commands | Strip all comment lines from setup command |
| Shell: `&&` chain short-circuits when `[ -z ]` returns 1 | Use `;` between steps that must always run |
| Shell: `!` triggers zsh history expansion inside inline Node script | Rewrite to avoid `!`; wrap in single quotes |

The form engine connector inherits these fixes for free by copying the MCP server and setup-command patterns. We must not re-derive them.

---

## 4. Current State of the Form Engine

The form engine has no existing REST surface for forms or entries. Twilio integration registers REST routes for its own inbound webhooks, but nothing is exposed for external management. Everything form-related today lives in AJAX handlers gated by nonce + `manage_options`.

Substantively, though, most of the *building blocks* a connector needs already exist and are well-isolated:

**Reusable as-is:**

| Component | File | Role in connector |
|---|---|---|
| `FRE_Forms_Manager::save_form()` / `get_form()` / `get_forms()` / `delete_form()` | `includes/Admin/class-fre-forms-manager.php` | Static CRUD methods; the AJAX handlers are thin wrappers around these |
| `FRE_Entry_Query` (fluent builder) | `includes/Database/class-fre-entry-query.php` | Production-ready filtering, pagination, search — serves the "read entries" capability directly |
| `FRE_Entry::create()` | `includes/Database/class-fre-entry.php` | Transactional entry creation; backs "test-submit" |
| `FRE_Validator` | `includes/Core/class-fre-validator.php` | Field validation, independent of submission handler |
| `FRE_Sanitizer` | `includes/Core/class-fre-sanitizer.php` | Per-field-type sanitization |
| `FRE_JSON_Schema_Validator` | `includes/Security/class-fre-json-schema-validator.php` | Validates form config structure — used by save path |
| `FRE_CSS_Validator` | `includes/Security/class-fre-css-validator.php` | Blocks unsafe CSS |
| `FRE_Webhook_Validator` | `includes/Security/class-fre-webhook-validator.php` | URL validation + SSRF protection |
| `fre_entry_created` action | hook | Natural place to wire connector-originated entries into the existing webhook/email pipeline |

**Requires wrapping (not replacement):**

- `FRE_Submission_Handler::handle_submission()` reads `$_POST` directly and calls `wp_send_json_*`. For test-submit we need a thin wrapper that takes a JSON body, reuses validation/sanitization/entry creation, and returns a REST response. Do not adapt the original handler in place; keep the AJAX path intact.

**Gaps that need new code:**

- No REST surface at all for forms or entries (Twilio aside).
- No auth layer for REST — no token/capability scheme beyond what WordPress provides.
- No admin page for connector configuration.
- No MCP server artifact (the Node.js script).
- No form-ownership concept — there's no way to tell a connector-managed form from an admin-managed one. For a pipeline where Cowork creates forms and humans may also edit them, this matters.
- No dry-run flag on the submission path — notifications and webhooks fire regardless.
- No API versioning discipline for form JSON (the top-level `version` key in form config is stored but never read).
- Capability gates are hardcoded to `manage_options` everywhere; connector permission callbacks need their own layer.

---

## 5. Capability → Surface Mapping

For each target capability, here is the mapping from Cowork MCP tool → REST endpoint → internal reuse. This is the design target.

| Capability | MCP tool (proposed) | REST route (proposed) | Primary internal reuse | New code needed |
|---|---|---|---|---|
| Preflight / health check | `formengine_preflight` | `GET /wp-json/fre/v1/connector/preflight` | — | Small handler returning plugin version, DB table status, license state, capability check result |
| List forms | `formengine_list_forms` | `GET /wp-json/fre/v1/connector/forms` | `FRE_Forms_Manager::get_forms()` | REST permission callback, pagination wrapper, ownership filter |
| Read single form | `formengine_get_form` | `GET /wp-json/fre/v1/connector/forms/{form_id}` | `FRE_Forms_Manager::get_form()` | REST permission callback |
| Create form | `formengine_create_form` | `POST /wp-json/fre/v1/connector/forms` | `FRE_Forms_Manager::save_form()` | REST permission callback, ownership tagging, 409 on existing ID |
| Update form | `formengine_update_form` | `PATCH /wp-json/fre/v1/connector/forms/{form_id}` | `FRE_Forms_Manager::save_form()` | REST permission callback, ownership enforcement/warning |
| Delete form | `formengine_delete_form` | `DELETE /wp-json/fre/v1/connector/forms/{form_id}` | `FRE_Forms_Manager::delete_form()` | REST permission callback, orphan-entry policy decision |
| List entries | `formengine_list_entries` | `GET /wp-json/fre/v1/connector/entries` | `FRE_Entry_Query` | REST permission callback, response schema, pagination metadata |
| Read single entry | `formengine_get_entry` | `GET /wp-json/fre/v1/connector/entries/{entry_id}` | `FRE_Entry::get()` | REST permission callback |
| Test-submit a form | `formengine_test_submit` | `POST /wp-json/fre/v1/connector/forms/{form_id}/submit` | `FRE_Validator`, `FRE_Sanitizer`, `FRE_Entry::create()`, `fre_entry_created` action | Thin REST-native submission handler with `dry_run` flag |
| Regenerate webhook secret | (admin only, not exposed to MCP) | — | — | — |

Ten MCP tools; ten-ish routes (counting the collection GET + single-item GET as separate routes). The Promptless connector runs seven tools, so this is in the same order of magnitude.

Two architectural notes on this mapping:

**Test-submit is where we diverge deliberately.** Promptless has no analog; deploying content is its natural write path. Our test-submit must support a `dry_run: true` mode that validates and returns the would-be entry payload *without* writing to the entries table, firing webhooks, or sending notifications. And a `dry_run: false` mode that writes the entry but skips notifications when the submission is tagged as originating from the connector. This is where Cowork can verify a webhook integration before handing off.

**Entry read is privacy-sensitive.** Entries contain user PII — names, emails, phone numbers, free-text message bodies, sometimes uploaded files. Exposing read access to Cowork means Cowork (and therefore Anthropic's infrastructure in the audit trail) can see every lead the site collects. Recommend gating this behind a per-site opt-in toggle in the connector settings, default off. The list-forms / test-submit / CRUD capabilities work without entry read being enabled.

---

## 6. Authentication Model (Matching Promptless Exactly)

Mirror the Promptless pattern end to end:

1. Site owner visits "Form Engine → Claude Connection" in admin.
2. Clicks "Generate Connection". Plugin revokes any existing Form Engine App Password via `WP_Application_Passwords::delete_application_password()`, then creates a fresh one via `WP_Application_Passwords::create_new_application_password()`, shows the password to the browser *once*, and writes a `_fre_connector_configured` user meta flag.
3. UI renders a one-line bash command that downloads our `form-engine-connector.js` MCP script and writes a `form-engine-wordpress` entry into `claude_desktop_config.json`.
4. User pastes it into Terminal. Claude Desktop picks it up on next restart.
5. Every REST call authenticates via HTTP Basic Auth using that App Password.
6. Every REST permission callback checks three things: `is_user_logged_in()`, `current_user_can('fre_manage_forms')` (see §7.7 below — we are introducing this custom capability and using it uniformly across admin UI and connector), and the connector enable toggle (§7.9). There is **no** premium/licensing gate — the form engine is not a commercial plugin and has no tiered model. Auth is purely "authenticated user with the right capability on a site that has the connector enabled."

Both MCP servers end up as two entries in the user's `claude_desktop_config.json`:

```json
"mcpServers": {
  "promptless-wordpress":   { "command": "...", "args": ["...", ".../promptless-mcp/wordpress-connector.js"], "env": {...} },
  "form-engine-wordpress":  { "command": "...", "args": ["...", ".../form-engine-mcp/form-engine-connector.js"], "env": {...} }
}
```

This is a small UX cost — two setup commands instead of one — but it matches the user's stated architectural preference ("separate MCP server") and means the form engine can be connected on sites that don't run Promptless at all. A future consolidation pass could offer a "connect both" shortcut that runs two setup commands, but that's cosmetic.

**Reuse the `User-Agent`, `Connection: close`, explicit `Content-Length` pattern** from Promptless's `wordpress-connector.js` verbatim. The ModSecurity workarounds were expensive to discover; we inherit them for free.

---

## 7. Architectural Decisions to Make Now

These are the choices that shape the build. Each has a default recommendation; call out divergences before we start.

### 7.1 Extract CRUD into a repository class *before* adding the REST layer

Currently `FRE_Forms_Manager` is the CRUD surface, the AJAX handlers, and the admin page renderer all in one file. Bolting REST handlers onto this file would deepen the coupling and produce three parallel sanitize-and-save code paths. The sustainable path is to extract a `FRE_Forms_Repository` class with pure CRUD, refactor the admin AJAX handlers to call it, and then have the new REST handlers call the same repository.

This is extra upfront work, but it is the difference between shipping a connector we can maintain and a connector where admin and connector code drift apart within three releases.

**Recommendation: do the extraction. It is the single biggest architectural decision in this build.**

### 7.2 Introduce form ownership / origin metadata

Add a `managed_by` field to the per-form record in `wp_options['fre_client_forms']`. Values: `'admin'` (default), `'connector:cowork'`, or reserved for future connectors. On create via the connector, set `managed_by: 'connector:cowork'`. In the admin Forms Manager UI, show a small "Managed by Claude Cowork" badge for connector-originated forms. Do *not* hard-block admin edits — humans must retain final authority — but show a confirmation dialog: "This form is managed by Claude Cowork. Editing here may be overwritten on the next connector sync. Continue?"

This preserves the human's override ability while giving Cowork enough signal to avoid stomping on hand-edited forms. Cowork-side logic can read `managed_by` and `modified` and decide whether to re-deploy or leave alone.

**Recommendation: implement from day one. Retrofitting ownership later is expensive once forms exist in the wild.**

### 7.3 Introduce explicit form versioning

The form JSON has a top-level `version` field that is stored and ignored. For the optimization loop Cowork wants to run (A/B test a form, swap versions, correlate entries with version), this field needs to become meaningful. Two options:

- **Passive versioning:** the connector writes a monotonic version number on every update; each entry stamps the version it was submitted against in its meta. Cowork can then query "entries for form X where version = 3" for A/B analysis. No UI exposure.
- **Active versioning:** explicit revisions stored, rollback supported. Heavy.

**Recommendation: passive versioning. Ship it with the connector. It costs one integer field, one entry meta row, and zero UI — and unlocks the entire analytics feedback loop the user described.**

### 7.4 Dry-run on test-submit

Add a `dry_run` boolean to the test-submit endpoint. `true` means: run validation, sanitization, and return the resulting entry payload, but do NOT insert into `fre_entries`, do NOT fire `fre_entry_created`, do NOT send notifications, do NOT dispatch webhooks. `false` (default for the endpoint, but opt-in for Cowork) means: full submission including side effects.

Also add a `skip_notifications` boolean independent of `dry_run` — useful when Cowork wants a real entry recorded (for analytics) but doesn't want the client's inbox to fill up during a test session.

**Recommendation: both flags. They are cheap to add and expensive to retrofit.**

### 7.5 Entry-read opt-in

Add a "Allow Claude Cowork to read form submissions" toggle in the connector settings page, default off. When off, the `formengine_list_entries` and `formengine_get_entry` tools return `403 entry_access_disabled` with a message explaining how to enable. When on, they work normally.

**Recommendation: ship this from day one. Privacy controls are not retrofittable in a way users trust.**

### 7.6 Rate limiting parity

Mirror Promptless's per-user, per-endpoint transient-based rate limits. Sensible starting values:

| Route | Limit | Rationale |
|---|---|---|
| preflight | 60/min | Cheap, Cowork may poll |
| list forms / get form | 60/min | Read-mostly |
| list entries / get entry | 60/min | Read-mostly |
| create form | 10/min | Protects DB, leaves headroom for batch generation |
| update form | 10/min | Same |
| delete form | 5/min | Destructive, throttle harder |
| test-submit (dry_run) | 30/min | Validation-only, cheap |
| test-submit (live) | 5/min | Real writes, throttle harder |

**Recommendation: adopt as-is. Tune later from telemetry.**

### 7.7 Introduce the `fre_manage_forms` custom capability

Today the plugin checks `current_user_can('manage_options')` everywhere forms or entries are accessed. `manage_options` is WordPress's "super admin" capability — it grants access to every setting in WordPress, including the ability to install plugins and delete the site. Reusing it for form management violates the principle of least privilege and forecloses future delegation.

Established WordPress plugins in the same category handle this uniformly by introducing their own capability: Gravity Forms uses `gform_full_access` (and an entire capability family for finer grain), WooCommerce uses `manage_woocommerce`, The Events Calendar uses `manage_tribe_events`. The pattern is: custom cap → granted to administrator role on activation → used in every permission check → available for admins to delegate to editors or custom roles when they need to.

**Decision: introduce `fre_manage_forms`.** Grant it to the `administrator` role on plugin activation. Remove it on uninstall. Replace every `current_user_can('manage_options')` check across admin UI, AJAX handlers, and the new connector permission callbacks with `current_user_can('fre_manage_forms')`. No UI to delegate it in v1 — admins who want account managers or copy specialists to operate Cowork against the form engine can use any standard role editor (User Role Editor, Members, etc.) or a one-line `add_cap()` filter. A v2 could add a dedicated delegation UI if demand warrants.

This is a small amount of work (one activation hook, ~15 call-site swaps, one uninstall hook) done once, versus a cross-cutting refactor later once the capability is assumed everywhere. The internal-use context does not change this recommendation — agency workflows where non-admins operate client sites are real even for one-operator shops the moment a second person joins.

### 7.8 Publish a standalone JSON schema for form definitions

Today the only contract for what constitutes a valid form JSON document is the `FRE_JSON_Schema_Validator` class. The rules live in PHP code. Cowork generates form JSON by reading `CLAUDE.md` prose and the validator source, which produces good but not perfect first-try outputs.

The industry-standard approach — used by every mature tool-calling API, every OpenAPI-driven integration, and recommended by Anthropic's own MCP documentation — is to publish a machine-readable JSON Schema document that both the consumer (Cowork) and the producer (the validator) can reference. The measurable payoff in LLM-generated structured output is substantial: first-try validity rates on LLM-generated structured data consistently improve when a schema is provided alongside the prose, because the model can self-check against the schema during generation rather than discovering violations only at validation time.

**Decision: ship `docs/form-schema.json` as part of this connector work.** Hand-author it in v1 to match the current validator's rules. Reference it from the MCP tool `inputSchema` fields — where the tool accepts a `config` parameter, its schema is a `$ref` or inlined copy of `form-schema.json`. This makes the tool definitions self-documenting and gives Cowork a concrete target to generate against.

A follow-on refactor (not in v1 scope) can flip the dependency: make `FRE_JSON_Schema_Validator` *consume* the JSON Schema document as its rules source instead of hard-coding rules in PHP. That turns the schema into the single source of truth and eliminates the risk of validator and schema drifting apart. Worth doing, but after v1 ships.

### 7.9 Explicit connector enable toggle, default off

Mature WordPress plugins with external API surfaces almost uniformly default them to off and require explicit opt-in. Gravity Forms' REST API, Ninja Forms' API, WooCommerce's legacy REST, and dozens of others ship with a site-level toggle that gates the endpoints entirely. The reasoning is security surface: every installation having a live connector by default multiplies the attack surface across every site running the plugin, most of which don't need it.

**Decision: add an "Enable Claude Cowork Connection" toggle to the admin Claude Connection page. Default off.** When off, every `/wp-json/fre/v1/connector/*` endpoint returns `403 connector_disabled` with a message pointing to the admin URL to enable. When on, permission callbacks fall through to the capability and auth checks. This is the "outer gate"; the entry-read toggle (§5, carried forward) is the "inner gate" for the most privacy-sensitive capability. Two gates, layered, each addressing a distinct threat model.

### 7.10 Public spec document

Publish a `CONNECTOR_SPEC.md` in the form engine docs folder that is the contract for the REST API. Same function as Promptless's — defines the namespace, routes, request/response shapes, error codes, rate limits, breaking-change policy. This is what Cowork-side tooling pins to.

**Recommendation: write it *before* the first REST endpoint lands. It's how we stop the API from drifting under us.**

---

## 8. What We Deliberately Do *Not* Do

Equal weight to what we're building: what we're *not*.

- **No dedicated Form section type in Promptless.** Binding is shortcode-only per the product decision. If a future phase adds a first-class Form block to Promptless, that's a change to *that* plugin and does not require changes to the form engine connector.
- **No shared MCP server with Promptless.** Two separate entries in `claude_desktop_config.json`. Accepts the UX cost for architectural independence.
- **No OAuth, no custom API keys, no JWT.** WP Application Passwords only.
- **No multi-site support in v1.** Single-site installations only, same as Promptless v1.
- **No image/file upload support via connector in v1.** Text forms only. File-field definitions can be created (that's just JSON), but connector-originated file uploads are a later phase.
- **No entry deletion via connector in v1.** Read-only on the entries side; destructive actions stay in the admin UI behind `fre_manage_forms`.
- **No bulk operations in v1.** Every tool call acts on one form or one entry at a time. Promptless does support batch-deploy, but it's coarse-grained (multiple pages' content in one call). For the form engine, Cowork can loop tool calls, and our rate limits are sized for that.

---

## 9. Phased Implementation Plan

Four phases, each independently shippable and testable.

### Phase 1 — Foundation (no user-facing surface yet)

The goal: make the codebase ready to host a connector without yet exposing one.

- Extract `FRE_Forms_Repository` from `FRE_Forms_Manager`. Refactor existing AJAX handlers to call the repository. No behavior change.
- Introduce the `fre_manage_forms` capability. Grant it to the `administrator` role on plugin activation; remove on uninstall. Replace every `current_user_can('manage_options')` check across admin UI, AJAX handlers, and anywhere form/entry access is gated. Migration path for existing installs: the activation hook runs on plugin update too, so existing admins acquire the capability on the next plugin load.
- Add `managed_by` field to the per-form record. Default `'admin'` for all existing forms in the migration step. Render the admin badge (simple case) behind an internal flag.
- Add the passive `version` bump on form updates. Add the entry-meta `form_version` column write path in `FRE_Entry::create()`.
- Add `dry_run` and `skip_notifications` support paths inside the submission pipeline, *but* gate them behind an internal-only flag. AJAX submission handler unchanged.
- Hand-author `docs/form-schema.json` to match the current validator's rules. Reference it from `CLAUDE.md`. Validator is unchanged in this phase; the schema is the documented contract only.
- Ship tests for each refactor, plus a capability-migration test that verifies admins keep their form-management access after the `manage_options` → `fre_manage_forms` swap.

Exit criteria: `FRE_Forms_Repository` passes unit tests matching the old AJAX behavior. Admin UI functions identically. Administrator users have `fre_manage_forms`. `docs/form-schema.json` exists and validates a known-good form config.

### Phase 2 — REST API + connector auth

The goal: the REST API exists, authenticates, and is usable from curl. No Claude involvement yet.

- Register the `/wp-json/fre/v1/connector/*` namespace.
- Implement the ten routes above, each calling the repository or entry query builder.
- Implement `FRE_Connector_Auth` permission callbacks with three concentric checks: connector enable toggle, `is_user_logged_in()`, `current_user_can('fre_manage_forms')`.
- Implement per-user per-route rate limiting with the table from §7.6.
- Implement the "Claude Connection" admin page: the connector enable toggle (default off), generate/revoke App Password, show setup command (Phase 3), entry-read toggle, link to spec.
- Write `CONNECTOR_SPEC.md` referencing `form-schema.json` as the source of truth for form config shape.

Exit criteria: a curl session with the toggle enabled can preflight, list, read, create, update, delete a form, list entries, get an entry, and run a dry-run submission. All with HTTP Basic Auth. Unauthenticated calls return 401. Calls with the toggle off return 403 `connector_disabled`. Rate-limit exceeded returns 429.

### Phase 3 — MCP server + Claude Desktop integration

The goal: the connector works end-to-end from Claude.

- Build `form-engine-connector.js` as a fork of Promptless's `wordpress-connector.js`, adapted to our ten tools. Preserve the ModSecurity and protocol-version fixes.
- Serve it via admin-ajax download handler (copying Promptless's pattern).
- Generate the one-line bash setup command (macOS first, following Promptless's lead).
- Ship the "Claude Connection" admin page with the generated command.
- Pressure-test: create 10 forms, list, update, delete, dry-run submit, live-submit, read entries, all from Claude Desktop. Document bugs in a `CONNECTOR_TESTING_REPORT.md`.

Exit criteria: a fresh site with the form engine plugin + Claude Desktop can be connected in under five minutes by pasting one bash command, and Claude can create a form and see it rendered via shortcode on the site.

### Phase 4 — Observability and hardening

The goal: operate it.

- Log every connector call (route, user ID, response code, duration) to an internal log table or `error_log()` behind a debug flag.
- Add preflight checks that surface useful diagnostic state (DB tables present, connector enable toggle state, entry-read toggle state, last 5 connector call outcomes, current user's capability status).
- Document ModSecurity and host-specific workarounds in `MCP_CONNECTOR_SETUP.md`.
- Cross-host testing: BlueHost, GoDaddy, Kinsta, SiteGround, generic VPS. Catalog host-specific issues.
- Document the Promptless ↔ Form Engine end-to-end workflow for Cowork documentation.

Exit criteria: the connector is documented to the same depth as Promptless's is today.

---

## 10. Risks and How to Absorb Them

**Risk: the repository extraction breaks existing admin workflows.**
Mitigation: do the extraction as a pure refactor in Phase 1 *before* any new code. Unit test the AJAX handlers against the old and new paths. The `class-fre-forms-manager.php` file grows a thin adapter layer; the CRUD moves out.

**Risk: form ownership badges confuse users who forgot they connected Cowork.**
Mitigation: the admin page's Claude Connection tab always shows current connection state (configured / not configured). The badge on a form is a one-line tooltip, not a modal.

**Risk: entry-read opt-in is missed, and Cowork appears broken when asked to review leads.**
Mitigation: the `formengine_list_entries` tool returns a specific `403 entry_access_disabled` error with the exact admin URL to enable the toggle. Cowork's prompt can be taught to surface this message clearly.

**Risk: two MCP entries in `claude_desktop_config.json` fight each other or misfire.**
Mitigation: they don't — each MCP server is a separate Node process with its own stdio. Claude Desktop multiplexes. But: each entry needs a unique `name` key (`promptless-wordpress` vs `form-engine-wordpress`) to avoid collision. Our setup command enforces this.

**Risk: the form JSON schema evolves and connector-created forms become invalid.**
Mitigation: the passive `version` field gives us migration granularity. When the schema changes, a migrator can upgrade stored forms. The connector spec pins a minimum schema version it emits.

**Risk: shared-host ModSecurity WAFs block the connector.**
Mitigation: inherit Promptless's fix verbatim. Reproduce it in `form-engine-connector.js`.

**Risk: performance — listing entries on a site with 100k+ submissions.**
Mitigation: `FRE_Entry_Query` is indexed-query-backed; pagination is already cheap. Hard-cap page size in the REST layer at 100. Document that larger operations should use the export UI, not the connector.

---

## 11. Resolved Decisions

The four items originally listed here as open questions were resolved on 2026-04-20. Each decision and its reasoning is recorded below so future maintainers can understand why these choices were made and when to revisit them.

### 11.1 No premium gate — authenticated capability check only

The form engine is not a commercial plugin. It is internal tooling supporting the user's web-development agency workflow. Adding a licensing layer (Freemius, EDD, or equivalent) would introduce a non-trivial dependency and ongoing operational burden with no business justification.

**Decision:** no premium or payment gate of any kind. Access to the connector is controlled by three concentric checks: (1) the connector is enabled site-wide (§7.9 toggle), (2) the requester is authenticated via WordPress Application Password over HTTP Basic Auth, (3) the authenticated user has the `fre_manage_forms` capability (§7.7). That is the complete auth model.

**When to revisit:** if the form engine is ever distributed publicly or commercialized. Not on the current horizon.

### 11.2 Entry retention on form delete — preserve by default

The consensus pattern across mature form plugins is separation of concerns between form definition and submission data. Gravity Forms moves deleted forms to trash and keeps entries separately accessible. Typeform soft-deletes with a recovery window. HubSpot and Formstack preserve submissions across form lifecycle events by default. The common principle: lead data is irreversibly expensive to lose, so destruction requires explicit intent — never a side effect of a form being deleted.

**Decision:** deleting a form via the connector removes the form record from `wp_options['fre_client_forms']` but leaves all associated entries in `wp_fre_entries`, `wp_fre_entry_meta`, and `wp_fre_entry_files` untouched. The orphan entries become unresolvable by form lookup (the `form_id` column still holds the deleted form's ID, but no form config matches) and remain queryable via the admin entries UI and the entry query builder. The delete response includes the count of preserved entries so Cowork and the requesting user know data was kept:

```json
{
  "success": true,
  "form_id": "old-quote-form",
  "entries_preserved": 47,
  "message": "Form deleted. 47 associated entries have been preserved and remain accessible in the admin Entries view."
}
```

Entry deletion remains a manual administrative action behind `fre_manage_forms` in the admin UI, explicitly out of connector scope for v1. This matches industry best practice (destructive actions on lead data require explicit human intent, not agent-initiated side effects).

**When to revisit:** never, absent a compelling data-hygiene argument. The current behavior of admin-initiated form deletes already preserves entries; we're maintaining continuity.

### 11.3 Custom capability `fre_manage_forms` — see §7.7

Full reasoning is in §7.7. Short form: replacing `manage_options` with `fre_manage_forms` across the plugin is a small, one-time investment that enforces least-privilege and enables future non-admin delegation without a cross-cutting refactor. Agency workflows where account managers or copy specialists operate Cowork on client sites are real the moment a second person joins the team, so designing for this now is cheaper than retrofitting later.

### 11.4 Standalone `form-schema.json` shipped with the connector — see §7.8

Full reasoning is in §7.8. Short form: published JSON Schema documents measurably improve first-try validity of LLM-generated structured output, and serve as the contract Cowork pins to. Hand-author in v1 to match the current validator; a follow-on refactor flips `FRE_JSON_Schema_Validator` to consume the schema as its source so there is never drift between "what Cowork is told" and "what the server enforces."

---

## 12. Done State Definition

Phase 4 exits when:

- A fresh WordPress install with Form Engine + Claude Desktop can be connected in under five minutes via a single admin command.
- Claude Cowork can create a form from natural language, retrieve its shortcode, and the site owner can paste that shortcode into a Promptless section and see a working form on the frontend.
- Claude Cowork can query the last 100 submissions of a form and correlate against analytics data to propose form changes.
- Claude Cowork can deploy a v2 of the form via update, and subsequent entries are stamped with version 2 for downstream A/B analysis.
- `CONNECTOR_SPEC.md`, `MCP_CONNECTOR_SETUP.md`, and `CONNECTOR_TESTING_REPORT.md` exist and are equivalent in depth to Promptless's.
- The AISB token contract (separate document) is updated if any new `--aisb-*` tokens are consumed during this work.

That is the finish line for v1.

---

## 13. References

- `ai-section-builder-modern/docs/development/CONNECTOR_SPEC.md` — reference API spec
- `ai-section-builder-modern/docs/development/MCP_CONNECTOR_SETUP.md` — reference setup and bug log
- `ai-section-builder-modern/docs/development/CONNECTOR_TESTING_REPORT.md` — reference test report
- `ai-section-builder-modern/includes/Admin/ConnectorSettings.php` — reference admin UI pattern
- `ai-section-builder-modern/includes/Connector/ConnectorAPI.php` — reference REST controller
- `ai-section-builder-modern/includes/Connector/ConnectorAuth.php` — reference auth + rate-limit pattern
- `form-runtime-engine/includes/Admin/class-fre-forms-manager.php` — CRUD to be extracted
- `form-runtime-engine/includes/Database/class-fre-entry-query.php` — entry query builder, reusable as-is
- `form-runtime-engine/includes/Core/class-fre-submission-handler.php` — submission logic to be wrapped
- `form-runtime-engine/docs/AISB_TOKEN_CONTRACT.md` — design token contract with Promptless (already established)
