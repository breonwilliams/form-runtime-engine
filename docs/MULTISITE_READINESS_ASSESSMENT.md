# Multisite Readiness Assessment — Promptless Plugin Suite

**Date:** 2026-06-16
**Scope:** Form Runtime Engine (Promptless Forms / `PForms_*`), FlowMint Workflows (`FMW_*`), Post Runtime Engine (`PCPTPages_*`), Promptless WP / AI Section Builder Modern (`AISB_*` / `pw_fs`).
**Trigger:** A new subsite on a WordPress network surfaced "Database tables are missing" from Promptless Forms. This is a class of defect (per-site initialization that assumes single-site), so the whole suite was audited rather than patching one site.
**Principle:** Fix in the plugin to WordPress best practices, for the benefit of all users. No site-specific workarounds.

---

## 1. Findings summary

| Plugin | Custom tables | Multisite-aware | New-subsite result | Severity |
|---|---|---|---|---|
| **Promptless Forms (FRE)** | 4 (`fre_entries`, `fre_entry_meta`, `fre_entry_files`, `fre_webhook_log`) | None | **Broken** — tables never created; `init()` only detects + warns | **High (blocker)** |
| **FlowMint Workflows** | 3 (`fmw_workflows`, `fmw_workflow_runs`, `fmw_workflow_run_steps`) | None | Latently self-heals on load **only if** the Promptless Forms dependency is already loaded on that subsite; teardown leaks tables | **Med-High** |
| **Post Runtime Engine** | None (options + post meta) | None explicit | Works gracefully (`init`-driven CPT/rewrite; caps self-heal via per-request upgrade) | **Low (polish)** |
| **Promptless WP (AISB)** | None | **Yes** — hooks `wp_initialize_site` / `wp_uninitialize_site`, per-site settings | Works out of the box | **Low (polish) + Freemius** |

**Reference implementation:** Promptless WP already does this correctly (`includes/Plugin.php:68-113`): `init_multisite_support()` guards on `is_multisite()`, provisions new subsites via `wp_initialize_site`, cleans up via `wp_uninitialize_site`, and keeps all settings per-site. The fix for the others is to bring them up to this standard.

---

## 2. The standardized fix pattern

Every plugin that does per-site setup (tables, capabilities, upload dirs, cron) must initialize **per site**, not once globally. The canonical pattern:

1. **One provisioning routine** — `provision_site()` that does all per-site setup idempotently (create/migrate tables, grant caps, create dirs, schedule cron).
2. **Activation is network-aware:**
   ```php
   public function activate( $network_wide = false ) {
       if ( is_multisite() && $network_wide ) {
           foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
               switch_to_blog( $blog_id );
               $this->provision_site();
               restore_current_blog();
           }
       } else {
           $this->provision_site();
       }
   }
   ```
3. **New subsites are provisioned on creation** — hook `wp_initialize_site` (modern; `wpmu_new_blog` is deprecated since WP 5.1):
   ```php
   add_action( 'wp_initialize_site', function ( $new_site ) {
       if ( ! is_plugin_active_for_network( plugin_basename( PLUGIN_FILE ) ) ) { return; }
       switch_to_blog( $new_site->id );
       $this->provision_site();
       restore_current_blog();
   }, 100 );
   ```
4. **Version-gated self-heal on load** — keep a cheap `get_option(db_version)` compare on `plugins_loaded`/`init` that runs `provision_site()` when behind. Idempotent; also covers plugin updates, imported sites, and plugins activated before multisite was enabled. (FlowMint has this; FRE lacks it; it must not be gated behind unrelated dependency checks.)
5. **Teardown is network-aware** — `wp_uninitialize_site` and `uninstall.php` loop subsites (`get_sites()` + `switch_to_blog()`), or drop per-site tables/options for each, so no orphan `wp_N_*` tables/options/caps remain.
6. **Correct scope** — per-site tables use `$wpdb->prefix` (already correct everywhere); per-site options stay `get_option`; only genuinely network-global state uses `get_site_option`.

---

## 3. Per-plugin work

### 3.1 Promptless Forms (FRE) — HIGH, blocker, do first (template)
Evidence: `form-runtime-engine.php:107` (activation hook), `:139-158` (`activate()` runs `PForms_Migrator->run_migrations()` for current site only, ignores `$network_wide`), `:363-410` (`check_database_health()` only *detects + warns*, never creates). No multisite primitives anywhere.
Required: implement the full pattern §2.1–§2.5. Specifically — refactor `activate()` to `provision_site()` + network loop; add `wp_initialize_site`; add a version-gated self-heal so `init()` *creates* missing tables instead of only warning; route caps grant, upload-dir creation, Twilio migrations, and webhook cron through `provision_site()`; make `uninstall.php` loop subsites.

