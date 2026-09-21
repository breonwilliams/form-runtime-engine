<?php
/**
 * Entry Database Handler for Promptless Forms.
 *
 * Handles CRUD operations for form entries.
 *
 * NOTE: Uses direct database queries because this plugin uses custom tables,
 * not WordPress post/meta tables. Direct queries are necessary for:
 * - Proper JOIN operations across entry/meta/files tables
 * - Transactional integrity for entry creation
 * - Efficient bulk operations
 *
 * Table names use $wpdb->prefix which is safe and validated.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form entry database handler.
 */
class PForms_Entry {

    /**
     * Maximum length for user agent string storage.
     * Matches the database column varchar(500).
     *
     * @var int
     */
    private const MAX_USER_AGENT_LENGTH = 500;

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
     * Create a new entry.
     *
     * Fix #2: Wrapped in transaction to ensure entry and metadata are atomic.
     * If metadata insertion fails, the entry is rolled back to prevent orphaned entries.
     *
     * @param string $form_id Form ID.
     * @param array  $data    Entry data (sanitized field values).
     * @param array  $meta    Additional metadata.
     * @return int|WP_Error Entry ID on success, WP_Error on failure.
     */
    public function create( $form_id, array $data, array $meta = array() ) {
        // Prepare entry data.
        $entry_data = array(
            'form_id'    => sanitize_key( $form_id ),
            'user_id'    => get_current_user_id() ?: null,
            'ip_address' => $this->get_client_ip(),
            'user_agent' => isset( $_SERVER['HTTP_USER_AGENT'] )
                ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, self::MAX_USER_AGENT_LENGTH )
                : '',
            'status'     => 'unread',
            'is_spam'    => isset( $meta['is_spam'] ) ? (int) $meta['is_spam'] : 0,
            'created_at' => current_time( 'mysql' ),
            'updated_at' => current_time( 'mysql' ),
        );

        // Fix #2: Start transaction for atomic entry + metadata creation.
        $this->wpdb->query( 'START TRANSACTION' );

        try {
            // Insert entry.
            $result = $this->wpdb->insert(
                $this->tables['entries'],
                $entry_data,
                array( '%s', '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
            );

            if ( $result === false ) {
                $this->wpdb->query( 'ROLLBACK' );
                // Fix #29: Sanitize error logs - don't expose raw MySQL errors.
                PForms_Logger::error( 'DB Error: Entry creation failed for form ' . sanitize_key( $form_id ) );
                throw new Exception( 'Database error' );
            }

            $entry_id = $this->wpdb->insert_id;

            // Insert field values as meta.
            foreach ( $data as $field_key => $value ) {
                $meta_result = $this->add_meta( $entry_id, $field_key, $value );
                if ( $meta_result === false ) {
                    $this->wpdb->query( 'ROLLBACK' );
                    PForms_Logger::error( 'DB Error: Entry metadata creation failed for form ' . sanitize_key( $form_id ) . ', field ' . sanitize_key( $field_key ) );
                    throw new Exception( 'Database error' );
                }
            }

            // Stamp the form's connector_version onto the entry (if available).
            //
            // This enables downstream A/B analysis and iterative optimization:
            // when the Cowork connector (Phase 2+) updates a form, its
            // connector_version bumps monotonically, and every entry submitted
            // against that form carries the version it was answered under.
            //
            // The field key is prefixed with an underscore (`_pforms_form_version`)
            // to mark it as internal metadata, matching the WordPress convention
            // for hidden post meta. This also keeps it out of webhook payloads
            // and CSV exports by default unless explicitly whitelisted.
            //
            // Only DB-stored forms have a connector_version; runtime-registered
            // forms (via pforms_register_form() in PHP code) do not, and their
            // entries skip this stamp. The meta absence is the signal that the
            // entry has no version context.
            if ( class_exists( 'PForms_Forms_Repository' ) ) {
                $form_record = PForms_Forms_Repository::get( $form_id );
                if ( is_array( $form_record ) && ! empty( $form_record['connector_version'] ) ) {
                    $version_meta_result = $this->add_meta(
                        $entry_id,
                        '_pforms_form_version',
                        (int) $form_record['connector_version']
                    );

                    if ( $version_meta_result === false ) {
                        $this->wpdb->query( 'ROLLBACK' );
                        PForms_Logger::error( 'DB Error: Form version stamp failed for form ' . sanitize_key( $form_id ) );
                        throw new Exception( 'Database error' );
                    }
                }
            }

            // Commit the transaction.
            $this->wpdb->query( 'COMMIT' );

            /**
             * Fires after an entry is created.
             *
             * @param int    $entry_id Entry ID.
             * @param string $form_id  Form ID.
             * @param array  $data     Entry field data.
             */
            do_action( 'pforms_entry_created', $entry_id, $form_id, $data );

            return $entry_id;

        } catch ( Exception $e ) {
            $this->wpdb->query( 'ROLLBACK' );
            throw $e;
        }
    }

