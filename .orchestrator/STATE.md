# Orchestrator state

Run status: RUNNING   <!-- RUNNING | PAUSED | HALTED: QUESTION | HALTED: BLOCKED | DONE -->
Updated: 2026-10-29T18:16:53Z
Last green commit: ef46c13
Next action: Run 3. P3 attempt 2 is with the phase-worker, starting from the parked attempt-1 work.

| Phase | Status | Attempts | Base commit | Gate | Review | Commit |
|---|---|---|---|---|---|---|
| P0 | done | 1 | 2a859bb | pass (exit 0) | APPROVE | 1b0c77d |
| P1 | done | 1 | 30340a6 | pass (exit 0) | APPROVE | d2cb13f |
| P2 | done | 1 | 1464b25 | pass (exit 0) | APPROVE | ef46c13 |
| P3 | in_progress | 2 | 168fef3 | | | |
| P4 | pending | 0 | | | | |
| P5 | pending | 0 | | | | |
| P6 | pending | 0 | | | | |

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

**Reviewer judgment** (a model read the code; not proof):
- P0: the release ZIP no longer contains `.orchestrator/`; the file list is otherwise identical to a b3579ee build (119 vs 119, empty names-only diff); the old script did ship 6 `.orchestrator` entries, so the fix is not vacuous. Reproduced independently by the reviewer in scratch copies; the worker's own build showed the same.
- P1: every done-state criterion met. The reviewer mutated a scratch copy to check the tests can fail for the reason they name: comparing in UTC or in server time fails 7 tests each; 0-as-unlimited fails 3; removing the filter fails both filter tests; disabling the save-time validator fails the admin and both connector path tests; Q-007 boundary mutations fail 2/5/4 tests. Both "save path" tests go through the real `save_form` and connector handlers.
- P2: every criterion and the Q-010 behaviour met. Two independent reviewers reproduced the naive count-then-insert failure themselves; the retry reviewer also killed mutants for the overwriting seed (4 failures), claim(null) as a no-op (3), deleting the count on a cap-less save (3), a no-op form-delete hook (1) and a missing release floor (risky = fail).

**Not verified:**
- Plugin Check on the new ZIP was not run (not in P0's done-state; it is a release gate).
- The ZIP check is not in the verify gate: a later edit to bin/build-release.sh could re-ship `.orchestrator/` and nothing automated would notice.
- P1: no test date falls on a DST transition; a local time inside a spring-forward gap is refused with a misleading generic message (reviewer finding, untested).
- P1: nothing enforces availability yet. `check()` has no production callers until P3/P4; the cap uses a stubbed count of 0 until P2.
- P1: PHP 7.4/8.2 — new files lint clean on Local's 7.4.1 (worker and reviewer, by hand); the Unit suite has not run on 7.4/8.2 (CI after push).
- P2: the race tests interleave at statement level on two connections; InnoDB row-lock waiting and real concurrent PHP-FPM requests are not exercised (human curl check, roadmap item 1).
- P2: nothing claims places yet; the handler wiring (claim before store, release on failure, never on spam/dry run) is P3.
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
