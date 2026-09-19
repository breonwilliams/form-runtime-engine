<?php
/**
 * Uninstall Promptless Forms.
 *
 * This file runs when the plugin is deleted from the Plugins screen (not on
 * deactivation).
 *
 * KEEPS the site's data unless the owner opted in. Entries, uploaded files,
 * forms, settings and API keys stay, so reinstalling picks up where the site
 * left off — the stack's data-protection rule ("never delete user data without
 * explicit consent", Promptless WP docs/operations/DATA_PROTECTION.md) and the
 * same choice WooCommerce makes. Only housekeeping always goes: caches,
 * transients, the connector's call log and settings (including its
 * application-password grants) and the plugin's capability grants.
 *
 * With **Settings → Remove all data when Promptless Forms is deleted** ticked
 * (option pforms_delete_data_on_uninstall), everything goes: the six tables
 * (entries, entry meta, entry files, webhook log, Twilio clients and
 * messages), the Media Library files uploaded through forms, the saved forms
 * and every Promptless Forms option.
 *
 * Up to 1.10.0 this file dropped the entry tables and the saved forms on
 * every deletion, with no way to keep them, and left the Twilio tables and
 * several options behind.
 *
 * NOTE: Uses direct database queries and filesystem operations; runs once.
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
 * Clean up after the plugin on one site: housekeeping always, data only with
 * the owner's opt-in.
 */
function pforms_uninstall_cleanup() {
    global $wpdb;

    // --- Always: housekeeping, no user data. -------------------------------

    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_pforms_%'
        OR option_name LIKE '_transient_timeout_pforms_%'"
    );

    // Capability grants track the plugin's presence; activation grants them
    // again.
    if ( class_exists( 'PForms_Capabilities' ) ) {
        PForms_Capabilities::revoke_all_capabilities();
    }

    // The connector's switches and application-password grants: access, not
    // content — never left behind for a plugin that is gone.
    if ( class_exists( 'PForms_Connector_Settings' ) ) {
        PForms_Connector_Settings::delete_all();
    }
    if ( class_exists( 'PForms_Connector_Log' ) ) {
        PForms_Connector_Log::clear();
    } else {
        delete_option( 'pforms_connector_call_log' );
    }

    wp_cache_flush();

    // --- Only with consent: the site's data. ------------------------------

    if ( ! get_option( 'pforms_delete_data_on_uninstall' ) ) {
        return;
    }

    // Files uploaded through forms are private Media Library items recorded
    // against their entries; remove them before the table that lists them.
    $files_table = $wpdb->prefix . 'fre_entry_files';
    if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $files_table ) ) === $files_table ) {
        $attachment_ids = $wpdb->get_col( "SELECT DISTINCT attachment_id FROM `{$files_table}` WHERE attachment_id IS NOT NULL AND attachment_id > 0" );
        foreach ( $attachment_ids as $attachment_id ) {
            wp_delete_attachment( (int) $attachment_id, true );
        }
    }

    $allowed_tables = array(
        'fre_entries'         => $wpdb->prefix . 'fre_entries',
        'fre_entry_meta'      => $wpdb->prefix . 'fre_entry_meta',
        'fre_entry_files'     => $wpdb->prefix . 'fre_entry_files',
        'fre_webhook_log'     => $wpdb->prefix . 'fre_webhook_log',
        'fre_twilio_clients'  => $wpdb->prefix . 'fre_twilio_clients',
        'fre_twilio_messages' => $wpdb->prefix . 'fre_twilio_messages',
    );
    foreach ( $allowed_tables as $key => $table ) {
        if ( $table === $wpdb->prefix . $key ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    // Every Promptless Forms option: forms, settings, API keys, Twilio
    // settings, secrets, versions — and the opt-in itself.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE 'pforms\\_%'
        OR option_name = 'fre_style_settings'"
    );

    pforms_delete_upload_directory();
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
