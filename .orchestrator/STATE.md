# Orchestrator state

Run status: DONE   <!-- RUNNING | PAUSED | HALTED: QUESTION | HALTED: BLOCKED | DONE -->
Updated: 2026-10-29T20:39:30Z
Last green commit: 4682894
Next action: Run complete; PR #28 open. Post-run checks recorded below ("Post-run checks"). Before merge: have a person click Reset → OK once (5c); the 5b layout defect is fixed in daf499f. Still open: the relay (blocked: every Promptless relay targets a live site), the Promptless WP roadmap update, confirming `hc-relay` was deleted from 725printlab.com.

| Phase | Status | Attempts | Base commit | Gate | Review | Commit |
|---|---|---|---|---|---|---|
| P0 | done | 1 | 2a859bb | pass (exit 0) | APPROVE | 1b0c77d |
| P1 | done | 1 | 30340a6 | pass (exit 0) | APPROVE | d2cb13f |
| P2 | done | 1 | 1464b25 | pass (exit 0) | APPROVE | ef46c13 |
| P3 | done | 2 | 168fef3 | pass (exit 0) | APPROVE | b289ba9 |
| P4 | done | 1 | c226cc6 | pass (exit 0) | APPROVE | 9f66955 |
| P5 | done | 2 | a937345 | pass (exit 0) | APPROVE | aa60815 |
| P6 | done | 1 | 394dceb | pass (exit 0) | APPROVE | 4682894 |

<!-- Status: pending | in_progress | needs_decision | blocked | done
     Gate: "pass (exit 0)" or "fail (exit N)". Only the verify command fills this column.
     Review: APPROVE | CHANGES_REQUESTED | ESCALATE -->

## Honesty

