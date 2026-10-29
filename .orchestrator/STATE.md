# Orchestrator state

Run status: RUNNING   <!-- RUNNING | PAUSED | HALTED: QUESTION | HALTED: BLOCKED | DONE -->
Updated: 2026-10-29T19:20:15Z
Last green commit: aa60815
Next action: Run 3. P6 attempt 1 is with the phase-worker.

| Phase | Status | Attempts | Base commit | Gate | Review | Commit |
|---|---|---|---|---|---|---|
| P0 | done | 1 | 2a859bb | pass (exit 0) | APPROVE | 1b0c77d |
| P1 | done | 1 | 30340a6 | pass (exit 0) | APPROVE | d2cb13f |
| P2 | done | 1 | 1464b25 | pass (exit 0) | APPROVE | ef46c13 |
| P3 | done | 2 | 168fef3 | pass (exit 0) | APPROVE | b289ba9 |
| P4 | done | 1 | c226cc6 | pass (exit 0) | APPROVE | 9f66955 |
| P5 | done | 2 | a937345 | pass (exit 0) | APPROVE | aa60815 |
| P6 | in_progress | 1 | 394dceb | | | |

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

**Reviewer judgment** (a model read the code; not proof):
- P0: the release ZIP no longer contains `.orchestrator/`; the file list is otherwise identical to a b3579ee build (119 vs 119, empty names-only diff); the old script did ship 6 `.orchestrator` entries, so the fix is not vacuous. Reproduced independently by the reviewer in scratch copies; the worker's own build showed the same.
- P1: every done-state criterion met. The reviewer mutated a scratch copy to check the tests can fail for the reason they name: comparing in UTC or in server time fails 7 tests each; 0-as-unlimited fails 3; removing the filter fails both filter tests; disabling the save-time validator fails the admin and both connector path tests; Q-007 boundary mutations fail 2/5/4 tests. Both "save path" tests go through the real `save_form` and connector handlers.
- P2: every criterion and the Q-010 behaviour met. Two independent reviewers reproduced the naive count-then-insert failure themselves; the retry reviewer also killed mutants for the overwriting seed (4 failures), claim(null) as a no-op (3), deleting the count on a cap-less save (3), a no-op form-delete hook (1) and a missing release floor (risky = fail).
- P4: every criterion met by reading; render tests sound; JS `form_closed` branch cannot be pre-empted and reuses the success confirmation's live region and focus; offset rewrite checked by the reviewer on PHP 8.5.5 for +05:45, -09:30, -03:30, -00:30, +14:00, +00:00.
- P3: every criterion met on both paths. A positive-control test proves the "no side effect" spies move on an accepted submission. Reviewer mutants: dropping the release from send_error → 3 failures; moving the availability check after validation → 1 failure + 2 errors. Reviewer's own two-connection test showed take()'s LAST_INSERT_ID identifies only the true last-place request.
- P5: every claim in the rulebook, schema, knowledge map, CONNECTOR_SPEC, CLAUDE.md and relay descriptions checked against the code by two reviewers; attempt 1 was refused for promising the unbuilt count control, attempt 2 approved. Drift tests fail on a key rename (reviewer mutants).

**Not verified:**
- Plugin Check on the new ZIP was not run (not in P0's done-state; it is a release gate).
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