### 3.2 FlowMint Workflows — Med-High
Evidence: `flowmint-workflows.php:384-401` (`activate()` ignores `$network_wide`), `:316-330` (`maybe_run_db_migration()` self-heal on `plugins_loaded`), `:170-176` (self-heal gated behind `dependencies_met()` → never provisions if Promptless Forms isn't loaded on that subsite), `uninstall.php:19-78` (single-site teardown).
Required: same network-aware `activate()` + `wp_initialize_site`; **decouple table/cap provisioning from the FRE-dependency gate** (provision tables regardless; only the workflow *runtime* needs the dependency); network-aware uninstall/deactivate. Keep the load-time self-heal as the fallback.

### 3.3 Post Runtime Engine — Low (polish)
Evidence: no custom tables; CPTs register on `init` with transient-driven rewrite flush (`class-pre-cpt-registry.php:257-286`); caps self-heal via per-request `maybe_run_data_upgrade()` (`post-runtime-engine.php:505-531`). `on_activation()` ignores `$network_wide` (`:466`).
Required (non-blocking): make `on_activation()` network-aware to pre-seed options/caps across existing sites; optionally add `wp_initialize_site` for parity. Functionally already fine on new subsites — schedule after the blockers.

### 3.4 Promptless WP (AISB) — Low (polish) + Freemius (external)
Evidence: already multisite-aware (`Plugin.php:68-113`). Minor: default-value drift between `Activator::activate()` (`Activator.php:30-43`) and `on_new_site_created()` (`Plugin.php:94-102`); `aisb_business_settings` not seeded on new subsites (fails safe). Activation cron/cap not per-subsite (harmless; self-seeds).
Required (polish): unify the two default-settings sources into one method; seed business settings on new subsites.
**Freemius licensing (NOT a code fix — external decision):** seat counting is enforced by Freemius's server, not the code. The plugin ships the stock SDK with no network config (`ai-section-builder-modern.php:39-56`) and gates per-site via `can_use_premium_code()`. Under defaults, each subsite likely consumes a license seat → ~20 demo subsites could exhaust the agency cap. **Action: confirm in the Freemius dashboard for plugin ID `22546` whether the agency license is a multisite/network license and how subsites are counted.** This directly constrains how many demo subsites the network can hold under the current license.

---

## 4. Test plan

Each fix ships with tests (per suite guardrails). The WP PHPUnit framework runs multisite tests independent of whether the dev's Local site is multisite.

- **Unit/Integration (PHPUnit, `@group ms-required`):**
  - Network-activate provisions tables + caps for **all existing** sites.
  - `wp_initialize_site` provisions a **newly created** subsite (`wp_insert_site` / `wpmu_create_blog`) → assert all tables exist with `wp_N_` prefix and admin has the capability.
  - Version-gated self-heal: simulate a site with missing `db_version` → first load creates tables.
  - Teardown: `wp_delete_site` / uninstall removes per-site tables (no orphans).
  - Single-site regression: non-multisite activation still provisions correctly.
- **Syntax lint:** `php -l` on changed files (run in Local; sandbox has no PHP).
- **Real-world validation (Bluehost network):** after packaging + deploying, create a fresh subsite and confirm tables + caps appear with no admin notice.

**Local Terminal commands (run from each plugin root):**
```bash
# one-time test DB setup
bin/install-wp-tests.sh wordpress_test root '' localhost latest
composer install
# run suite in multisite mode
WP_MULTISITE=1 vendor/bin/phpunit
# single-site regression
vendor/bin/phpunit
```

---

## 5. Implementation order & release

1. **Promptless Forms (FRE)** — blocker + template. Bump `PForms_DB_VERSION`? No (schema unchanged); bump plugin version. Release via `RELEASE.md`.
2. **FlowMint Workflows** — same pattern; bump plugin version (DB unchanged).
3. **PRE** — polish; batch with a future release.
4. **Promptless WP** — settings-default unification; batch with a future release.
5. **Freemius dashboard check** — Breon, in parallel; gates demo-network scale.

Each plugin releases independently through its own `RELEASE.md` (version stamps → commit → tag → `bin/build-release.sh` → `gh release create`). After release, update the multisite network: deactivate/replace the plugin zip, reactivate network-wide, confirm a fresh subsite provisions cleanly.
