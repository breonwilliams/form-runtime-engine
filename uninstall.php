<?php
/**
 * Uninstall Form Runtime Engine.
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

// Define plugin directory if not already defined.
if ( ! defined( 'FRE_PLUGIN_DIR' ) ) {
    define( 'FRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}

// Load required files.
require_once FRE_PLUGIN_DIR . 'includes/class-fre-autoloader.php';

/**
 * Clean up all plugin data.
 */
function fre_uninstall_cleanup() {
    global $wpdb;

    // Delete database tables.
    // Define allowed table names for validation.
    $allowed_tables = array(
        'fre_entries'     => $wpdb->prefix . 'fre_entries',
        'fre_entry_meta'  => $wpdb->prefix . 'fre_entry_meta',
        'fre_entry_files' => $wpdb->prefix . 'fre_entry_files',
    );

    foreach ( $allowed_tables as $key => $table ) {
        // Validate table name matches expected pattern before dropping.
        if ( $table === $wpdb->prefix . $key ) {
            $wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
        }
    }

    // Delete options.
    delete_option( 'fre_db_version' );
    delete_option( 'fre_migration_error' );
    delete_option( 'fre_email_failures' );

    // Delete transients.
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
        WHERE option_name LIKE '_transient_fre_%'
        OR option_name LIKE '_transient_timeout_fre_%'"
    );

    // Delete uploaded files.
    fre_delete_upload_directory();

    // Clear any cached data.
    wp_cache_flush();
}

/**
 * Delete the upload directory and all contents.
 */
function fre_delete_upload_directory() {
    $upload_dir = wp_upload_dir();
    $fre_dir    = trailingslashit( $upload_dir['basedir'] ) . 'fre-uploads';

    if ( is_dir( $fre_dir ) ) {
        fre_recursive_delete( $fre_dir );
    }
}

/**
 * Recursively delete a directory and its contents.
 *
 * @param string $dir Directory path.
 * @return bool True on success.
 */
function fre_recursive_delete( $dir ) {
    if ( ! is_dir( $dir ) ) {
        return false;
    }

    $files = array_diff( scandir( $dir ), array( '.', '..' ) );

    foreach ( $files as $file ) {
        $path = trailingslashit( $dir ) . $file;

        if ( is_dir( $path ) ) {
            fre_recursive_delete( $path );
        } else {
            unlink( $path );
        }
    }

    return rmdir( $dir );
}

// Run cleanup.
fre_uninstall_cleanup();
