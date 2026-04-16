<?php
/**
 * Database migrator for Twilio text-back integration.
 *
 * Creates and manages the fre_twilio_clients and fre_twilio_messages tables.
 * Follows the same migration pattern as FRE_Migrator.
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
 * Twilio database migration handler.
 */
class FRE_Twilio_Migrator {

    /**
     * Current Twilio schema version.
     *
     * @var string
     */
    const VERSION = '1.0.0';

    /**
     * Option key for storing the Twilio schema version.
     *
     * @var string
     */
    const VERSION_OPTION = 'fre_twilio_db_version';

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
            'clients'  => $wpdb->prefix . 'fre_twilio_clients',
            'messages' => $wpdb->prefix . 'fre_twilio_messages',
        );
    }

    /**
     * Run all pending Twilio migrations.
     *
     * @return bool True on success, false on failure.
     */
    public function run_migrations() {
        $current_version = get_option( self::VERSION_OPTION, '0.0.0' );

        // Already up to date.
        if ( version_compare( $current_version, self::VERSION, '>=' ) ) {
            return true;
        }

        $migrations = $this->get_pending_migrations( $current_version );

        if ( empty( $migrations ) ) {
            update_option( self::VERSION_OPTION, self::VERSION );
            return true;
        }

        try {
            foreach ( $migrations as $migration ) {
                $result = $this->run_migration( $migration );

                if ( $result === false ) {
                    throw new Exception(
                        sprintf(
                            'Twilio migration %s failed: %s',
                            $migration['version'],
                            $this->wpdb->last_error
                        )
                    );
                }
            }

            update_option( self::VERSION_OPTION, self::VERSION );
            delete_option( 'fre_twilio_migration_error' );

            return true;

        } catch ( Exception $e ) {
            update_option( 'fre_twilio_migration_error', $e->getMessage() );
            FRE_Logger::error( 'Twilio Migration Error: ' . $e->getMessage() );

            return false;
        }
    }

    /**
     * Get migrations that need to run.
     *
     * @param string $current_version Current Twilio schema version.
     * @return array Array of migration definitions.
     */
    private function get_pending_migrations( $current_version ) {
        $all_migrations = array(
            array(
                'version' => '1.0.0',
                'method'  => 'migration_1_0_0',
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
     * Migration 1.0.0 - Initial Twilio tables.
     *
     * Creates:
     * - fre_twilio_clients: Maps Twilio numbers to client configurations.
     * - fre_twilio_messages: Stores SMS conversation threads per lead.
     *
     * @return bool True on success, false on failure.
     */
    private function migration_1_0_0() {
        $charset_collate = $this->wpdb->get_charset_collate();

        // Clients table - maps Twilio numbers to client configs.
        $sql_clients = "CREATE TABLE {$this->tables['clients']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            client_name varchar(200) NOT NULL,
            twilio_number varchar(20) NOT NULL,
            owner_phone varchar(20) NOT NULL,
            owner_email varchar(200) NOT NULL DEFAULT '',
            auto_reply_template text NOT NULL,
            form_id varchar(100) NOT NULL,
            webhook_url varchar(2048) NOT NULL DEFAULT '',
            webhook_secret varchar(255) NOT NULL DEFAULT '',
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY twilio_number (twilio_number),
            KEY form_id (form_id),
            KEY is_active (is_active)
        ) $charset_collate;";

        // Messages table - SMS conversation threads.
        $sql_messages = "CREATE TABLE {$this->tables['messages']} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            entry_id bigint(20) unsigned NOT NULL,
            direction varchar(10) NOT NULL,
            body text NOT NULL,
            twilio_message_sid varchar(50) NOT NULL DEFAULT '',
            status varchar(20) NOT NULL DEFAULT 'queued',
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY entry_id (entry_id),
            KEY twilio_message_sid (twilio_message_sid),
            KEY status (status),
            KEY entry_direction (entry_id, direction)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        dbDelta( $sql_clients );
        dbDelta( $sql_messages );

        // Verify tables were created.
        return $this->check_tables_exist();
    }

    /**
     * Check if all Twilio tables exist.
     *
     * @return bool True if all tables exist.
     */
    public function check_tables_exist() {
        foreach ( $this->tables as $name => $table ) {
            $exists = $this->wpdb->get_var(
                $this->wpdb->prepare(
                    'SHOW TABLES LIKE %s',
                    $table
                )
            );

            if ( $exists !== $table ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get a table name.
     *
     * @param string $table Table key (clients, messages).
     * @return string|null Full table name or null if not found.
     */
    public function get_table( $table ) {
        return isset( $this->tables[ $table ] ) ? $this->tables[ $table ] : null;
    }

    /**
     * Get all Twilio table names.
     *
     * @return array Array of table names.
     */
    public function get_tables() {
        return $this->tables;
    }

    /**
     * Drop all Twilio tables.
     *
     * Used during uninstall.
     *
     * @return bool True on success.
     */
    public function drop_tables() {
        $allowed = array( 'fre_twilio_clients', 'fre_twilio_messages' );

        foreach ( $this->tables as $name => $table ) {
            $suffix = str_replace( $this->wpdb->prefix, '', $table );
            if ( in_array( $suffix, $allowed, true ) ) {
                $this->wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
            }
        }

        delete_option( self::VERSION_OPTION );
        delete_option( 'fre_twilio_migration_error' );

        return true;
    }

    /**
     * Check if there are pending migrations to run.
     *
     * @return bool True if migrations are pending.
     */
    public function has_pending_migrations() {
        $current_version = get_option( self::VERSION_OPTION, '0.0.0' );
        return version_compare( $current_version, self::VERSION, '<' );
    }

    /**
     * Get current Twilio schema version.
     *
     * @return string Current version.
     */
    public function get_current_version() {
        return get_option( self::VERSION_OPTION, '0.0.0' );
    }
}
