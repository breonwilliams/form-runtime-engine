# Form Runtime Engine — Architectural Audit

Prepared 2026-05. Same format as `themes/promptless-theme/THEME_AUDIT.md`. Synthesizes two parallel deep-dive audits (core architecture + security/performance/integrations) plus my own verification.

**Plugin version audited:** 1.6.1 (mature codebase, ~22k lines across 57 PHP files).

The bottom line up front: **FRE is architecturally sound and production-ready.** No anti-patterns to unwind, no critical bugs requiring an emergency response. What it has is **growth pain** — three classes have crossed the size threshold where they're starting to feel like god objects, and the absence of automated tests means future feature additions carry regression risk that the current manual-test culture hasn't formalized. The recommendations below are about **scaling the codebase to handle 12 more months of feature additions** without compounding debt.

Findings are graded **Critical** (do soon — actively limiting velocity or accepting real risk), **Important** (real improvements but not urgent), **Nice-to-have** (polish), and **Non-issues** (claims I checked and downgraded).

---

## Critical

### C1. `FRE_Submission_Handler` is becoming a god object (995 lines)

**Where:** `includes/Core/class-fre-submission-handler.php` — 18 public methods, single class responsible for: nonce + idempotency, spam protection orchestration (honeypot/timing/rate-limit), validation + sanitization delegation, file upload validation, entry creation, email + webhook dispatch, AJAX response formatting, error handling.

**Why it's critical now:** every new feature (Cowork's `process_submission()` public API surfaced in v1.5, Twilio integration in v1.6, the upcoming v2 connector improvements) layers on this class. It's still functional — but each addition makes the next one harder, and the class now has ~6 distinct responsibilities. This is the same shape `SectionRenderer` had in Promptless before its Phase 2 split: works fine, until it doesn't.

**Recommended fix:** Extract a `Submission_Pipeline` class that accepts (form config, POST data, options) and returns a normalized result (entry_id + status + warnings). Each pipeline step delegates to focused classes that already exist (`FRE_Validator`, `FRE_Sanitizer`, `FRE_Upload_Handler`, `FRE_Entry`, `FRE_Email_Notification`, `FRE_Webhook_Dispatcher`). `FRE_Submission_Handler` becomes a thin AJAX wrapper around the pipeline.

**Effort:** ~40 hours, on the order of Promptless Phase 2. Before starting: build snapshot tests of current submission outputs (similar to how Phase 0 snapshots protected the SectionRenderer split). Without those tests, this refactor is reckless.

### C2. `FRE_Connector_API` is becoming a god object (1159 lines)

**Where:** `includes/Connector/class-fre-connector-api.php` — 24 public methods. One class handles: REST preflight (schema, health check, diagnostics), form CRUD (list/get/create/update/delete), entry CRUD (list/get/delete), SMS log queries, response formatting + error translation.

**Why it's critical now:** this is the **public API** that external callers (Cowork MCP, future integrations) depend on. Tight coupling makes versioning hard (you'd have to fork the whole class to support v2 alongside v1) and makes endpoint-level testing nearly impossible without integration test infrastructure that doesn't exist yet.

**Recommended fix:** Split into focused handler classes:
- `Connector_Preflight_Handler` — schema, health, diagnostics
- `Connector_Forms_Handler` — form CRUD
- `Connector_Entries_Handler` — entry CRUD + SMS logs

Keep `FRE_Connector_API` as a thin router that delegates to handler classes. This pattern is already established in WP REST API itself (controllers per resource type).

**Effort:** ~35 hours, mostly mechanical extraction. Lower risk than C1 because the public surface (REST routes + responses) doesn't change.

### C3. No automated test infrastructure

**Where:** the entire plugin. The `tests/` directory doesn't exist. Only `docs/CONNECTOR_TESTING_REPORT.md` documents one-off manual pressure tests from April 2026 on a single host (getflowmint.com).

**Why it's critical now:** C1 and C2 are blocked by this. You cannot safely refactor a 995-line submission handler or a 1159-line connector API without snapshot or unit tests confirming behavior is preserved. This is exactly the situation Promptless was in before Phase 0 — the test scaffolding was a prerequisite for everything else.

**Recommended fix:**
- **Phase 0a:** PHPUnit bootstrap + `tests/Unit/` with focused tests for security-sensitive paths first: `FRE_Validator`, `FRE_Sanitizer`, `FRE_Upload_Handler::validate_mime()`, `FRE_Webhook_Dispatcher::sign_payload()`, `FRE_Rate_Limiter`. Target: 60% coverage on security-critical code in the first sprint.
- **Phase 0b:** Snapshot harness for full-form rendering (similar to `tests/test-section-renderer.php` in Promptless). Render representative form configs to HTML, save goldens, verify byte-equivalent on every PR.
- **Phase 0c:** Integration tests for submission pipeline (POST data → entry created → email queued → webhook dispatched). 