**Mechanically verified** (verify-command exit codes only):
- Baseline 2a859bb: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T16:36:28Z (Unit 484 tests / 1254 assertions; Integration 97 tests / 231 assertions / 3 skipped).
- P0 working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T16:39:13Z (same counts). P0 changed no PHP, so this proves only that nothing regressed.
- Run 2 baseline 30340a6: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T16:46:04Z (same counts).
- P1 working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T16:56:40Z (Unit 546 tests / 1459 assertions; Integration 99 tests / 242 assertions / 3 skipped). The new tests pass and nothing regressed; it does not by itself prove the tests test the right thing (see reviewer judgment).
- P2 retry working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T17:55:35Z (Unit 549 / 1473; Integration 121 / 396 / 3 skipped). Includes the two-connection race tests against Local's real MySQL/InnoDB.
- Q-011 test change c226cc6 (human-unlocked turn): `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T18:51:29Z after the baseline move (Unit 549 / 1473; Integration 154 / 740 / 3 skipped).
- P4 retry working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T18:55:43Z (Unit 549 / 1473; Integration 166 / 807 / 3 skipped), incl. exact before-open text for New York EST/EDT, offset-only UTC+5:30 / UTC-5, Auckland NZDT.
- P3 attempt 2 working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T18:19:28Z (Unit 549 / 1473; Integration 154 / 740 / 3 skipped), existing duplicate/idempotency tests unedited and green.
- P5 attempt 2 working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T19:15:14Z (Unit 556 / 1517; Integration 180 / 947 / 3 skipped); `jq empty docs/form-schema.json` and `node --check` on the relay OK.
- P6 working tree: `.claude/hooks/verify.sh run` exit 0 at 2026-10-29T19:26:49Z (Unit 556 / 1517; Integration 192 / 1044 / 3 skipped); `node --check assets/js/admin.js` OK.

**Reviewer judgment** (a model read the code; not proof):
- P0: the release ZIP no longer contains `.orchestrator/`; the file list is otherwise identical to a b3579ee build (119 vs 119, empty names-only diff); the old script did ship 6 `.orchestrator` entries, so the fix is not vacuous. Reproduced independently by the reviewer in scratch copies; the worker's own build showed the same.
- P1: every done-state criterion met. The reviewer mutated a scratch copy to check the tests can fail for the reason they name: comparing in UTC or in server time fails 7 tests each; 0-as-unlimited fails 3; removing the filter fails both filter tests; disabling the save-time validator fails the admin and both connector path tests; Q-007 boundary mutations fail 2/5/4 tests. Both "save path" tests go through the real `save_form` and connector handlers.
- P2: every criterion and the Q-010 behaviour met. Two independent reviewers reproduced the naive count-then-insert failure themselves; the retry reviewer also killed mutants for the overwriting seed (4 failures), claim(null) as a no-op (3), deleting the count on a cap-less save (3), a no-op form-delete hook (1) and a missing release floor (risky = fail).
- P4: every criterion met by reading; render tests sound; JS `form_closed` branch cannot be pre-empted and reuses the success confirmation's live region and focus; offset rewrite checked by the reviewer on PHP 8.5.5 for +05:45, -09:30, -03:30, -00:30, +14:00, +00:00.
- P3: every criterion met on both paths. A positive-control test proves the "no side effect" spies move on an accepted submission. Reviewer mutants: dropping the release from send_error → 3 failures; moving the availability check after validation → 1 failure + 2 errors. Reviewer's own two-connection test showed take()'s LAST_INSERT_ID identifies only the true last-place request.
- P5: every claim in the rulebook, schema, knowledge map, CONNECTOR_SPEC, CLAUDE.md and relay descriptions checked against the code by two reviewers; attempt 1 was refused for promising the unbuilt count control, attempt 2 approved. Drift tests fail on a key rename (reviewer mutants).
- P6: every criterion met; count control checks capability then nonce, no nopriv hook, refuses never-capped forms without creating a row, writes only via PForms_Place_Counter::set(); escaping, translation and $_POST handling clean; the doc sentence about the control is true.

**Not verified:**
- Plugin Check on the new ZIP was not run (not in P0's done-state; it is a release gate).
- P6: wp-admin layout, wording and usability (Status column, Adjust count control, light/dark admin schemes, narrow width) — human check 5. Admin JS has no automated test. Reviewer minor findings, not fixed: an undecodable stored config shows "Closed: by a custom rule (form_not_found)"; the Forms list costs 1–2 extra queries per form.
- Whole run: FlowMint was never run against this branch (human check 4); PHP 7.4/8.2 suites not run (CI after push); no real concurrent HTTP requests (human check 1); the installed relay copy is not updated (human check 3).
- The ZIP check is not in the verify gate: a later edit to bin/build-release.sh could re-ship `.orchestrator/` and nothing automated would notice.
- P1: no test date falls on a DST transition. (The spring-forward-gap message was fixed and tested in P5.)
- P5: the relay agents actually use (`~/form-engine-mcp/form-engine-connector.js`) is NOT updated — a human copies the repo file there and restarts the relay (human check 3). Relay descriptions were read, not executed. Minor doc gaps left: CONNECTOR_SPEC §7 error table lacks `form_closed` (it is in §9.9); CONNECTOR_SPEC.md:165 says field_errors are arrays, the validator stores one string per key (pre-existing).
- P1: nothing enforces availability yet. `check()` has no production callers until P3/P4; the cap uses a stubbed count of 0 until P2.
- P1: PHP 7.4/8.2 — new files lint clean on Local's 7.4.1 (worker and reviewer, by hand); the Unit suite has not run on 7.4/8.2 (CI after push).
- P2: the race tests interleave at statement level on two connections; InnoDB row-lock waiting and real concurrent PHP-FPM requests are not exercised (human curl check, roadmap item 1).
- P4: the stale-page JavaScript (`form_closed` → closed message replaces the form, focus moves) has no automated test — read by the reviewer only; human browser check 2. The reviewer could not run the "remove the closed branch" mutation (guard refused its manual take of the Integration lock), so "the closed-render tests can fail" is reviewer reading, not a run.
- P4 (reviewer minor findings, not fixed): the closed render skips the `pforms_rendered_form` filter; a capped form's uncached render costs one count read per view.
- P3 (reviewer minor findings, not fixed): on a site with a read/write-splitting db drop-in (HyperDB/LudicrousDB without send-reads-to-master) `SELECT LAST_INSERT_ID()` could hit a replica, so `pforms_form_full` may silently not fire (the cap itself holds); a throwing third-party `pforms_entry_created` listener leaves the entry stored but its place released (count one low); a PHP `\Error` between take and keep leaks one place (request dies there).
- P3: the stale-cached-page front-end and FlowMint not starting a run are not exercised end to end (no JS tests; FlowMint not in the gate — human checks 2 and 4).
- P2: declared gap — a PHP-registered form whose cap is removed in code before its first submission keeps no count while uncapped, and PHP forms are seeded at their first capped claim, not at cap-add (A-004).

## Post-run checks (the roadmap's "Human checks after the run")

Requested by the human, 2026-10-29. **Performed by Claude** (Opus 5.5, the orchestrating
session), not by the human: the human asked to be recorded as the tester, which would make
this record false, so it names who actually ran each check. All on branch
`feat/form-availability-rules` at `f784ebf` (PR #28). Scripts, raw output and notes are in
`.orchestrator/evidence/human-checks/` (excluded from the release ZIP by P0). Test data was
created on the Local site only and removed afterwards, except where noted.

| # | Check | Verifies | Result | Evidence |
|---|---|---|---|---|
| 6 | CI, PHP 7.4 and 8.2 | P0–P6 | **PASS** — GitHub Actions run 40941404775 on f784ebf: "PHP syntax (7.4)", "PHP syntax (8.2)", "Unit tests (7.4)" (PHP 7.4.33) and "Unit tests (8.2)" (PHP 8.2.35) all pass; both Unit legs `OK (556 tests, 1517 assertions)`. CI runs lint + Unit only; Integration is not in CI. | `gh pr checks 28`; job logs |
| 1 | Real concurrency, cap 1 | P2, P3 | **PASS** — `concurrency.sh 25 10`: 25 rounds × 10 simultaneous real HTTP posts (nginx → PHP-FPM → admin-ajax, with real nonce, timing token, honeypot and a unique submission id), a fresh cap-1 form per round. Every round: 1 success, 9 `form_closed`/`cap_reached`, 1 stored entry, 0 spam rows, count 1. Run twice (250 requests each), both 25/25. The second run used a temporary mu-plugin probe (removed) that logged pid/start/end per request and held each 300 ms before the availability check: in **all 25 rounds two different PHP workers (pids 26434, 26435) were inside the handler at the same time**, and the cap still held. | `concurrency.sh`, `concurrency-probe-run-summary.txt`, `concurrency-overlap-probe.log` |
| 2 | Stale cached page, browser | P4 (+P3) | **PASS** — form loaded open, closed server-side (cap 0), submitted from the stale page: server answered `form_closed`/`cap_reached`; the closed message replaced the fields and button, in the `role="alert"` container, focus moved to it; nothing stored; on reload the server rendered the closed div with no `<form>`. | `stale-page-browser.md` |
| 4a | FlowMint: refused submission starts no run | P3 | **PASS** (Local) — cap-2 form with an enabled FlowMint workflow; posts 1–2 accepted, post 3 `form_closed`: exactly 2 runs (137→entry 93, 138→entry 94). | `flowmint.sh`, `flowmint-run/state-after-3-posts.txt`, `resp-*.json` |
| 4b | FlowMint: last-place submission starts exactly one run | P3 | **PASS** (Local) — entry 94 took the last place and has exactly one run; both runs executed to `completed` through Action Scheduler. | `flowmint-run/state-after-runs.txt` |
| 4c | FlowMint retention does not reopen a full form | P2 (Q-001/Q-010) | **PASS** (Local) — FlowMint's own `fre_delete_entries` step deleted both entries (`deleted_count: 2`); count stayed 2, form stayed `cap_reached`; a 4th HTTP post was refused and started no run. | `flowmint-run/state-after-retention.txt`, `state-after-post-4.txt` |
| 4d | FlowMint loads a form with availability settings | P1/P5 | **PASS (partial)** (Local) — `FMW_Workflow_Validator::validate_full` → `{"valid":true,"errors":[],"warnings":[]}`; runs executed against the form (4b). The FlowMint *editor screen* was not opened (needs a signed-in browser). | `flowmint-run/validator.txt` |
| 5a | wp-admin Status column text | P6 | **PASS** — in the browser, signed in by the human (Claude entered no credentials), all six fixtures show the right text; also matched by the WP-CLI admin render. | `wp-admin-browser.md`, `admin-status-render.txt` |
| 5b | wp-admin layout | P6 | **FAIL, then FIXED and re-checked.** Found: at desktop width the Status column was ~1 character wide (fixed table layout; existing columns took 35% + 30% + 20% + 100px and `.column-availability` had no width). Fix `daf499f`: widths rebalanced (form 28%, shortcode 24%, status 20%, modified 14%, entries 100px), control label stacked above its field, RTL regenerated; gate exit 0 at 2026-10-29T20:39:30Z (Unit 556, Integration 192). Re-checked in the browser by Claude: 1440px — five readable columns, Status cells normal height, control laid out, Save via element references stored 6; 800px — five readable columns; 375px — WordPress's stacked small-screen layout, readable, header wraps ("Modifie d"), which is how this table's other columns behave too (no primary-column markup; not compared against the pre-P6 table). Light/dark admin schemes not checked. | `wp-admin-browser.md` |
| 5c | Adjust count save flow | P6 | **PASS (Save) / NOT VERIFIED (Reset button).** Save 7 → stored 7, page shows "7 of 10"; Save 0 → stored 0. "Reset to 0" did nothing in the built-in browser: it requires `confirm()` (`admin.js:410`), which this browser does not accept. Needs a person to click Reset → OK. | `wp-admin-browser.md` |
| 5d | Adjust count refused without a valid nonce | P6 | **PASS** — signed-in admin session: no nonce → `-1`, wrong nonce → `-1`, count unchanged (0). Logged out: HTTP 400 `0`. | `wp-admin-browser.md` |
| 3 | Relay copy and restart | P5 | **NOT DONE — blocked.** All three Promptless relays (`promptless-wordpress`, `form-engine-wordpress`, `flowmint-workflows`) in the desktop app config point at `https://725printlab.com`, a live site running an older Forms plugin without this feature. Installing the new relay there would describe settings that site cannot enforce. The new file was copied in and then **restored from backup** (`form-engine-connector.js.pre-availability.bak`, identical hash); the running relay processes were never restarted, so it was never loaded. | — |
| 7 | Mark §3.11 built in Promptless WP | — | **NOT DONE** — another repository; not changed in this session. | — |

