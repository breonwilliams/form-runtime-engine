<?php
/**
 * Plugin upgrade handler for Promptless Forms.
 *
 * Runs on every `plugins_loaded` to detect when the plugin's code version
 * (PForms_VERSION) differs from the version stored in the database
 * (`pforms_plugin_version` option). When it detects a difference, it runs any
 * required upgrade routines and updates the stored version.
 *
 * This is distinct from PForms_Migrator, which handles *database schema* versioning
 * via the `pforms_db_version` option. Those concerns are orthogonal: the schema may
 * change within a plugin version, and the plugin may release updates that don't
 * touch the schema.
 *
 * Upgrade routines handled here:
 *   - Capability grant (idempotent; safe to run on every upgrade)
 *   - Future: data migrations, option-format changes, feature-flag bootstrapping
 *
 * Design notes:
 *   - Activation-hook-based upgrades are unreliable because WordPress does not
 *     always re-activate a plugin on update (depends on the update path used).
 *     `plugins_loaded` + version-compare is the pattern used by WooCommerce,
 *     Yoast, Gravity Forms, and other mature plugins.
 *   - On fresh installs, the activation hook sets the stored version so the
 *     first `plugins_loaded` call after activation is a no-op.
 *   - Downgrades (stored > current) are detected and log a warning but do not
 *     run any routines — downward migration is out of scope.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Plugin version upgrader.
 */
class PForms_Upgrader {

    /**
     * Option key that stores the last-seen plugin version.
     *
     * @var string
     */
    const VERSION_OPTION = 'pforms_plugin_version';

    /**
     * Detect whether the plugin's code version differs from the stored version.
     *
     * Safe to call on every `plugins_loaded` — does nothing on hot paths when
     * versions already match.
     */
    public static function maybe_upgrade() {
        // One-time prefix migration (1.8.0): copy any options stored under the
        // legacy `fre_` prefix to the new `pforms_` prefix. Must run BEFORE the
        // version check below, because the version marker itself was renamed
        // (`fre_plugin_version` → `pforms_plugin_version`). Without this, a site
        // upgrading from <1.8.0 would read an empty new version marker, mistake
        // itself for a fresh install, and skip the upgrade routine (capability
        // grant, etc.). Gated by a single get_option so it's a no-op on the hot
        // path once the legacy marker is gone.
        if ( null !== get_option( 'fre_plugin_version', null ) ) {
            self::migrate_legacy_prefix_options();
        }

        $stored  = get_option( self::VERSION_OPTION, '' );
        $current = defined( 'PForms_VERSION' ) ? PForms_VERSION : '0.0.0';

        // Fresh install: no stored version. Stamp it and run fresh-install routine.
        if ( '' === $stored ) {
            self::on_fresh_install();
            update_option( self::VERSION_OPTION, $current );
            return;
        }

        // Same version: nothing to do. Hot path.
        if ( version_compare( $stored, $current, '=' ) ) {
            return;
        }

        // Downgrade: log and bail. Don't rewrite stored version — preserves the
        // fact that the site was once at a higher version, so re-upgrading
        // doesn't re-trigger upgrade routines.
        if ( version_compare( $stored, $current, '>' ) ) {
            if ( class_exists( 'PForms_Logger' ) ) {
                PForms_Logger::warning(
                    sprintf(
                        'Promptless Forms downgrade detected: stored version %s > plugin version %s. No routines run.',
                        $stored,
                        $current
                    )
                );
            }
            return;
        }

        // Upgrade: stored < current.
        self::on_upgrade( $stored, $current );
        update_option( self::VERSION_OPTION, $current );
    }

