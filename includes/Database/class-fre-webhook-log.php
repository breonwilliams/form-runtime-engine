<?php
/**
 * Webhook Delivery Log for Form Runtime Engine.
 *
 * Tracks webhook delivery attempts, statuses, and retry scheduling.
 * Mirrors the email retry pattern from FRE_Email_Notification.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Webhook delivery log model.
 */
class FRE_Webhook_Log {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Full table name.
     *
     * @var string
     */
    private $table;

    /**
     * Delivery status constants.
     */
    const STATUS_PENDING  = 'pending';
    const STATUS_SUCCESS  = 'success';
    const STATUS_FAILED   = 'failed';
    const STATUS_RETRYING = 'retrying';

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'fre_webhook_log';
    }

    /**
     * Get the table name.
     *
     * @return string
     */
    public function get_table_name() {
        return $this->table;
    }

    /**
     * Create a log entry for a webhook delivery attempt.
     *
     * @param int    $entry_id    Form entry ID.
     * @param string $form_id     Form ID.
     * @param string $webhook_url Webhook endpoint URL.
     * @return int|false Log entry ID on success, false on failure.
     */
    public function create( $entry_id, $form_id, $webhook_url ) {
        $result = $this->wpdb->insert(
            $this->table,
            array(
                'entry_id'    => $entry_id,
                'form_id'     => $form_id,
                'webhook_url' => $webhook_url,
                'status'      => self::STATUS_PENDING,
                'attempts'    => 0,
                'created_at'  => current_time( 'mysql', true ),
            ),
            array( '%d', '%s', '%s', '%s', '%d', '%s' )
        );

        return $result ? $this->wpdb->insert_id : false;
    }

    /**
     * Record a delivery attempt result.
     *
     * @param int    $log_id        Log entry ID.
     * @param string $status        New status (success, failed, retrying).
     * @param int    $response_code HTTP response code (0 for connection errors).
     * @param string $response_body Response body (truncated for storage).
     * @param string $error_message Error message if failed.
     */
    public function record_attempt( $log_id, $status, $response_code = 0, $response_body = '', $error_message = '' ) {
        // Truncate response body to prevent bloating the log table.
        if ( strlen( $response_body ) > 2000 ) {
            $response_body = substr( $response_body, 0, 2000 ) . '... [truncated]';
        }

        $data = array(
            'status'          => $status,
            'attempts'        => $this->get_attempts( $log_id ) + 1,
            'response_code'   => $response_code,
            'response_body'   => $response_body,
            'error_message'   => $error_message,
            'last_attempt_at' => current_time( 'mysql', true ),
        );

        // Clear next_retry_at on terminal states.
        if ( $status === self::STATUS_SUCCESS || $status === self::STATUS_FAILED ) {
            $data['next_retry_at'] = null;
        }

        $this->wpdb->update(
            $this->table,
            $data,
            array( 'id' => $log_id ),
            array( '%s', '%d', '%d', '%s', '%s', '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Schedule a retry for a log entry.
     *
     * @param int    $log_id       Log entry ID.
     * @param string $next_retry   DateTime string for next retry (UTC).
     */
    public function schedule_retry( $log_id, $next_retry ) {
        $this->wpdb->update(
            $this->table,
            array(
                'status'        => self::STATUS_RETRYING,
                'next_retry_at' => $next_retry,
            ),
            array( 'id' => $log_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );
    }

    /**
     * Get a single log entry by ID.
     *
     * @param int $log_id Log entry ID.
     * @return array|null Log entry data or null if not found.
     */
    public function get( $log_id ) {
        return $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE id = %d",
                $log_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get the current attempt count for a log entry.
     *
     * @param int $log_id Log entry ID.
     * @return int Attempt count.
     */
    private function get_attempts( $log_id ) {
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT attempts FROM {$this->table} WHERE id = %d",
                $log_id
            )
        );
    }

    /**
     * Get webhook delivery status for a specific form entry.
     *
     * @param int $entry_id Form entry ID.
     * @return array Array of log entries for this form entry.
     */
    public function get_by_entry( $entry_id ) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE entry_id = %d
                 ORDER BY created_at DESC",
                $entry_id
            ),
            ARRAY_A
        );
    }

    /**
     * Get the latest webhook status for a form entry.
     *
     * Used for the entries list table status indicator.
     *
     * @param int $entry_id Form entry ID.
     * @return string|null Status string or null if no webhook was sent.
     */
    public function get_entry_status( $entry_id ) {
        return $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT status FROM {$this->table}
                 WHERE entry_id = %d
                 ORDER BY created_at DESC
                 LIMIT 1",
                $entry_id
            )
        );
    }

    /**
     * Get log entries that are due for retry.
     *
     * @param int $limit Maximum number of entries to return.
     * @return array Array of log entries due for retry.
     */
    public function get_due_retries( $limit = 50 ) {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE status = %s
                 AND next_retry_at <= %s
                 ORDER BY next_retry_at ASC
                 LIMIT %d",
                self::STATUS_RETRYING,
                current_time( 'mysql', true ),
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Get recent webhook deliveries for admin display.
     *
     * @param int    $limit   Number of entries to return.
     * @param int    $offset  Offset for pagination.
     * @param string $form_id Optional form ID filter.
     * @return array Array with 'items' and 'total' keys.
     */
    public function get_recent( $limit = 20, $offset = 0, $form_id = '' ) {
        $where = '';
        $args  = array();

        if ( ! empty( $form_id ) ) {
            $where = 'WHERE form_id = %s';
            $args[] = $form_id;
        }

        $args[] = $limit;
        $args[] = $offset;

        $items = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 {$where}
                 ORDER BY created_at DESC
                 LIMIT %d OFFSET %d",
                ...$args
            ),
            ARRAY_A
        );

        // Get total count.
        $count_args = array();
        $count_where = '';
        if ( ! empty( $form_id ) ) {
            $count_where = 'WHERE form_id = %s';
            $count_args[] = $form_id;
        }

        if ( ! empty( $count_args ) ) {
            $total = (int) $this->wpdb->get_var(
                $this->wpdb->prepare(
                    "SELECT COUNT(*) FROM {$this->table} {$count_where}",
                    ...$count_args
                )
            );
        } else {
            $total = (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table}" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
            );
        }

        return array(
            'items' => $items ?: array(),
            'total' => $total,
        );
    }

    /**
     * Delete log entries older than a given number of days.
     *
     * Prevents the log table from growing indefinitely.
     *
     * @param int $days Number of days to retain.
     * @return int Number of rows deleted.
     */
    public function prune( $days = 30 ) {
        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

        return (int) $this->wpdb->query(
            $this->wpdb->prepare(
                "DELETE FROM {$this->table}
                 WHERE created_at < %s
                 AND status IN (%s, %s)",
                $cutoff,
                self::STATUS_SUCCESS,
                self::STATUS_FAILED
            )
        );
    }

    /**
     * Get summary counts by status.
     *
     * @return array Associative array of status => count.
     */
    public function get_status_counts() {
        $results = $this->wpdb->get_results(
            "SELECT status, COUNT(*) as count FROM {$this->table} GROUP BY status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix
            ARRAY_A
        );

        $counts = array(
            self::STATUS_PENDING  => 0,
            self::STATUS_SUCCESS  => 0,
            self::STATUS_FAILED   => 0,
            self::STATUS_RETRYING => 0,
        );

        foreach ( $results as $row ) {
            $counts[ $row['status'] ] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Check if the webhook log table exists.
     *
     * @return bool
     */
    public function table_exists() {
        $exists = $this->wpdb->get_var(
            $this->wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $this->table
            )
        );

        return $exists === $this->table;
    }
}
