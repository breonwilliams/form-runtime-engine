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
