<?php
/**
 * Entry Query Builder for Form Runtime Engine.
 *
 * Provides a fluent interface for querying entries.
 *
 * NOTE: Uses direct database queries because this plugin uses custom tables.
 * Query builder pattern requires dynamic SQL construction with proper escaping.
 * All user input is escaped via $wpdb->prepare() or esc_sql().
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Entry query builder.
 */
class FRE_Entry_Query {

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
     * WHERE clauses.
     *
     * @var array
     */
    private $where = array();

    /**
     * WHERE parameters.
     *
     * @var array
     */
    private $where_params = array();

    /**
     * ORDER BY clause.
     *
     * @var string
     */
    private $order = 'created_at DESC';

    /**
     * LIMIT clause.
     *
     * @var int|null
     */
    private $limit = null;

    /**
     * OFFSET clause.
     *
     * @var int
     */
    private $offset = 0;

    /**
     * Allowed order columns (whitelist for security).
     *
     * @var array
     */
    private const ALLOWED_ORDER_COLUMNS = array(
        'id', 'form_id', 'created_at', 'updated_at', 'status', 'is_spam',
    );

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
     * Filter by form ID.
     *
     * @param string $form_id Form ID.
     * @return self
     */
    public function form( $form_id ) {
        $this->where[]        = 'form_id = %s';
        $this->where_params[] = sanitize_key( $form_id );
        return $this;
    }

    /**
     * Filter by status.
     *
     * @param string $status Entry status.
     * @return self
     */
    public function status( $status ) {
        $this->where[]        = 'status = %s';
        $this->where_params[] = sanitize_key( $status );
        return $this;
    }

    /**
     * Filter by spam status.
     *
     * @param bool $is_spam Whether to include spam entries.
     * @return self
     */
    public function spam( $is_spam = true ) {
        $this->where[]        = 'is_spam = %d';
        $this->where_params[] = $is_spam ? 1 : 0;
        return $this;
    }

    /**
     * Filter by user ID.
     *
     * @param int $user_id User ID.
     * @return self
     */
    public function user( $user_id ) {
        $this->where[]        = 'user_id = %d';
        $this->where_params[] = (int) $user_id;
        return $this;
    }

    /**
     * Filter by date range (Fix #22: Strict date validation).
     *
     * Performance fix: Uses datetime range comparison instead of DATE() function
     * to allow index usage on created_at column.
     *
     * @param string $start Start date (Y-m-d format).
     * @param string $end   End date (Y-m-d format).
     * @return self
     */
    public function date_range( $start, $end ) {
        $validated_start = $this->validate_date( $start );
        $validated_end   = $this->validate_date( $end );

        // Use datetime range to enable index usage.
        // Start at beginning of start date.
        if ( $validated_start ) {
            $this->where[]        = 'created_at >= %s';
            $this->where_params[] = $validated_start . ' 00:00:00';
        }

        // End at end of end date.
        if ( $validated_end ) {
            $this->where[]        = 'created_at <= %s';
            $this->where_params[] = $validated_end . ' 23:59:59';
        }

        return $this;
    }

    /**
     * Filter by date (entries from a specific day).
     *
     * Performance fix: Uses datetime range comparison instead of DATE() function
     * to allow index usage on created_at column.
     *
     * @param string $date Date (Y-m-d format).
     * @return self
     */
    public function date( $date ) {
        $validated_date = $this->validate_date( $date );
        if ( $validated_date ) {
            // Use range to cover entire day while allowing index usage.
            $this->where[]        = 'created_at >= %s';
            $this->where_params[] = $validated_date . ' 00:00:00';
            $this->where[]        = 'created_at <= %s';
            $this->where_params[] = $validated_date . ' 23:59:59';
        }
        return $this;
    }

    /**
     * Validate date string (Fix #22).
     *
     * @param string $date Date string.
     * @return string|false Validated date in Y-m-d format, or false if invalid.
     */
    private function validate_date( $date ) {
        $date = sanitize_text_field( $date );

        // Validate format using DateTime.
        $dt = DateTime::createFromFormat( 'Y-m-d', $date );
        if ( $dt && $dt->format( 'Y-m-d' ) === $date ) {
            return $date;
        }

        return false;
    }

    /**
     * Filter by field value using meta table.
     *
     * @param string $field_key   Field key.
     * @param mixed  $field_value Field value.
     * @param string $compare     Comparison operator (=, LIKE, !=).
     * @return self
     */
    public function field( $field_key, $field_value, $compare = '=' ) {
        $allowed_compare = array( '=', '!=', 'LIKE', 'NOT LIKE' );
        if ( ! in_array( $compare, $allowed_compare, true ) ) {
            $compare = '=';
        }

        $subquery = $this->wpdb->prepare(
            "SELECT entry_id FROM {$this->tables['entry_meta']}
            WHERE field_key = %s AND field_value {$compare} %s",
            sanitize_key( $field_key ),
            $compare === 'LIKE' || $compare === 'NOT LIKE'
                ? '%' . $this->wpdb->esc_like( $field_value ) . '%'
                : $field_value
        );

        $this->where[] = "id IN ({$subquery})";

        return $this;
    }

    /**
     * Filter WHERE IN clause.
     *
     * @param string $column Column name.
     * @param array  $values Array of values.
     * @return self
     */
    public function where_in( $column, array $values ) {
        if ( empty( $values ) ) {
            return $this;
        }

        // Whitelist column.
        if ( ! in_array( $column, self::ALLOWED_ORDER_COLUMNS, true ) ) {
            return $this;
        }

        $placeholders = implode( ',', array_fill( 0, count( $values ), '%s' ) );
        $this->where[] = "{$column} IN ({$placeholders})";

        foreach ( $values as $value ) {
            $this->where_params[] = $value;
        }

        return $this;
    }