    /**
     * Get an entry by ID.
     *
     * @param int $entry_id Entry ID.
     * @return array|null Entry data or null if not found.
     */
    public function get( $entry_id ) {
        $entry = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->tables['entries']} WHERE id = %d",
                $entry_id
            ),
            ARRAY_A
        );

        if ( ! $entry ) {
            return null;
        }

        // Get meta values.
        $entry['fields'] = $this->get_all_meta( $entry_id );

        // Get files.
        $entry['files'] = $this->get_files( $entry_id );

        return $entry;
    }

    /**
     * Update an entry.
     *
     * @param int   $entry_id Entry ID.
     * @param array $data     Data to update.
     * @return bool True on success.
     */
    public function update( $entry_id, array $data ) {
        // Whitelist allowed columns.
        $allowed = array(
            'status', 'is_spam', 'notification_sent',
            'notification_sent_at', 'notification_error',
        );

        $update_data   = array();
        $update_format = array();

        foreach ( $data as $key => $value ) {
            if ( in_array( $key, $allowed, true ) ) {
                $update_data[ $key ] = $value;
                $update_format[]     = is_int( $value ) ? '%d' : '%s';
            }
        }

        if ( empty( $update_data ) ) {
            return false;
        }

        $update_data['updated_at'] = current_time( 'mysql' );
        $update_format[]           = '%s';

        $result = $this->wpdb->update(
            $this->tables['entries'],
            $update_data,
            array( 'id' => $entry_id ),
            $update_format,
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Delete an entry and all associated data.
     *
     * @param int $entry_id Entry ID.
     * @return bool True on success.
     */
    public function delete( $entry_id ) {
        // Delete files first.
        $upload_handler = new PForms_Upload_Handler();
        $upload_handler->delete_entry_files( $entry_id );

        // Delete file records.
        $this->wpdb->delete(
            $this->tables['entry_files'],
            array( 'entry_id' => $entry_id ),
            array( '%d' )
        );

        // Delete meta.
        $this->wpdb->delete(
            $this->tables['entry_meta'],
            array( 'entry_id' => $entry_id ),
            array( '%d' )
        );

        // Delete entry.
        $result = $this->wpdb->delete(
            $this->tables['entries'],
            array( 'id' => $entry_id ),
            array( '%d' )
        );

        return $result !== false;
    }

    /**
     * Add meta value for an entry.
     *
     * @param int    $entry_id   Entry ID.
     * @param string $field_key  Field key.
     * @param mixed  $value      Field value.
     * @return int|false Meta ID on success, false on failure.
     */
    public function add_meta( $entry_id, $field_key, $value ) {
        // Serialize arrays.
        if ( is_array( $value ) ) {
            $value = maybe_serialize( $value );
        }

        $result = $this->wpdb->insert(
            $this->tables['entry_meta'],
            array(
                'entry_id'    => $entry_id,
                'field_key'   => sanitize_key( $field_key ),
                'field_value' => $value,
            ),
            array( '%d', '%s', '%s' )
        );

        return $result !== false ? $this->wpdb->insert_id : false;
    }

    /**
     * Get a specific meta value.
     *
     * @param int    $entry_id  Entry ID.
     * @param string $field_key Field key.
     * @return mixed|null Field value or null if not found.
     */
    public function get_meta( $entry_id, $field_key ) {
        $value = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT field_value FROM {$this->tables['entry_meta']}
                WHERE entry_id = %d AND field_key = %s",
                $entry_id,
                $field_key
            )
        );

        if ( $value === null ) {
            return null;
        }

        return maybe_unserialize( $value );
    }

    /**
     * Get all meta values for an entry.
     *
     * @param int $entry_id Entry ID.
     * @return array Key-value pairs.
     */
    public function get_all_meta( $entry_id ) {
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT field_key, field_value FROM {$this->tables['entry_meta']}
                WHERE entry_id = %d",
                $entry_id
            ),
            ARRAY_A
        );

        $meta = array();
        foreach ( $results as $row ) {
            $meta[ $row['field_key'] ] = maybe_unserialize( $row['field_value'] );
        }

        return $meta;
    }

    /**
     * Add file record for an entry.
     *
     * @param int   $entry_id Entry ID.
     * @param array $file_data File data from upload handler.
     * @param string $field_key Field key.
     * @return int|false File record ID on success.
     */
    public function add_file( $entry_id, array $file_data, $field_key ) {
        $result = $this->wpdb->insert(
            $this->tables['entry_files'],
            array(
                'entry_id'      => $entry_id,
                'field_key'     => sanitize_key( $field_key ),
                'attachment_id' => isset( $file_data['attachment_id'] ) ? (int) $file_data['attachment_id'] : null,
                'file_path'     => isset( $file_data['file_path'] ) ? $file_data['file_path'] : '',
                'file_name'     => isset( $file_data['file_name'] ) ? $file_data['file_name'] : '',
                'file_size'     => isset( $file_data['file_size'] ) ? (int) $file_data['file_size'] : 0,
                'mime_type'     => isset( $file_data['mime_type'] ) ? $file_data['mime_type'] : '',
                'created_at'    => current_time( 'mysql' ),
            ),
            array( '%d', '%s', '%d', '%s', '%s', '%d', '%s', '%s' )
        );

        return $result !== false ? $this->wpdb->insert_id : false;
    }

    /**
     * Get files for an entry.
     *
     * @param int    $entry_id  Entry ID.
     * @param string $field_key Optional specific field key.
     * @return array Array of file records.
     */
    public function get_files( $entry_id, $field_key = '' ) {
        $sql = "SELECT * FROM {$this->tables['entry_files']} WHERE entry_id = %d";
        $params = array( $entry_id );

        if ( ! empty( $field_key ) ) {
            $sql     .= ' AND field_key = %s';
            $params[] = $field_key;
        }

        return $this->wpdb->get_results(
            $this->wpdb->prepare( $sql, ...$params ),
            ARRAY_A
        );
    }

    /**
     * Mark entry as read.
     *
     * @param int $entry_id Entry ID.
     * @return bool True on success.
     */
    public function mark_read( $entry_id ) {
        return $this->update( $entry_id, array( 'status' => 'read' ) );
    }

    /**
     * Mark entry as unread.
     *
     * @param int $entry_id Entry ID.
     * @return bool True on success.
     */
    public function mark_unread( $entry_id ) {
        return $this->update( $entry_id, array( 'status' => 'unread' ) );
    }

    /**
     * Mark entry as spam.
     *
     * @param int $entry_id Entry ID.
     * @return bool True on success.
     */
    public function mark_spam( $entry_id ) {
        return $this->update( $entry_id, array( 'is_spam' => 1 ) );
    }

    /**
     * Count entries for a form.
     *
     * @param string $form_id Form ID.
     * @param string $status  Optional status filter.
     * @return int Entry count.
     */
    public function count( $form_id = '', $status = '' ) {
        $sql    = "SELECT COUNT(*) FROM {$this->tables['entries']} WHERE 1=1";
        $params = array();

        if ( ! empty( $form_id ) ) {
            $sql     .= ' AND form_id = %s';
            $params[] = $form_id;
        }

        if ( ! empty( $status ) ) {
            $sql     .= ' AND status = %s';
            $params[] = $status;
        }

        if ( ! empty( $params ) ) {
            return (int) $this->wpdb->get_var( $this->wpdb->prepare( $sql, ...$params ) );
        }

        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Get client IP address.
     *
     * @return string
     */
    private function get_client_ip() {
        $ip = isset( $_SERVER['REMOTE_ADDR'] )
            ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
            : '';

        if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
            return '';
        }

        return $ip;
    }

    /**
     * Claim the duplicate window for this content, or report that it is taken.
     *
     * Kept for back-compat; the submission handler uses PForms_Submission_Lock
     * directly so it can tell a RECEIVED duplicate from one still in flight.
     * A true return here means only "someone holds this window" — callers
     * must not treat it as proof that anything was stored.
     *
     * @deprecated 1.11.0 Use PForms_Submission_Lock::claim() with
     *             PForms_Submission_Lock::duplicate_key().
     *
     * @param string $form_id Form ID.
     * @param array  $data    Submission data.
     * @param int    $window  Time window in seconds (default: 60).
     * @return bool True if the window was already held.
     */
    public function is_duplicate( $form_id, array $data, $window = 60 ) {
        $lock = new PForms_Submission_Lock();

        return true !== $lock->claim( PForms_Submission_Lock::duplicate_key( $form_id, $data ), (int) $window );
    }

    /**
     * Delete expired duplicate and idempotency guard rows.
     *
     * @deprecated 1.11.0 Use PForms_Submission_Lock::cleanup_expired(), which
     *             also runs on its own during submissions.
     *
     * @return int Number of rows deleted.
     */
    public function cleanup_expired_duplicates() {
        $lock = new PForms_Submission_Lock();

        return $lock->cleanup_expired();
    }
}