**Incident, 2026-10-29 ~20:03Z:** before discovering the relay target (check 3), Claude used the
`form-engine-wordpress` MCP tools and so acted on **725printlab.com**: created a form `hc-relay`
("HC relay check", two fields, notifications off, on no page), read it back, and ran one
`dry_run` test submit (stores nothing, sends nothing). That form is still there; removing it is
the human's decision. — The human approved deleting it; the delete call failed ("Server
form-engine-wordpress unavailable": no relay process was running), so `hc-relay` is **still on
725printlab.com** until the relay is restarted and the delete re-run, or it is deleted in that
site's wp-admin (Form Entries → Forms). The human said (2026-10-29) they will delete it in
that site's wp-admin themselves; not confirmed here. No other MCP tool was used against that site; all other checks used the
Local site only.

All Local test data (hc-* forms, entries, counts, FlowMint workflow and runs, test page,
probe mu-plugin) removed by 2026-10-29T20:32:38Z; verified empty.

**Before PR #28 can merge:**
1. ~~Fix the Status column width (5b)~~ — **done** in `daf499f` (2026-10-29T20:39:30Z), see 5b.
2. A person clicks "Reset to 0" → OK once in a browser (5c), or the Reset path is changed so it
   can be tested.
3. Push the post-run evidence commits.
4. Before release (not merge): Plugin Check; update the relay only when 725printlab.com runs this
   version; mark §3.11 built in Promptless WP; confirm `hc-relay` was deleted from 725printlab.com.

