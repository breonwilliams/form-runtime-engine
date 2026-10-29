# Roadmap: Form availability rules (open date, close date, entry cap, closed message)

Roadmap status: approved   <!-- approved by the human 2026-10-29 ("Approve it"), after Q-001 … Q-008 were answered. draft | approved. The orchestrator only runs "approved". -->

<!-- Drafted 2026-10-29T16:23:34Z from HEAD b3579ee. Source of the requirements:
     ai-section-builder-modern/docs/development/NEXT_PHASE_ROADMAP.md §3.11 and its §2.0 row.
     DO NOT set this to approved while any question in QUESTIONS.md (Q-001 … Q-008) is
     unanswered. Those are product decisions the spec leaves open; the orchestrator passes
     every answered question to each worker as binding context, so the answers ARE part of
     this plan. Done-states below that depend on one name it as "per Q-00n". -->

## Goal

A form owner can give a Promptless form an open date, a close date and a maximum number of
entries, plus the message a visitor sees when the form is not accepting submissions. The
server refuses a submission to a closed form on both submission paths (the AJAX form and the
programmatic path behind `formengine_test_submit`) before anything is stored, emailed,
webhooked or handed to FlowMint, and the cap holds when two people take the last place at the
same moment. An agent can set, change, clear and read these rules through the Forms MCP relay,
and the schema, rulebook and validator describe exactly what the handler enforces.

## Config