    /**
     * Search across all field values.
     *
     * @param string $search Search term.
     * @return self
     */
    public function search( $search ) {
        if ( empty( $search ) ) {
            return $this;
        }

        $like    = '%' . $this->wpdb->esc_like( $search ) . '%';
        $subquery = $this->wpdb->prepare(
            "SELECT DISTINCT entry_id FROM {$this->tables['entry_meta']}
            WHERE field_value LIKE %s",
            $like
        );

        $this->where[] = "id IN ({$subquery})";

        return $this;
    }

    /**
     * Set order by.
     *
     * @param string $column    Column to order by.
     * @param string $direction ASC or DESC.
     * @return self
     */
    public function order_by( $column, $direction = 'DESC' ) {
        // Whitelist columns to prevent SQL injection.
        if ( ! in_array( $column, self::ALLOWED_ORDER_COLUMNS, true ) ) {
            $column = 'created_at';
        }

        $direction   = strtoupper( $direction ) === 'ASC' ? 'ASC' : 'DESC';
        $this->order = "{$column} {$direction}";

        return $this;
    }

    /**
     * Set limit.
     *
     * @param int $limit Number of entries.
     * @return self
     */
    public function limit( $limit ) {
        $this->limit = max( 1, (int) $limit );
        return $this;
    }

    /**
     * Set offset.
     *
     * @param int $offset Offset.
     * @return self
     */
    public function offset( $offset ) {
        $this->offset = max( 0, (int) $offset );
        return $this;
    }

    /**
     * Set page (convenience method).
     *
     * @param int $page     Page number (1-based).
     * @param int $per_page Items per page.
     * @return self
     */
    public function page( $page, $per_page = 20 ) {
        $page     = max( 1, (int) $page );
        $per_page = max( 1, min( 100, (int) $per_page ) );

        $this->limit  = $per_page;
        $this->offset = ( $page - 1 ) * $per_page;

        return $this;
    }

    /**
     * Execute query and get results.
     *
     * @param bool $include_meta Include field values for each entry.
     * @return array Array of entry records.
     */
    public function get( $include_meta = false ) {
        $sql = "SELECT * FROM {$this->tables['entries']}";

        if ( ! empty( $this->where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $this->where );
        }

        $sql .= " ORDER BY {$this->order}";

        if ( $this->limit !== null ) {
            $sql .= $this->wpdb->prepare( ' LIMIT %d', $this->limit );
        }

        if ( $this->offset > 0 ) {
            $sql .= $this->wpdb->prepare( ' OFFSET %d', $this->offset );
        }

        if ( ! empty( $this->where_params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$this->where_params );
        }

        $results = $this->wpdb->get_results( $sql, ARRAY_A );

        if ( $include_meta && ! empty( $results ) ) {
            $entry_repo = new FRE_Entry();
            foreach ( $results as $index => $entry ) {
                $results[ $index ]['fields'] = $entry_repo->get_all_meta( $entry['id'] );
                $results[ $index ]['files']  = $entry_repo->get_files( $entry['id'] );
            }
        }

        return $results;
    }

    /**
     * Get count of matching entries.
     *
     * @return int
     */
    public function count() {
        $sql = "SELECT COUNT(*) FROM {$this->tables['entries']}";

        if ( ! empty( $this->where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $this->where );
        }

        if ( ! empty( $this->where_params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$this->where_params );
        }

        return (int) $this->wpdb->get_var( $sql );
    }

    /**
     * Get first matching entry.
     *
     * @param bool $include_meta Include field values.
     * @return array|null
     */
    public function first( $include_meta = true ) {
        $this->limit( 1 );
        $results = $this->get( $include_meta );

        return ! empty( $results ) ? $results[0] : null;
    }

    /**
     * Get entry IDs only.
     *
     * @return array Array of entry IDs.
     */
    public function pluck_ids() {
        $sql = "SELECT id FROM {$this->tables['entries']}";

        if ( ! empty( $this->where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $this->where );
        }

        $sql .= " ORDER BY {$this->order}";

        if ( $this->limit !== null ) {
            $sql .= $this->wpdb->prepare( ' LIMIT %d', $this->limit );
        }

        if ( $this->offset > 0 ) {
            $sql .= $this->wpdb->prepare( ' OFFSET %d', $this->offset );
        }

        if ( ! empty( $this->where_params ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$this->where_params );
        }

        return $this->wpdb->get_col( $sql );
    }

    /**
     * Delete matching entries.
     *
     * @return int Number of entries deleted.
     */
    public function delete() {
        $ids = $this->pluck_ids();

        if ( empty( $ids ) ) {
            return 0;
        }

        $entry_repo = new FRE_Entry();
        $deleted    = 0;

        foreach ( $ids as $id ) {
            if ( $entry_repo->delete( $id ) ) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Reset query builder.
     *
     * @return self
     */
    public function reset() {
        $this->where        = array();
        $this->where_params = array();
        $this->order        = 'created_at DESC';
        $this->limit        = null;
        $this->offset       = 0;

        return $this;
    }

    /**
     * Get distinct form IDs that have entries.
     *
     * @return array
     */
    public function get_form_ids() {
        return $this->wpdb->get_col(
            "SELECT DISTINCT form_id FROM {$this->tables['entries']} ORDER BY form_id ASC"
        );
    }
}