**Effort:** ~25 hours for the phase 0 scaffolding, plus 30 min per future PR to add tests for new code. Without this, the refactors in C1/C2 are reckless to attempt.

---

## Important

### I1. `FRE_Renderer` layout logic (982 lines) — extract Layout_Engine

**Where:** `includes/Core/class-fre-renderer.php` — `render_fields_with_layout()`, `render_column_row()`, `render_section_with_fields()`, `render_multistep_fields()`. Loop-with-index logic for sections + columns + steps. Off-by-one bugs are hard to introduce when reading current code, but harder to avoid when modifying it.

**Recommendation:** Extract `FRE_Layout_Engine` with two methods: `normalize_fields()` flattens sections/columns/steps into a render queue; `render_queue()` iterates and dispatches to field renderers. Renderer becomes a thin coordinator.

**Benefit:** Adding new layout types (tabs, accordions, conditional sections) becomes a localized change instead of a cross-cutting one. Easier to test layout decisions in isolation.

**Effort:** ~20 hours.

### I2. AISB token contract drift — verify all `var(--aisb-*)` usage has fallbacks

**Where:** `assets/css/frontend.css`, `assets/css/neo-brutalist.css`, plus any new CSS added since. The contract at `docs/AISB_TOKEN_CONTRACT.md` lists ~57 documented token references but there's no automated verification that every CSS `var(--aisb-*)` call has a fallback.

**Why it matters:** when the Promptless plugin is inactive, missing fallbacks render as transparent or browser default — forms degrade silently, not with a visible error. Hard to catch in QA.

**Recommended fix:** add a one-liner check to the build/release process:
```bash
grep -rE 'var\(--aisb-[^,)]*\)' assets/css/*.css | grep -v '\.min\.css'
```
Anything that prints (a `var(--aisb-X)` with no comma = no fallback) is a bug. Could be wrapped in a CI check or a pre-release script.

**Effort:** ~30 min to write the check + ~2 hours to fix any flagged sites.

### I3. Webhook secret rotation is missing

**Where:** `docs/CONNECTOR_SPEC.md` §3 explicitly notes the secret can be set or regenerated but never read back. Good. **What's missing:** an endpoint to rotate the secret atomically and surface the new value to the calling agent (so Cowork can re-store it).

**Why it matters:** webhook secrets persist forever today. If a URL appears in a server log or Slack history, an attacker can spoof submissions indefinitely. Industry standard is rotation: monthly, quarterly, or on-demand after suspected exposure.

**Recommended fix:** add `POST /forms/{id}/webhook-rotate-secret` to the connector API. Returns the new secret in the response (one-time read). Document the rotation flow in the spec.

**Effort:** ~4 hours for the endpoint + tests + spec update.

### I4. Twilio module needs cleaner isolation

**Where:** `includes/Twilio/` (~2500 lines across 6 files). Currently woven into the submission pipeline and admin UI. SMS log query methods live in `FRE_Entry`. Makes core FRE harder to test without the Twilio classes present.

**Recommended fix:** wrap Twilio in a conditional bootstrap (`if ( $twilio_enabled )`). Use filter hooks for virtual form registration instead of direct calls from `FRE_Forms_Manager`. SMS log methods should live in the Twilio module, not `FRE_Entry`.

**Effort:** ~25 hours.

### I5. Twilio credential decryption — verify no silent plaintext fallback

**Where:** `includes/Twilio/class-fre-twilio-client.php` (or wherever credentials are loaded from `wp_options`). The setup notes claim AES-256-CBC encryption, but if decryption fails (corrupted option, wrong key, plugin reinstall), does the code fall back to plaintext or fail hard?

**Recommended fix:** read the load path. Confirm decryption failure throws/returns `WP_Error`, never returns the encrypted blob as if it were plaintext. Add a unit test for the failure case once test infrastructure exists.

**Effort:** ~2 hours to audit + fix if needed.

### I6. Rate limiter race condition (transient-based)

**Where:** `includes/Security/class-fre-rate-limiter.php`. Uses `get_transient()` + `set_transient()` for counters. Not atomic — two simultaneous requests can both increment past the limit.

**Why it matters:** in a real DoS or scripted-spam scenario, the limit is bypassed by concurrency.