- verify: `.claude/hooks/verify.sh run`   (exit 0 = green: tamper check, `php -l` over shipped files, Unit suite, Integration suite against Local's MySQL)
- max_attempts_per_phase: 3
<!-- max_phases_per_run removed for run 3 (P2–P6). Human, 2026-10-29: "run the rest of the roadmap — P2 through P6 — without stopping between phases. Halt only if you hit a decision that's genuinely mine to make, or if a phase runs out of attempts." Runs 1 and 2 were capped at one phase each. -->
- protected: everything CLAUDE.md lists as **Locked** — existing `tests/**` files (new, uncommitted test files stay editable), `phpunit.xml(.dist)`, `composer.json/.lock`, `.gitignore`, `bin/install-wp-tests.sh`, `.github/**`, `.claude/settings*.json`, `.claude/hooks/**`, `vendor/**`, `refs/verify/*`, `~/.wp-tests/**`, `~/.claude/**`

**Preconditions for every run** (the gate fails closed without them, and that is a BLOCKED
halt, not a phase failure):
- The **AI Section Builder** site is running in Local (its MySQL socket only exists while it
  runs; the Integration suite needs it).
- Run in the main checkout, not a worktree: worktrees have no `vendor/` and the gate is red
  there until `composer install`.
- Baseline is green: Unit 484 tests, Integration 97 tests / 3 skipped (measured 2026-10-29,
  1.12.3). Any failure is a regression; there is no tolerated floor any more.

## What the gate can and cannot prove for this feature

Read this before calling any phase "verified".

| Requirement | Proved by the gate? | Where it is actually checked |
|---|---|---|
| Decision logic (dates, timezone, unlimited vs 0, reasons, messages) | **Yes** — Unit suite | P1 tests |
| Refusal before any side effect, on both paths | **Yes** — Integration suite (real DB, real hooks) | P3 tests |
| Cap holds under concurrent submissions | **Only partly.** See below. | P2 test + a human load check |
| Relay tool descriptions (`includes/Connector/assets/form-engine-connector.js`) | **No.** The gate runs PHP only; `tests/relay-tool-errors.test.js` is not in it. The per-edit hook runs `node --check` (syntax only). | Reviewer reads; human runs the relay |
| The relay agents actually use (`~/form-engine-mcp/form-engine-connector.js`) | **No.** It is a copy, byte-identical to the repo file on 2026-10-29, and nothing keeps them in sync. | Human copies and restarts |
| Front-end behaviour of a stale cached page | **No.** FRE has no JS test infrastructure (known, accepted gap). | Human, in a browser |
| wp-admin screens | **No.** | Human, in a browser |
| FlowMint's behaviour with this feature | **No. Nothing verifies FlowMint and Forms together** (CLAUDE.md "Known gaps"). | Human checklist at the end of this file |
| PHP 7.4 / 8.2 | **No.** Local runs 8.5; CI covers 7.4/8.2 for lint + Unit only after push. | CI after push |

**On concurrency, specifically.** The Integration suite runs in one PHP process on one
database connection, and `WP_UnitTestCase` wraps every test in a transaction on that
connection (`PForms_Entry::create()`'s own `START TRANSACTION` implicitly commits it). One
connection cannot race itself, so a test that submits twice in a row proves sequencing, not
atomicity. A meaningful in-suite proof needs a **second, independent database connection**
interleaving with the first, and has to cope with the test transaction's visibility and row
locks. Even then it proves the SQL statement is atomic in MySQL/InnoDB, not that two real
HTTP requests through PHP-FPM behave — that last step is a human-run check (end of file).
Note also that `PForms_Submission_Lock`, the pattern §3.11 says to copy, is itself tested only
in the Unit suite against `FakeOptionsWpdb`: its atomicity has never been exercised against a
real database either. Copying its shape does not inherit a proof.

## Phases

### P0: Keep orchestration state out of the release

**Intent:** `.orchestrator/` is not in `bin/build-release.sh`'s exclude list, so this plan and
its logs would ship in the WordPress.org ZIP. Plugin Check flags hidden files, and the WP.org
review team has already flagged internal planning documents in this plugin (see the comment
above the WP.org exclusions in that script). Fix it before the directory fills up.

**Plan:** Exclude the directory from the release build the same way the other developer-only
paths are excluded.

**Done when:**
- `./bin/build-release.sh` produces a ZIP whose listing (`unzip -l build/form-runtime-engine.zip`) contains no path containing `.orchestrator`.
- Apart from that, the ZIP's file list is identical to one built from `b3579ee`: the diff of the two `unzip -l` listings (names only) is empty, and the report includes it.
- The verify command exits 0.

### P1: One "is this form open?" decision, and settings that mean one thing

**Intent:** §3.11 requires a single decision point used by both submission paths and by
rendering, extensible through a filter, with "unlimited" as its own state and dates in the
site timezone. Everything later calls this; build and pin it first, with no enforcement yet.

**Plan:** Settings live under the form's `settings` like every other setting and are
normalised through `PForms_Registry::$default_settings`. Save-time validation goes where form
JSON is already validated (`PForms_JSON_Schema_Validator` / the Forms repository save path).
Names, nesting and the exact signature are the worker's call.

**Done when:**
- There is exactly one function or method that, given a form ID or config (and, for the cap, the current place count from P2 — a stub or injected value is fine in this phase), returns: open or closed; a machine-readable reason (at least: before the open date, after the close date, cap reached); and the message to show. A reviewer can find every caller with one grep.
- Its result passes through a filter whose name starts with `pforms_`, and a Unit test proves a filter callback can close an otherwise-open form and open an otherwise-closed one, with its own reason and message.
- Unit tests show, for a form with no availability settings at all, that it is open — so every existing form keeps working unchanged.
- **Unlimited is explicit:** a Unit test shows an absent cap means no limit; a cap of `0` means closed (zero places), not unlimited; and the validator refuses a negative cap, a non-integer cap, and a string such as `"unlimited"` or `""` with a message naming the setting. No input other than "absent" (or the explicit unlimited value, if one is chosen and documented) produces unlimited.
- The validator refuses a malformed date and a close date at or before the open date, at save time, through both the admin save path and the connector's create/update path (Unit tests for each, or one test per path).
- **Timezone:** with the site timezone set to a zone far from UTC (e.g. `Pacific/Auckland` or `America/Los_Angeles`, via `timezone_string`), a Unit test shows the form flips from not-yet-open to open, and from open to closed, at the configured local time, and one minute either side of it gives the other answer. The test must fail if the comparison is done in UTC or server time.
- Date format, inclusivity and the before-open message behave as decided in **Q-006** and **Q-007**, each pinned by a test.
- The verify command exits 0.

### P2: A place count that cannot be over-claimed

**Intent:** The cap's correctness under concurrency. Two people sending the last place at the
same moment must not both get it, and what counts toward the cap must be exactly what
**Q-001** decided.

**Plan:** §3.11's constraint, verbatim: the check and the claim are one atomic step, written
through `$wpdb`, never transients or the object cache (1.11.0 found those non-atomic on managed
hosts). How the count is stored is open, subject to Q-001. Read "On concurrency" above before
designing the test.

**Done when:**
- There is a claim operation that, for a form with a cap of N, succeeds at most N times in total, and a release operation that returns a place.
- An **Integration** test using **two independent database connections** (not two calls on the same `$wpdb`) has both attempt the last remaining place with their steps interleaved, and asserts exactly one succeeds. The worker must also show, in the phase report, that the same test **fails** against a deliberately naive count-then-insert version (run once, locally, not committed) — otherwise the test may pass for the wrong reason.
- Nothing in the claim/release path calls `get_transient`, `set_transient`, `delete_transient`, `wp_cache_*` or `get_option`/`update_option` for the count (grep evidence in the report).
- Integration tests pin each counting rule from **Q-001**: whether a spam-flagged submission takes a place; whether deleting entries (through `PForms_Entry::delete()`, the path the admin, the connector and FlowMint's `fre_delete_entry`/`fre_delete_entries` steps all use) gives places back; whether a form with `store_entries` off can have a cap and how it counts; what happens when a cap is first added to a form that already has entries; and whether a restored "Mark as Not Spam" entry takes a place.
- Uninstall removes whatever P2 stores (the existing uninstall test, or a new one, covers it).
- The verify command exits 0.

### P3: Enforce it on both submission paths, before any side effect

**Intent:** The rule itself. Page caches keep serving an open form after it closes, and the
connector's path never renders at all, so the refusal has to be in the submission handler,
every time.

**Plan:** Both paths live in `includes/Core/class-fre-submission-handler.php`
(`handle_submission()` for AJAX, `process_submission()` for the connector) and share
`pforms_before_submission_process`. Where in each path the check and the claim go is the
worker's call, within the done-state.

**Done when** (all Integration tests unless noted, each run on **both** paths unless the path
cannot reach the case):
- A submission to a form that is closed by date or by a full cap creates no entry row, no entry meta, no file row and no file on disk; sends no notification email; dispatches no webhook; and fires neither `pforms_entry_created` nor `pforms_submission_complete` (so FlowMint's listener, which hangs off the latter, cannot start a run). The test asserts each of these, not just the row count.
- The visitor gets the form's closed message (per **Q-006** for the before-open case), not a generic error: the AJAX response carries a distinct error code and that message; `process_submission()` returns a `WP_Error` with a distinct code whose data includes the reason.
- A closed form is refused **even when the submitted fields are invalid** — the visitor is told it is closed, not asked to fix fields on a form they cannot submit.
- A submission that would have taken a place but then fails later (validation failure, a refused upload, a database error from `PForms_Entry::create()`) does not consume the place: the count afterwards equals the count before.
- A spam-caught (honeypot) submission behaves exactly as **Q-001** decided, and still receives the normal fake-success response it gets today.
- A form with `store_entries` off still has its dates enforced, and its cap enforced or refused at save, per **Q-001**.
- `dry_run` behaviour on a closed form and its effect on the count are as decided in **Q-008**, pinned by a test.
- If **Q-003** chose an action when the cap is reached: it fires exactly once, on the submission that takes the last place, after that entry is stored, and never on a refused submission (test with a listener counting calls).
- The existing duplicate-detection and idempotency tests are still green (they share the claim/release machinery and the early-exit paths).
- The verify command exits 0.

### P4: Rendering, and what a stale cached page shows

**Intent:** The courtesy half: a form that is closed when the page renders shows the message
instead of the form; a page cached while the form was open still lands the visitor on the
closed message when they submit. Neither replaces P3.

**Plan:** Rendering goes through the same decision point as P1. The front-end handling of
the P3 error code is in the form's JS. Behaviour as decided in **Q-004**.

**Done when:**
- An Integration (or Unit) test renders a closed form via the shortcode and asserts the output contains the closed message, contains no `<form` element, no submit button and no `pforms_field_` inputs; and the same form, open, renders exactly as it did before this feature (compare against the existing render tests, which must be unchanged and green).
- The closed message is escaped/sanitised on output the same way `success_message` is; a test renders a message containing `<script>` and asserts it is not emitted as markup.
- The closed message is announced to assistive technology the way the success confirmation already is (same live-region / focus treatment); the existing accessibility tests stay green, and the reviewer confirms by reading that the same mechanism is used.
- The front-end, on receiving the P3 closed error code, shows the message as decided in **Q-004**. **Not verifiable by the gate** (no JS tests): the reviewer reads the JS change; the human browser check at the end of this file is what confirms it.
- The verify command exits 0.

### P5: The connector, the schema and the docs say what the code does

**Intent:** §3.11: an agent must be able to set, change, clear and read these rules, and
reach for them unprompted; the schema, rulebook, tool descriptions and validator must not
promise anything the handler does not do.

**Plan:** The surfaces are `includes/Connector/class-fre-connector-api.php` (rulebook),
`docs/form-schema.json`, `docs/FRE_KNOWLEDGE_MAP.md`, `docs/CONNECTOR_SPEC.md`, this repo's
`CLAUDE.md` (settings table, hooks reference) and the relay's tool descriptions in
`includes/Connector/assets/form-engine-connector.js`.

**Done when:**
- Through the connector REST endpoints that back `formengine_create_form` / `formengine_update_form`: an Integration test sets all availability settings, changes them, and clears them (back to "always open, unlimited"), and a refused value comes back with the same message the admin path gives.
- The connector's form read (`formengine_get_form`'s endpoint) returns whether the form is open now, the reason, and the current count and cap (Integration test). Whether `formengine_list_forms` includes it is the worker's call; say which in the report.
- `docs/form-schema.json` describes every availability setting with its type, the unlimited state and the date format, and is valid JSON (`jq empty`); a Unit test asserts every availability key in the defaults appears in the schema and in the rulebook (so a later rename fails the gate instead of drifting silently).
- The rulebook states which entries count toward the cap and what deleting entries does (per **Q-001**), that closed forms refuse `formengine_test_submit` (per **Q-008**), and the cap-reached action if **Q-003** chose one.
- `CLAUDE.md` lists the settings in the Settings table and any new filter/action in the Hooks Reference, with the `pforms_` prefix; `RetiredNamesInDocsTest` stays green.
- The relay tool descriptions mention the availability settings and the read-back. **Not verified by the gate**: the reviewer confirms by reading, and `node --check` must pass on the file.
- The verify command exits 0.

### P6: The wp-admin surface

**Intent:** The form owner can see, without reading JSON, whether a form is open and why, and
can do whatever **Q-001** / **Q-002** decided they can do (for example reset or adjust the
count).

**Plan:** As decided in **Q-002**. Forms are edited as JSON in the Forms Manager today
(`includes/Admin/class-fre-forms-manager.php`); availability settings are at minimum editable
there like every other setting.

**Done when:**
- The Forms list shows each form's state: open, or closed with the reason, and for a capped form the count against the cap. A Unit or Integration test asserts the rendered list contains these for a fixture form in each state.
- Every admin action this phase adds (per Q-001/Q-002) checks `pforms_manage_forms` and a nonce, and a test proves a request without either is refused.
- Dates are shown to the admin in the site timezone, with the zone named (test, using the P1 non-UTC zone).
- **Not verifiable by the gate:** layout and usability. Covered by the human check at the end of this file.
- The verify command exits 0.

## Human checks after the run (the gate cannot do these)

These are where the plan's "Not verified" list ends up. None is optional before a release.

1. **Real concurrency.** On the Local site, a form with a cap of 1 and two or more `curl` processes posting to `admin-ajax.php` at the same instant (each with a freshly fetched nonce and timing token, e.g. `xargs -P`). Exactly one entry, every other response the closed message. Repeat ~20 times. This is the only check that goes through real PHP-FPM workers and separate connections.
2. **Stale cached page.** Load a form, close it (change the date or fill the cap from another tab), submit the still-open page. The closed message appears as Q-004 decided; nothing is stored. Try once with a page-cache plugin active if one is available.
3. **Relay.** Copy `includes/Connector/assets/form-engine-connector.js` to `~/form-engine-mcp/`, restart the relay, and have an agent set, read back and clear the rules on a test form, then `formengine_test_submit` against it closed.
4. **FlowMint (nothing automated checks this):**
   - A closed-form submission starts **no** run in FlowMint's Runs list, and the submission that takes the last place starts exactly one.
   - A retention workflow using `fre_delete_entries` on a capped form: confirm the form reopens or stays closed exactly as Q-001 decided, and that the Forms list count shows it.
   - FlowMint's workflow editor and validator still load a form that has availability settings (they read the stored form record via `pforms_get_db_form` / `PForms_Forms_Repository::get`).
   - If Q-003 added a cap-reached action: it is **not** a FlowMint trigger until FlowMint adds one. That is separate FlowMint work, not part of this roadmap.
5. **wp-admin.** The Forms list and any new controls in a browser, light and dark admin colour schemes, and at narrow width.
6. **PHP 7.4 / 8.2.** CI after push (lint + Unit). Optionally `php -l` with Local's 7.4 and 8.2 binaries (paths in CLAUDE.md "Known gaps") before pushing.
7. **Stack docs.** Mark §3.11 in `ai-section-builder-modern/docs/development/NEXT_PHASE_ROADMAP.md` (and its §2.0 row) as built, with what was decided. That is another repository; the orchestrator does not commit there.

**Out of scope:** version bump, CHANGELOG, Plugin Check and the release itself (`RELEASE.md`);
"N places left" unless Q-005 says v1; waitlists and per-user/per-email limits (the P1 filter is
where they would plug in later).

## Amendments

<!-- Orchestrator appends one line per accepted deviation: "- P1: <change> (A-001)" -->
- P0: the release ZIP is `build/promptless-forms.zip`, not `build/form-runtime-engine.zip` (A-001)
- P1: setting names, the decision point's signature/reasons and five edge rules, as built (A-002)
- P4: the default before-open message names the site timezone (Q-009 answer) (A-003)
- P2: count storage, seeding hooks, opt-in uninstall, claim(null) for Q-010, declared PHP-form gap (A-004)
- P3: take() returns its own count for pforms_form_full; availability checked before spam, so a honeypot hit on a closed form gets the closed message (A-005)
- P1/P4: two P1 before-open assertions loosened to a prefix check in a human-unlocked turn; baseline moved (A-006)