    /**
     * Migrate option keys from the legacy `fre_` prefix to `pforms_`.
     *
     * Runs once when a site upgrades from a pre-1.8.0 version (detected by the
     * presence of the legacy `fre_plugin_version` option). The plugin renamed
     * its entire symbol/option surface from the 3-character `fre` prefix to
     * `pforms` to satisfy WordPress.org's 4-character prefix requirement; this
     * routine carries each persisted option across so existing sites keep their
     * saved forms, settings, API keys, and connector state.
     *
     * Idempotent: each key is copied only if the source exists and the target
     * does not, then the source is deleted. After the run, the legacy
     * `fre_plugin_version` marker is gone, so the gate in maybe_upgrade() skips
     * this method on every subsequent load.
     *
     * Custom DB tables (wp_fre_entries, wp_fre_twilio_clients, etc.) are NOT
     * renamed — table names are not subject to the prefix rule and renaming
     * them would require a destructive ALTER. Transients are not migrated —
     * they self-heal (the code looks up the new key, misses, regenerates; old
     * ones expire on their own).
     */
    private static function migrate_legacy_prefix_options() {
        // Sentinel distinguishes "option absent" from a legitimately-stored
        // false/empty/0 value.
        $sentinel = '__pforms_option_absent__';

        // Legacy option keys (without the prefix). Each migrates from
        // 'fre_' . $key to 'pforms_' . $key.
        $keys = array(
            'plugin_version',
            'db_version',
            'migration_error',
            'email_failures',
            'client_forms',
            'connector_call_log',
            'honeypot_secret',
            'failed_email_queue',
            'quarantine_suffix',
            'twilio_settings',
            'twilio_db_version',
            'twilio_migration_error',
            'connector_enabled',
            'connector_entry_read_enabled',
            'google_places_api_key',
            'form_config_errors',
        );

        foreach ( $keys as $key ) {
            $old_key = 'fre_' . $key;
            $new_key = 'pforms_' . $key;

            $old_value = get_option( $old_key, $sentinel );
            if ( $sentinel === $old_value ) {
                continue; // Legacy option never existed on this site.
            }

            // Copy to the new key only if it isn't already populated (protects
            // against clobbering a value written by the new code before this
            // migration ran).
            if ( $sentinel === get_option( $new_key, $sentinel ) ) {
                update_option( $new_key, $old_value );
            }

            delete_option( $old_key );
        }

        // Revoke the legacy `fre_manage_forms` capability from all roles. The
        // new `pforms_manage_forms` capability is granted by
        // grant_default_capabilities() during the upgrade routine that runs
        // right after this migration, so admins keep access without
        // interruption; this just clears the orphaned old grant.
        if ( class_exists( 'WP_Roles' ) ) {
            $roles = wp_roles();
            if ( $roles instanceof WP_Roles ) {
                foreach ( array_keys( $roles->roles ) as $role_slug ) {
                    $role = get_role( $role_slug );
                    if ( $role instanceof WP_Role && $role->has_cap( 'fre_manage_forms' ) ) {
                        $role->remove_cap( 'fre_manage_forms' );
                    }
                }
            }
        }
    }

    /**
     * Force-run the fresh-install routine and stamp the stored version.
     *
     * Called from the plugin's activation hook to guarantee capabilities are
     * granted even before the first `plugins_loaded` fires on a new install.
     */
    public static function on_activation() {
        $current = defined( 'PForms_VERSION' ) ? PForms_VERSION : '0.0.0';
        $stored  = get_option( self::VERSION_OPTION, '' );

        if ( '' === $stored ) {
            self::on_fresh_install();
        } else {
            // Reactivation of an existing install — ensure caps are present.
            // (They might have been removed by a previous uninstall or manual intervention.)
            PForms_Capabilities::grant_default_capabilities();
        }

        update_option( self::VERSION_OPTION, $current );
    }

    /**
     * Fresh install routine.
     *
     * Grants default capabilities. Keep minimal — anything that should always
     * happen on upgrade *and* install should go in `run_common_upgrade_steps()`
     * and be called from both paths.
     */
    private static function on_fresh_install() {
        self::run_common_upgrade_steps();
    }

    /**
     * Upgrade routine.
     *
     * Runs common steps, then any version-specific routines keyed to the
     * transition from `$from` to `$to`.
     *
     * @param string $from Previous plugin version.
     * @param string $to   Current plugin version.
     */
    private static function on_upgrade( $from, $to ) {
        self::run_common_upgrade_steps();

        // Version-specific routines go here. Keep them in ascending order.
        //
        // Example:
        //   if ( version_compare( $from, '1.3.0', '<' ) ) {
        //       self::upgrade_to_1_3_0();
        //   }
        //
        // The Phase 1 connector work ships the capability grant, which is
        // covered by `run_common_upgrade_steps()`. No version-specific
        // routine is needed for Phase 1 — all existing installs will receive
        // the capability on the next `plugins_loaded` after upgrading.

        /**
         * Fires after upgrade routines complete successfully.
         *
         * Use for logging, post-upgrade cache flushes, or side effects that
         * external code needs to hang off of.
         *
         * @param string $from Previous plugin version.
         * @param string $to   Current plugin version.
         */
        do_action( 'pforms_plugin_upgraded', $from, $to );
    }

    /**
     * Steps that run on every fresh install and upgrade.
     *
     * Must be idempotent — any operation here should be safe to run repeatedly.
     */
    private static function run_common_upgrade_steps() {
        // Grant capabilities to default roles. Idempotent at the
        // WP_Role::add_cap() level.
        PForms_Capabilities::grant_default_capabilities();
    }

    /**
     * Get the stored version (last-seen plugin version).
     *
     * Exposed primarily for tests and diagnostics.
     *
     * @return string Stored version, or empty string if never set.
     */
    public static function get_stored_version() {
        return get_option( self::VERSION_OPTION, '' );
    }
}
