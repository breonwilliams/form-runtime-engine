<?php
/**
 * Plugin upgrade handler for Form Runtime Engine.
 *
 * Runs on every `plugins_loaded` to detect when the plugin's code version
 * (FRE_VERSION) differs from the version stored in the database
 * (`fre_plugin_version` option). When it detects a difference, it runs any
 * required upgrade routines and updates the stored version.
 *
 * This is distinct from FRE_Migrator, which handles *database schema* versioning
 * via the `fre_db_version` option. Those concerns are orthogonal: the schema may
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
class FRE_Upgrader {

    /**
     * Option key that stores the last-seen plugin version.
     *
     * @var string
     */
    const VERSION_OPTION = 'fre_plugin_version';

    /**
     * Detect whether the plugin's code version differs from the stored version.
     *
     * Safe to call on every `plugins_loaded` — does nothing on hot paths when
     * versions already match.
     */
    public static function maybe_upgrade() {
        $stored  = get_option( self::VERSION_OPTION, '' );
        $current = defined( 'FRE_VERSION' ) ? FRE_VERSION : '0.0.0';

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
            if ( class_exists( 'FRE_Logger' ) ) {
                FRE_Logger::warning(
                    sprintf(
                        'Form Runtime Engine downgrade detected: stored version %s > plugin version %s. No routines run.',
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
     * Force-run the fresh-install routine and stamp the stored version.
     *
     * Called from the plugin's activation hook to guarantee capabilities are
     * granted even before the first `plugins_loaded` fires on a new install.
     */
    public static function on_activation() {
        $current = defined( 'FRE_VERSION' ) ? FRE_VERSION : '0.0.0';
        $stored  = get_option( self::VERSION_OPTION, '' );

        if ( '' === $stored ) {
            self::on_fresh_install();
        } else {
            // Reactivation of an existing install — ensure caps are present.
            // (They might have been removed by a previous uninstall or manual intervention.)
            FRE_Capabilities::grant_default_capabilities();
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
        do_action( 'fre_plugin_upgraded', $from, $to );
    }

    /**
     * Steps that run on every fresh install and upgrade.
     *
     * Must be idempotent — any operation here should be safe to run repeatedly.
     */
    private static function run_common_upgrade_steps() {
        // Grant capabilities to default roles. Idempotent at the
        // WP_Role::add_cap() level.
        FRE_Capabilities::grant_default_capabilities();
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
