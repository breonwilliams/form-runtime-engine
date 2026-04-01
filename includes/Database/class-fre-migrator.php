<?php
/**
 * Database migrator for Form Runtime Engine.
 *
 * Handles creation and migration of database tables.
 *
 * NOTE: Uses dbDelta() and direct queries for schema management.
 * This is the standard WordPress approach for plugin table creation.
 * Table names are hardcoded with $wpdb->prefix for safety.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Database migration handler.
 */
class FRE_Migrator {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Table names.
     *
     * @var array
     */
    private $tables;

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb = $wpdb;

        $this->tables = array(
            'entries'     => $wpdb->prefix . 'fre_entries',
            'entry_meta'  => $wpdb->prefix . 'fre_entry_meta',
            'entry_files' => $wpdb->prefix . 'fre_entry_files',
        );
    }

    /**
     * Run all pending migrations.
     *
     * @return bool True on success, false on failure.
     */
    public function run_migrations() {
        $current_version = get_option( 'fre_db_version', '0.0.0' );
        $target_version  = FRE_DB_VERSION;

        // Already up to date.
        if ( version_compare( $current_version, $target_version, '>=' ) ) {
            return true;
        }

        // Get pending migrations.
        $migrations = $this->get_pending_migrations( $current_version );

        if ( empty( $migrations ) ) {
            update_option( 'fre_db_version', $target_version );
            return true;
        }

        // Note: WordPress doesn't support proper transactions on all systems,
        // but we'll use dbDelta which is safer for schema changes.
        try {
            foreach ( $migrations as $migration ) {
                $result = $this->run_migration( $migration );

                if ( $result === false ) {
                    throw new Exception(
                        sprintf(
                            'Migration %s failed: %s',
                            $migration['version'],
                            $this->wpdb->last_error
                        )
                    );
                }
            }

            // Update version on success.
            update_option( 'fre_db_version', $target_version );

            // Clear any previous migration errors.
            delete_option( 'fre_migration_error' );

            return true;

        } catch ( Exception $e ) {
            // Store error for admin notice.
            update_option( 'fre_migration_error', $e->getMessage() );

            // Log error.
            FRE_Logger::error( 'Migration Error: ' . $e->getMessage() );

            return false;
        }
    }

    /**
     * Get migrations that need to run.
     *
     * @param string $current_version Current database version.
     * @return array Array of migration definitions.
     */
    private function get_pending_migrations( $current_version ) {
        $all_migrations = array(
            array(
                'version' => '1.0.0',
                'method'  => 'migration_1_0_0',
            ),
            array(
                'version' => '1.1.0',
                'method'  => 'migration_1_1_0',
            ),
        );

        $pending = array();

        foreach ( $all_migrations as $migration ) {
            if ( version_compare( $current_version, $migration['version'], '<' ) ) {
                $pending[] = $migration;
            }
        }

        return $pending;
    }

    /**
     * Run a single migration.
     *
     * @param array $migration Migration definition.
     * @return bool True on success, false on failure.
     */
    private function run_migration( $migration ) {
        $method = $migration['method'];

        if ( method_exists( $this, $method ) ) {
            return $this->$method();
        }

        return false;
    }

    /**
     * Migration for version 1.0.0 - Initial tables.
     *
     * @return bool True on success, false on failure.
     */
    private function migration_1_0_0() {
        $charset_collate = $this->wpdb->get_charset_collate();

        // Entries table - main form submissions.
        $sql_entries = "CREATE TABLE {$this->tables['entries']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            form_id varchar(100) NOT NULL,
            user_id bigint(20) unsigned DEFAULT NULL,
            ip_address varchar(45) NOT NULL DEFAULT '',
            user_agent text,
            status varchar(20) NOT NULL DEFAULT 'unread',
            is_spam tinyint(1) NOT NULL DEFAULT 0,
            notification_sent tinyint(1) NOT NULL DEFAULT 0,
            notification_sent_at datetime DEFAULT NULL,
            notification_error text,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY form_id (form_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY is_spam (is_spam),
            KEY created_at (created_at),
            KEY form_status_created (form_id, status, created_at)
        ) $charset_collate;";

        // Entry meta table - field values.
        $sql_entry_meta = "CREATE TABLE {$this->tables['entry_meta']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            field_value longtext,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY field_key (field_key),
            KEY entry_field (entry_id, field_key)
        ) $charset_collate;";

        // Entry files table - uploaded files.
        $sql_entry_files = "CREATE TABLE {$this->tables['entry_files']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NOT NULL,
            field_key varchar(100) NOT NULL,
            attachment_id bigint(20) unsigned DEFAULT NULL,
            file_path varchar(500) NOT NULL DEFAULT '',
            file_name varchar(255) NOT NULL DEFAULT '',
            file_size bigint(20) unsigned NOT NULL DEFAULT 0,
            mime_type varchar(100) NOT NULL DEFAULT '',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY field_key (field_key),
            KEY attachment_id (attachment_id)
        ) $charset_collate;";

        // Include WordPress upgrade functions.
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        // Run dbDelta for each table.
        dbDelta( $sql_entries );
        dbDelta( $sql_entry_meta );
        dbDelta( $sql_entry_files );

        // Verify tables were created.
        $health = $this->check_database_health();

        return $health === true;
    }

    /**
     * Check if all required tables exist.
     *
     * @return bool|array True if healthy, array of missing tables otherwise.
     */
    public function check_database_health() {
        $missing = array();

        foreach ( $this->tables as $name => $table ) {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $table
                )
            );

            if ( $exists !== $table ) {
                $missing[] = $table;
            }
        }

        return empty( $missing ) ? true : $missing;
    }

    /**
     * Get table name.
     *
     * @param string $table Table key (entries, entry_meta, entry_files).
     * @return string|null Full table name or null if not found.
     */
    public function get_table( $table ) {
        return isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : null;
    }

    /**
     * Get all table names.
     *
     * @return array Array of table names.
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Drop all plugin tables.
     *
     * Used during uninstall.
     *
     * @return bool True on success.
     */
    public function drop_tables() {
        // Define allowed table suffixes for validation.
        $allowed_suffixes = array( 'fre_entries', 'fre_entry_meta', 'fre_entry_files' );

        foreach ( $this->tables as $name => $table ) {
            // Validate table name against known tables before dropping.
            $expected_suffix = isset( $this->tables[ $name ] ) ? $name : null;
            $suffix_map      = array(
                'entries'     => 'fre_entries',
                'entry_meta'  => 'fre_entry_meta',
                'entry_files' => 'fre_entry_files',
            );

            if ( isset( $suffix_map[ $name ] ) && $table === $this->wpdb->prefix . $suffix_map[ $name ] ) {
                $this->wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
            }
        }

        delete_option( 'fre_db_version' );
        delete_option( 'fre_migration_error' );

        return true;
    }

    /**
     * Get current database version.
     *
     * @return string Current database version.
     */
    public function get_current_version() {
        return get_option( 'fre_db_version', '0.0.0' );
    }

    /**
     * Migration for version 1.1.0 - Performance indexes.
     *
     * Adds composite indexes for common query patterns.
     *
     * @return bool True on success, false on failure.
     */
    private function migration_1_1_0() {
        // Add composite indexes for better query performance.
        $indexes = array(
            // For entries table: Common filter combinations.
            array(
                'table'   => $this->tables['entries'],
                'name'    => 'status_created',
                'columns' => 'status, created_at',
            ),
            array(
                'table'   => $this->tables['entries'],
                'name'    => 'spam_created',
                'columns' => 'is_spam, created_at',
            ),
            array(
                'table'   => $this->tables['entries'],
                'name'    => 'form_spam_status',
                'columns' => 'form_id, is_spam, status',
            ),
            // For entry_meta table: Search/filter by field value.
            array(
                'table'   => $this->tables['entry_meta'],
                'name'    => 'field_value_prefix',
                'columns' => 'field_key, field_value(50)',
            ),
        );

        foreach ( $indexes as $index ) {
            // Check if index already exists.
            $exists = $this->wpdb->get_var( $this->wpdb->prepare(
                "SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                 AND TABLE_NAME = %s
                 AND INDEX_NAME = %s",
                $index['table'],
                $index['name']
            ) );

            if ( ! $exists ) {
                $result = $this->wpdb->query(
                    "ALTER TABLE {$index['table']} ADD INDEX {$index['name']} ({$index['columns']})"
                );

                if ( $result === false ) {
                    FRE_Logger::error( "Failed to create index {$index['name']} on {$index['table']}: " . $this->wpdb->last_error );
                    // Continue with other indexes even if one fails.
                }
            }
        }

        return true;
    }
}
