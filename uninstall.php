<?php
/**
 * Uninstall Promptless Forms.
 *
 * This file runs when the plugin is uninstalled (deleted) from WordPress.
 * It removes all plugin data including database tables and options.
 *
 * NOTE: Uses direct database queries and filesystem operations for complete cleanup.
 * This runs once during plugin deletion and must reliably remove all plugin data.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.WP.AlternativeFunctions.unlink_unlink
 * phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
 */

// Exit if uninstall not called from WordPress.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

/**
 * Duplicate-install guard (2026-07-11).
 *
 * If a second copy of this plugin exists under a different folder name
 * (release-ZIP install alongside a GitHub-source or dev-folder copy),
 * deleting the stale copy through the Plugins screen runs this file —
 * which would DROP the shared entry tables and options out from under the
 * copy still installed. If any other installed copy remains (identified
 * by its form-runtime-engine.php main file in a different plugin folder),
 * skip cleanup entirely; full cleanup runs only when the LAST copy is
 * deleted. Mirrors the guard in Promptless CPT Pages.
 */
$pforms_own_dir = dirname( WP_UNINSTALL_PLUGIN );
$pforms_mains   = glob( WP_PLUGIN_DIR . '/*/form-runtime-engine.php' );
if ( is_array( $pforms_mains ) && '' !== $pforms_own_dir && '.' !== $pforms_own_dir ) {
    foreach ( $pforms_mains as $pforms_main ) {
        if ( basename( dirname( $pforms_main ) ) !== $pforms_own_dir ) {
            return; // Another copy is still installed — preserve shared data.
        }
    }
}
unset( $pforms_own_dir, $pforms_mains, $pforms_main );

// Define plugin directory if not already defined.
if ( ! defined( 'PForms_PLUGIN_DIR' ) ) {
    define( 'PForms_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Load required files.
require_once PForms_PLUGIN_DIR . 'includes/class-fre-autoloader.php';

/**
 * Clean up all plugin data.
 */
function pforms_uninstall_cleanup() {
    global $wpdb;

    // Delete database tables.
    // Define allowed table names for validation.
    $allowed_tables = array(
        'fre_entries'     => $wpdb->prefix . 'fre_entries',
        'fre_entry_meta'  => $wpdb->prefix . 'fre_entry_meta',
        'fre_entry_files' => $wpdb->prefix . 'fre_entry_files',
        'fre_webhook_log' => $wpdb->prefix . 'fre_webhook_log',
    );

    foreach ( $allowed_tables as $key => $table ) {
        // Validate table name matches expected pattern before dropping.
        if ( $table === $wpdb->prefix . $key ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    // Delete options.
    delete_option( 'pforms_db_version' );
    delete_option( 'pforms_plugin_version' );
    delete_option( 'pforms_migration_error' );
    delete_option( 'pforms_email_failures' );

    // Delete database-stored forms.
    // Previously leaked on uninstall — explicitly cleaned since the forms
    // repository extraction.
    delete_option( 'pforms_client_forms' );

    // Delete transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_pforms_%'
        OR option_name LIKE '_transient_timeout_pforms_%'"
    );

    // Revoke the plugin's custom capability from every role.
    // Loaded lazily so uninstall still succeeds if the class is missing for
    // any reason (e.g., partial file deletion before cleanup runs).
    if ( class_exists( 'PForms_Capabilities' ) ) {
        PForms_Capabilities::revoke_all_capabilities();
    }

    // Remove connector settings (toggles + per-user configuration markers).
    if ( class_exists( 'PForms_Connector_Settings' ) ) {
        PForms_Connector_Settings::delete_all();
    }

    // Remove the connector call log.
    if ( class_exists( 'PForms_Connector_Log' ) ) {
        PForms_Connector_Log::clear();
    } else {
        delete_option( 'pforms_connector_call_log' );
    }

    // Clean up any connector rate-limit transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_pforms_connector_rate_%'
        OR option_name LIKE '_transient_timeout_pforms_connector_rate_%'"
    );

    // Delete uploaded files.
    pforms_delete_upload_directory();

    // Clear any cached data.
    wp_cache_flush();
}

/**
 * Delete the upload directory and all contents.
 */
function pforms_delete_upload_directory() {
    $upload_dir = wp_upload_dir();
    $pforms_dir    = trailingslashit( $upload_dir['basedir'] ) . 'fre-uploads';

    if ( is_dir( $pforms_dir ) ) {
        pforms_recursive_delete( $pforms_dir );
    }
}

/**
 * Recursively delete a directory and its contents.
 *
 * @param string $dir Directory path.
 * @return bool True on success.
 */
function pforms_recursive_delete( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }

    $files = array_diff( scandir( $dir ), array( '.', '..' ) );

    foreach ( $files as $file ) {
        $path = trailingslashit( $dir ) . $file;

        if ( is_dir( $path ) ) {
            pforms_recursive_delete( $path );
        } else {
            unlink( $path );
        }
    }

    return rmdir( $dir );
}

// Run cleanup. On a multisite network, clean every site so no per-site tables,
// options, capabilities, or uploaded files are orphaned. Skipped on very large
// networks to avoid request timeouts (best-effort cleanup of the current site
// only in that case).
if ( is_multisite() && ! wp_is_large_network( 'sites' ) ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $pforms_site_id ) {
        switch_to_blog( (int) $pforms_site_id );
        pforms_uninstall_cleanup();
        restore_current_blog();
    }
} else {
    pforms_uninstall_cleanup();
}
