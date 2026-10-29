# Plan amendments

<!-- Every accepted deviation from ROADMAP.md, appended by the orchestrator. Never edited after
     the fact. A deviation that isn't written down here is drift. -->

## A-001 · P0 · 2026-10-29T16:41:08Z

**Plan said:** "`./bin/build-release.sh` produces a ZIP whose listing (`unzip -l build/form-runtime-engine.zip`) contains no path containing `.orchestrator`."
**Changed to:** The check was run on `build/promptless-forms.zip`.
**Because:** The build script names the ZIP after the plugin slug, not the repo folder (bin/build-release.sh:24 `PLUGIN_SLUG="promptless-forms"`, :28 `ZIP_NAME="${PLUGIN_SLUG}.zip"`); `build/form-runtime-engine.zip` is never produced. The roadmap's path was the planner's error. Both comparison ZIPs share the root folder `promptless-forms/`.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P0 commit, recorded in STATE.md)

## A-002 · P1 · 2026-10-29T17:00:25Z

**Plan said:** "Names, nesting and the exact signature are the worker's call." / "No input other than 'absent' (or the explicit unlimited value, if one is chosen and documented) produces unlimited."
**Changed to:** Not a departure from the plan, recorded because later phases must build on these calls: settings live in `settings.availability` (`opens_at`, `closes_at`, `max_entries`, `closed_message`, `before_open_message`); the decision point is `PForms_Availability::check( $form, $context )`, filtered by `pforms_form_availability`, with reasons `open`, `not_yet_open`, `closed_date`, `cap_reached`, `invalid_settings`, `form_not_found`; (a) JSON `null` is the explicit "not set"/unlimited value; (b) unknown keys and empty strings inside the block are refused; (c) close-before-open compares resolved moments, so a same-day date-only window is valid; (d) an invalid PHP-registered config fails closed with `invalid_settings`; (e) checks run before-open → after-close → cap.
**Because:** The spec left these open; each is documented in the class docblock and pinned by tests (includes/Core/class-fre-availability.php; tests/Unit/FormAvailabilityTest.php).
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P1 commit, recorded in STATE.md)

## A-003 · P4 · 2026-10-29T17:07:03Z

**Plan said:** P4 done-state does not mention the default message wording; P1 shipped the before-open default "This form opens on %1$s at %2$s." with no timezone.
**Changed to:** P4 also makes the default before-open message append the zone label WordPress gives for the site timezone (e.g. "This form opens on March 15, 2027 at 9:00 am EDT"), pinned by a test with a non-UTC site zone. The closed default is unchanged.
**Because:** Human decision on Q-009 (answered 2026-10-29T17:07:03Z): "a time without a zone is ambiguous for anyone outside the site's timezone, and these are deadline-driven forms."
**Proposed by:** human (Q-009) · **Accepted by:** human · **Commit:** (P4 commit, recorded in STATE.md)

## A-004 · P2 · 2026-10-29T18:00:30Z

**Plan said:** "How the count is stored is open"; "Uninstall removes whatever P2 stores"; seeding "happens where forms are saved".
**Changed to:** `PForms_Place_Counter` stores one wp_options row `pforms_places_{form_id}` (md5 for IDs over 150 chars), autoload 'no', SQL through `$wpdb` only. DB forms are seeded on `pforms_form_saved` and their count removed on `pforms_form_deleted`; PHP-registered forms get their row lazily at their first capped claim (INSERT IGNORE). Uninstall removes counts only with the "Remove all data" opt-in, like forms. Q-010 counting is `claim($id, null)`: one statement per accepted submission, a no-op on forms that never had a cap. Declared gap: a PHP-registered form whose cap is removed in code before its first submission keeps no count while uncapped, and is seeded at its first capped claim rather than at cap-add.
**Because:** no schema change or DB_VERSION bump; the repository's only save/delete points fire those actions and Unit tests stub SQL in save(); default uninstall keeps forms, so dropping counts would reopen full forms on reinstall; the system cannot see a code-only cap without writing on every registration.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P2 commit, recorded in STATE.md)

## A-005 · P3 · 2026-10-29T18:23:55Z

