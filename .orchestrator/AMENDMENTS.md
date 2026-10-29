# Plan amendments

<!-- Every accepted deviation from ROADMAP.md, appended by the orchestrator. Never edited after
     the fact. A deviation that isn't written down here is drift. -->

## A-001 · P0 · 2026-10-29T16:41:08Z

**Plan said:** "`./bin/build-release.sh` produces a ZIP whose listing (`unzip -l build/form-runtime-engine.zip`) contains no path containing `.orchestrator`."
**Changed to:** The check was run on `build/promptless-forms.zip`.
**Because:** The build script names the ZIP after the plugin slug, not the repo folder (bin/build-release.sh:24 `PLUGIN_SLUG="promptless-forms"`, :28 `ZIP_NAME="${PLUGIN_SLUG}.zip"`); `build/form-runtime-engine.zip` is never produced. The roadmap's path was the planner's error. Both comparison ZIPs share the root folder `promptless-forms/`.
**Proposed by:** worker · **Accepted by:** reviewer (APPROVE) · **Commit:** (P0 commit, recorded in STATE.md)