**Recommended fix:** either use a custom `wp_options` row with InnoDB row-level locking, or switch to Action Scheduler's built-in deduplication. Document the change in `docs/CLAUDE.md`.

**Effort:** ~6 hours plus load testing.

---

## Nice-to-have

- **N1.** Email notification retry uses transients for queue state. Fragile under high volume; Action Scheduler would be cleaner. ~8 hours when load justifies.
- **N2.** Upload Handler MIME validation is half-extracted into `FRE_MIME_Validator` (576 lines). Finish the extraction so `Upload_Handler` is purely orchestration. ~10 hours.
- **N3.** Magic strings for hooks (`fre_init`, `fre_field_types`, etc.) — extract to constants for IDE autocomplete + grep-ability.
- **N4.** Asset enqueueing — verify front-end CSS only loads when `[fre_form]` shortcode is present (saves bytes on chromeless pages).
- **N5.** Admin entries list — verify no N+1 metadata fetch when paginating 100+ entries.
- **N6.** Connector entry-read gate audit — confirm no other endpoint leaks entry counts or field values when the gate is off (side-channel disclosure).
- **N7.** Condition evaluator (`class-fre-conditions.php`) doesn't have an interface or registry. Fine today (operators are stable); revisit if multiple classes need condition logic.

---

## Non-issues (audit-flagged but verified as fine)

- **NI1.** Singleton bootstrap pattern in `Form_Runtime_Engine::instance()` — flagged as "classic WordPress pattern." It is, and it's the right call for a plugin that wires hooks at load time. Not a problem.
- **NI2.** No PSR-4 / Composer. Class-map autoloader is the FRE convention and matches PRE. Aligning this with Promptless (which adopted Composer) would be a multi-day cross-plugin standardization effort, not worth it for FRE alone.
- **NI3.** Field type interface (`FRE_Field_Type`) is well-designed and complete. No anti-patterns. Adding new field types is a 3-file change (interface impl + autoloader entry + docs), not a registry refactor.

---

## Comparison with Promptless plugin (post-refactor)

| Aspect | FRE | Promptless | Verdict |
|---|---|---|---|
| Bootstrap | Singleton on `plugins_loaded` | Same | Identical pattern |
| Per-type renderers | Field types: interface + class-map (good); Form rendering: monolithic in 982-line Renderer (I1) | Per-type renderer classes via SectionRegistry | FRE's field-type pattern matches; form-rendering pattern is one refactor behind |
| Validation | Single `FRE_Validator` class | `SchemaValidator` + per-type validators | FRE more generic; equivalent for the use case |
| Admin CRUD | Two-tier (`FRE_Forms_Manager` → `FRE_Forms_Repository`) | Same two-tier pattern | Identical |
| Webhooks | `FRE_Webhook_Dispatcher` (961 lines, focused) | Equivalent dispatcher | Same complexity, both acceptable |
| Tests | None | Snapshot suite + unit tests | FRE significantly behind |
| Constants for hooks | Magic strings | `AISB_HOOKS` enum concept | Promptless slightly more maintainable |

---

## Recommended sequencing

If you tackle this work, the right order is:

**Wave 1 (next focused session — ~2 weeks):**
1. **C3 Phase 0a** — PHPUnit bootstrap + tests for security-sensitive paths (Validator, Sanitizer, MIME, Webhook signing, Rate Limiter). This unblocks everything else.
2. **I2** — AISB token fallback verification (30 min check, fix any flagged sites).
3. **I5** — Twilio credential decryption audit (2 hours).
4. **I3** — Webhook secret rotation endpoint (4 hours, doable in isolation).

**Wave 2 (next quarter — ~3 weeks):**
1. **C3 Phase 0b** — Snapshot harness for form rendering.
2. **C2** — Decompose `FRE_Connector_API` into handler classes. Lower risk than C1, builds confidence for C1.
3. **I6** — Rate limiter atomic fix.

**Wave 3 (later — ~4 weeks):**
1. **C1** — Extract `Submission_Pipeline`. The Promptless equivalent (Phase 2) was the highest-impact refactor of that codebase. Same is true here.
2. **I1** — Layout_Engine extraction.
3. **I4** — Twilio module isolation.

**Recommendation:** Wave 1 is genuinely independent of Wave 2/3 and can ship in a single sprint. Treat it as the prerequisite for any larger refactor. The code is healthy enough that you don't need to do C1/C2 to ship product features — but if you put off the test infrastructure (C3) much longer, every future change carries growing regression risk.