**Plan said:** (P2 binding note) "call claim($form_id, $cap_or_null) on EVERY accepted submission"; done-state: "A spam-caught (honeypot) submission … still receives the normal fake-success response it gets today."
**Changed to:** capped forms take a place with new `PForms_Place_Counter::take($form_id, $cap)`, which returns the count its own atomic UPDATE produced (`LAST_INSERT_ID(expr)`), so `pforms_form_full` fires on exactly the request that took the last place; uncapped forms call `claim($form_id, null)`; `claim()` with a cap delegates to `take()`. The availability check runs before the spam check, so a honeypot submission to an already-closed form gets the closed message and nothing is stored (fake success and spam-entry keeping are unchanged on open forms). An exact repeat within the duplicate window of a submission already received is still answered with its success.
**Because:** Q-003 needs the last-place request identified exactly; a bool claim plus a later read could see another request's claim. A closed form must create "no entry row", and a kept spam entry is one; on a closed form every visitor gets the same answer, so nothing about detection leaks.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P3 commit, recorded in STATE.md)

## A-006 · P1/P4 · 2026-10-29T18:51:29Z

**Plan said:** P1's tests pinned the before-open default as an exact sentence (`tests/Unit/FormAvailabilityTest.php:484`, `tests/Integration/FormAvailabilityTimezoneTest.php:52`, `assertSame( 'This form opens on March 15, 2027 at 9:00 am.' )`).
**Changed to:** both now `assertStringStartsWith( 'This form opens on March 15, 2027 at 9:00 am' )`. The exact text with the timezone label (A-003) is pinned by P4's own tests.
**Because:** Human answer to Q-011 (option 1). The tests were locked; the edit was made by the main session in a turn the human unlocked by typing `UNLOCK-VERIFICATION`, committed as c226cc6, and the verification baseline moved to c226cc6 (logged by the guard). Gate after the move: exit 0 (Unit 549; Integration 154 / 3 skipped).
**Proposed by:** worker (P4 attempt 1, NEEDS_DECISION) · **Accepted by:** human (Q-011) · **Commit:** c226cc6

## A-007 · P4 · 2026-10-29T18:59:56Z

**Plan said:** the closed message is "announced … the way the success confirmation already is (same live-region / focus treatment)"; A-003 "use whatever zone label WordPress gives".
**Changed to:** a new neutral `.fre-form__message--closed` style (existing `--fre-*` properties only); a closed form rendered at page load uses the confirmation's container and state but does not move focus (the stale-page path does, via `showConfirmation()`); an offset-shaped zone label (PHP's "+05:30" / "GMT+0530" / "-03") is rewritten as "UTC±H[:MM]" ("UTC" for +0); the closed render bypasses the `pforms_rendered_form` filter.
**Because:** the closed state is neither success nor error; moving focus before the visitor acts pulls them away from where they are; PHP has no abbreviation for offset zones and its raw output varies by version; the filter has no known consumer.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P4 commit, recorded in STATE.md)

## A-008 · P5 · 2026-10-29T19:19:47Z

**Plan said:** P5's surfaces are the rulebook, schema, knowledge map, CONNECTOR_SPEC, CLAUDE.md and relay descriptions; get_form reads back availability.
**Changed to:** the connector's `handle_submit` now merges its 400 status into existing error data instead of replacing it, so `form_closed` data (form_id, reason, opens_at, closes_at) and `validation_failed`'s `field_errors` reach REST callers, as CONNECTOR_SPEC already promised. `availability` is returned on get_form and list_forms, not on create/update responses; agents are told to read back with get_form. A DST spring-forward-gap time gets its own validator message. Docs make no claim about an owner count control until P6 builds it.
**Because:** the old replace silently dropped documented error data; a committed Unit test drives handle_update_form with a stub `$wpdb` that cannot read the count; the P1 reviewer flagged the misleading DST message; attempt 1's claim of an unbuilt control was refused in review.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE, attempt 2) · **Commit:** (P5 commit, recorded in STATE.md)

## A-009 · P6 · 2026-10-29T19:29:28Z

**Plan said:** reuse P4's zone-label logic (A-003); add admin actions that check `pforms_manage_forms` and a nonce.
**Changed to:** `PForms_Availability::timezone_label()` made public (behaviour unchanged) so the Forms list reuses it. The new `pforms_set_place_count` AJAX handler answers refusals with HTTP 403/400/404/500 (existing Forms Manager handlers answer 200). The Status cell also shows "N counted (no entry limit set)" for a form whose cap was removed but still counts (Q-010), and "Closed: by a custom rule (<reason>)" for a filter's reason; the page reloads after a successful count save.
**Because:** the label helper was private; proper status codes for refusals, which admin.js reads from responseJSON; Q-010 makes an uncapped count real and adjustable.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P6 commit, recorded in STATE.md)