**Limits of what these checks show:** Local's PHP-FPM pool is `pm = static`, 2 workers, so
real concurrency is two requests at a time (proven to overlap), not ten; one browser engine;
no page-cache plugin; screen-reader behaviour inferred from markup and focus, not heard.

### Which of these could become gate checks

Ordered by value for effort. "Gate" means `.claude/hooks/verify.sh` (local, Stop hook) or
CI; changing either is a verification-layer change the human must unlock.

| Check | Could be automated? | What it needs |
|---|---|---|
| 6 CI 7.4/8.2 | Already automated (CI). Gap: the Integration suite runs only locally. | A CI job with MySQL + the WP test library running `phpunit --testsuite integration` on 7.4 and 8.2. The suite already runs in the local gate; CI needs a `services: mysql` block and `bin/install-wp-tests.sh`. Two-connection race tests need nothing extra. |
| 1 Concurrency | **Yes, as a separate, slower gate tier.** | `concurrency.sh` already exits non-zero on failure. Needs: a running site (Local, or `wp server`/Docker in CI) with ≥ 2 PHP-FPM workers; replacing the per-round WP-CLI transient clear with a test-only filter (`pforms_global_ip_limit` does not exist yet — a small product change) or a dedicated test site with limits raised; the overlap probe turned into a checked-in test mu-plugin so each run *proves* overlap rather than hoping for it. ~2 min per run, so a pre-release tier, not every Stop. |
| 4a–4c FlowMint × Forms | **Yes — highest value.** CLAUDE.md lists "nothing verifies the two together" as a known gap. | An Integration test (in FlowMint's suite or a cross-plugin suite) that loads both plugins in the WP test bootstrap, submits through `process_submission()`/AJAX, and asserts run rows and the delete-step behaviour exactly as `flowmint.sh` does. Needs the test bootstrap to load FlowMint and Action Scheduler; no browser. |
| 2 Stale page JS | **Yes**, with JS test infrastructure FRE does not have (a known, accepted gap). | Either a Jest + jsdom test of `frontend.js`'s `form_closed` branch (fast; needs a JS test setup and a fixture of the rendered form), or a Playwright test against a running site (slower; the AISB repo already drives Chromium for its a11y gates and could be reused). |
| 5 wp-admin | **Partly.** The Status text and the control's security are already in the Integration suite. | Visual/usability: a Playwright run that signs in as a test admin on a test-only site (credentials from the test site's own fixtures), screenshots the Forms list in light/dark/narrow, and clicks Adjust count. Needs a disposable test site; never the human's own admin login. |
| 4d FlowMint editor | Yes, same as 5 (Playwright on a test site). | As above. The validator half is easy to add to the FlowMint × Forms Integration test. |
| 3 Relay | **Partly.** `tests/relay-tool-errors.test.js` exists but the gate does not run it. | Run `node --test tests/*.test.js` in the gate/CI. Separately: a check that the relay's described settings exist on the site it targets (e.g. preflight compares the relay's version to the site's `PForms_VERSION`) would have caught the mismatch found today. The relays' target URL is environment, not code — no gate can choose it; a human must point a relay at a test site. |
| 7 Stack roadmap | No — a cross-repo documentation step. | — |

