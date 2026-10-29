# Orchestrator state

Run status: PAUSED   <!-- RUNNING | PAUSED | HALTED: QUESTION | HALTED: BLOCKED | DONE -->
Updated: 2026-10-29T17:00:25Z
Last green commit: (P1 commit — recorded below)
Next action: Paused after P1 by `max_phases_per_run: 1` (human checkpoint, run 2). To continue, the human raises or removes `max_phases_per_run` in ROADMAP.md and runs the orchestrator; it starts P2 attempt 1. Q-009 (default message wording) is open but does not block P2 or P3; answer it before P4.

| Phase | Status | Attempts | Base commit | Gate | Review | Commit |
|---|---|---|---|---|---|---|
| P0 | done | 1 | 2a859bb | pass (exit 0) | APPROVE | 1b0c77d |
| P1 | done | 1 | 30340a6 | pass (exit 0) | APPROVE | (pending) |
| P2 | pending | 0 | | | | |
| P3 | pending | 0 | | | | |
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

**Reviewer judgment** (a model read the code; not proof):
- P0: the release ZIP no longer contains `.orchestrator/`; the file list is otherwise identical to a b3579ee build (119 vs 119, empty names-only diff); the old script did ship 6 `.orchestrator` entries, so the fix is not vacuous. Reproduced independently by the reviewer in scratch copies; the worker's own build showed the same.
- P1: every done-state criterion met. The reviewer mutated a scratch copy to check the tests can fail for the reason they name: comparing in UTC or in server time fails 7 tests each; 0-as-unlimited fails 3; removing the filter fails both filter tests; disabling the save-time validator fails the admin and both connector path tests; Q-007 boundary mutations fail 2/5/4 tests. Both "save path" tests go through the real `save_form` and connector handlers.

**Not verified:**
- Plugin Check on the new ZIP was not run (not in P0's done-state; it is a release gate).
- The ZIP check is not in the verify gate: a later edit to bin/build-release.sh could re-ship `.orchestrator/` and nothing automated would notice.
- P1: no test date falls on a DST transition; a local time inside a spring-forward gap is refused with a misleading generic message (reviewer finding, untested).
- P1: nothing enforces availability yet. `check()` has no production callers until P3/P4; the cap uses a stubbed count of 0 until P2.
- P1: PHP 7.4/8.2 — new files lint clean on Local's 7.4.1 (worker and reviewer, by hand); the Unit suite has not run on 7.4/8.2 (CI after push).

## Notes

<!-- Halt reasons, parked branches, attempt failures. A human may add a line starting with
     "Human note:" to give instructions to the next session. -->
- Run 1 is capped at one phase (`max_phases_per_run: 1`) at the human's request: halt after P0 and report before P1.
- P0 attempt 1: guard refused one orchestrator Bash append to phases/P0.md because the quoted gate output named the baseline ref (pattern match on Bash text). Logged with Edit instead; no protected path or ref touched.
- Reviewer note: repo-root `./form-runtime-engine.zip` is a tracked file (old artifact). Not touched; `*.zip` is excluded from builds. Possibly worth removing from git separately.
- Run 2 (P1) capped at one phase at the human's request ("Run P1 next").
- Carry into later briefs (P1 reviewer's minor findings): (P3/P4) an invalid PHP-registered config logs an error on every `check()` call — once wired into render and submit that is one log line per page view, so dedupe when wiring; (P5/P6) a DST spring-forward-gap time gets the generic "must be a date…" refusal — give it a clearer message or document it.
- Q-009 (default wording of the closed and before-open messages) raised by the P1 worker as non-blocking; open. Needed before P4.