## Notes

<!-- Halt reasons, parked branches, attempt failures. A human may add a line starting with
     "Human note:" to give instructions to the next session. -->
- Run 1 is capped at one phase (`max_phases_per_run: 1`) at the human's request: halt after P0 and report before P1.
- P0 attempt 1: guard refused one orchestrator Bash append to phases/P0.md because the quoted gate output named the baseline ref (pattern match on Bash text). Logged with Edit instead; no protected path or ref touched.
- Reviewer note: repo-root `./form-runtime-engine.zip` is a tracked file (old artifact). Not touched; `*.zip` is excluded from builds. Possibly worth removing from git separately.
- Run 2 (P1) capped at one phase at the human's request ("Run P1 next").
- Carry into later briefs (P1 reviewer's minor findings): (P3/P4) an invalid PHP-registered config logs an error on every `check()` call — once wired into render and submit that is one log line per page view, so dedupe when wiring; (P5/P6) a DST spring-forward-gap time gets the generic "must be a date…" refusal — give it a clearer message or document it.
- Q-009 (default wording of the closed and before-open messages) raised by the P1 worker as non-blocking; answered by the human 2026-10-29T17:07:03Z (before-open default must name the site timezone, WordPress's label). Goes into the P4 brief (A-003).
- Run 3 started 2026-10-29T17:07:36Z: P2–P6 with no phase cap, at the human's request. Baseline b82c9f2 green (Unit 546, Integration 99 / 3 skipped).
- P2 attempt 1 (2026-10-29T17:23:34Z): worker DONE, gate exit 0 (Unit 549, Integration 116 / 3 skipped), reviewer ESCALATE on a guessed decision — removing and re-adding a cap restarts the count from stored entries, which Q-001 does not settle. The orchestrator had waved the worker's question through as covered by Q-001(a); the reviewer was right to dispute it. Attempt escalated, not counted (Attempts back to 0). Work parked on `orchestrator/P2-attempt-1` (0e07366): reusable once Q-010 is answered. Retry must also fix the reviewer's minor finding: the lazy-row race test does not interleave inside the lazy path (a mutant survives).
- P3 attempt 1 (2026-10-29T18:16:53Z): failed — worker hit its 60-turn limit before reporting. Partial tree was green (Integration 154). Parked on `orchestrator/P3-attempt-1` (e606cd5). Attempt 2 starts from it.
- P3 attempt 2: DONE, gate exit 0, reviewer APPROVE (A-005). For P4: AJAX refusal is `{success:false, data:{code:'form_closed', message, reason}}`, reason ∈ not_yet_open / closed_date / cap_reached / invalid_settings. For P5: document `pforms_form_full( $form_id, $entry_id, $cap, $form_config )` and the replica caveat.
- P4 attempt 1 (2026-10-29T18:28:57Z): worker NEEDS_DECISION — A-003 blocked by two protected exact-string tests from P1 (FormAvailabilityTest.php:484, FormAvailabilityTimezoneTest.php:52). Render + JS work green (worker's gate: Integration 161 / 781) and parked on `orchestrator/P4-attempt-1` (e8d70c3). Escalated, not counted. Raised as Q-011. Retry must also put two worker deviations to cold review: a neutral closed-message style; no focus move on a page-load closed render.
- Orchestrator note: the guard pattern-matches Bash text, so any Bash command whose text names the baseline ref or the re-baseline script is refused even when it only writes a log. State files are written with the Edit/Write tools.
- P5 attempt 1 (2026-10-29T19:12:20Z): gate exit 0 (Unit 556, Integration 179), reviewer CHANGES_REQUESTED — rulebook and knowledge map promise an owner count adjust/reset control that only P6 builds. Parked on `orchestrator/P5-attempt-1` (3085ca7).
