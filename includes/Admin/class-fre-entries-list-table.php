<?php
/**
 * Entries List Table for Form Runtime Engine.
 *
 * NOTE: This list table uses $_GET parameters for filtering/sorting which is
 * standard WP_List_Table behavior. The admin page context provides implicit
 * nonce verification via WordPress admin routing.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Load WP_List_Table if not available.
if ( ! class_exists( 'WP_List_Table' ) ) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Entries list table using WP_List_Table.
 */
class FRE_Entries_List_Table extends WP_List_Table {

    /**
     * Entry query instance.
     *
     * @var FRE_Entry_Query
     */
    private $query;

    /**
     * Preloaded metadata for visible entries.
     *
     * @var array
     */
    private $preloaded_meta = array();

    /**
     * Constructor.
     */
    public function __construct() {
        parent::__construct( array(
            'singular' => 'entry',
            'plural'   => 'entries',
            'ajax'     => false,
        ) );

        $this->query = new FRE_Entry_Query();
    }

    /**
     * Get table columns.
     *
     * @return array
     */
    public function get_columns() {
        return array(
            'cb'           => '<input type="checkbox" />',
            'entry'        => __( 'Entry', 'form-runtime-engine' ),
            'form_id'      => __( 'Form', 'form-runtime-engine' ),
            'status'       => __( 'Status', 'form-runtime-engine' ),
            'notification' => __( 'Email', 'form-runtime-engine' ),
            'webhook'      => __( 'Webhook', 'form-runtime-engine' ),
            'created_at'   => __( 'Date', 'form-runtime-engine' ),
        );
    }

    /**
     * Get sortable columns.
     *
     * @return array
     */
    public function get_sortable_columns() {
        return array(
            'entry'      => array( 'id', false ),
            'form_id'    => array( 'form_id', false ),
            'status'     => array( 'status', false ),
            'created_at' => array( 'created_at', true ),
        );
    }

    /**
     * Default column handler.
     *
     * @param array  $item        Entry data.
     * @param string $column_name Column name.
     * @return string
     */
    public function column_default( $item, $column_name ) {
        return esc_html( $item[ $column_name ] ?? '' );
    }

    /**
     * Checkbox column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_cb( $item ) {
        return sprintf(
            '<input type="checkbox" name="entry_ids[]" value="%d" />',
            (int) $item['id']
        );
    }

    /**
     * Entry column - combined summary, ID, and row actions.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_entry( $item ) {
        // Build view URL, preserving form_id filter if set.
        $view_args = array(
            'page'     => 'fre-entry',
            'entry_id' => $item['id'],
        );

        // Preserve form_id filter for back navigation.
        if ( ! empty( $_GET['form_id'] ) ) {
            $view_args['form_id'] = sanitize_key( $_GET['form_id'] );
        }

        $view_url = add_query_arg( $view_args, admin_url( 'admin.php' ) );

        // Build summary content from preloaded metadata.
        $fields = isset( $this->preloaded_meta[ $item['id'] ] )
            ? $this->preloaded_meta[ $item['id'] ]
            : array();

        $summary_html = $this->build_entry_summary( $fields, $view_url );

        // Entry ID (muted).
        $entry_id_html = sprintf(
            '<span class="fre-entry-id">#%d</span>',
            (int) $item['id']
        );

        // Row actions.
        $actions = array(
            'view'   => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $view_url ),
                esc_html__( 'View', 'form-runtime-engine' )
            ),
        );

        // Add spam action if not already spam.
        if ( empty( $item['is_spam'] ) ) {
            $actions['spam'] = sprintf(
                '<a href="#" class="fre-mark-spam" data-entry-id="%d">%s</a>',
                (int) $item['id'],
                esc_html__( 'Spam', 'form-runtime-engine' )
            );
        }

        $actions['delete'] = sprintf(
            '<a href="#" class="fre-delete-entry submitdelete" data-entry-id="%d">%s</a>',
            (int) $item['id'],
            esc_html__( 'Delete', 'form-runtime-engine' )
        );

        return $summary_html . ' ' . $entry_id_html . $this->row_actions( $actions );
    }

    /**
     * Build entry summary HTML from field data.
     *
     * @param array  $fields   Field data.
     * @param string $view_url URL to view the entry.
     * @return string
     */
    private function build_entry_summary( $fields, $view_url ) {
        if ( empty( $fields ) ) {
            return sprintf(
                '<a class="row-title" href="%s"><em>%s</em></a>',
                esc_url( $view_url ),
                esc_html__( 'No data', 'form-runtime-engine' )
            );
        }

        $summary = array();
        $count   = 0;

        foreach ( $fields as $key => $value ) {
            if ( $count >= 2 ) {
                break;
            }

            // Fix #15: Properly escape array values before imploding.
            if ( is_array( $value ) ) {
                $value = implode( ', ', array_map( 'esc_html', array_map( 'strval', $value ) ) );
            } else {
                $value = (string) $value;
            }

            // Truncate long values.
            if ( strlen( $value ) > 50 ) {
                $value = substr( $value, 0, 47 ) . '...';
            }

            if ( ! empty( $value ) ) {
                $label = ucfirst( str_replace( '_', ' ', $key ) );

                if ( $count === 0 ) {
                    // First field: make it the clickable title.
                    $summary[] = sprintf(
                        '<a class="row-title" href="%s"><strong>%s:</strong> %s</a>',
                        esc_url( $view_url ),
                        esc_html( $label ),
                        esc_html( $value )
                    );
                } else {
                    // Subsequent fields: regular text.
                    $summary[] = sprintf(
                        '<strong>%s:</strong> %s',
                        esc_html( $label ),
                        esc_html( $value )
                    );
                }
                $count++;
            }
        }

        return implode( '<br>', $summary );
    }

    /**
     * Form ID column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_form_id( $item ) {
        $form = fre()->registry->get( $item['form_id'] );

        if ( $form && ! empty( $form['title'] ) ) {
            return esc_html( $form['title'] );
        }

        return sprintf(
            '<code>%s</code>%s',
            esc_html( $item['form_id'] ),
            $form ? '' : ' <em>(' . esc_html__( 'deleted', 'form-runtime-engine' ) . ')</em>'
        );
    }

    /**
     * Status column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_status( $item ) {
        $status = $item['status'];

        if ( ! empty( $item['is_spam'] ) ) {
            return '<span class="fre-status fre-status--spam">' . esc_html__( 'Spam', 'form-runtime-engine' ) . '</span>';
        }

        $class = $status === 'unread' ? 'fre-status--unread' : 'fre-status--read';
        $label = $status === 'unread' ? __( 'Unread', 'form-runtime-engine' ) : __( 'Read', 'form-runtime-engine' );

        return sprintf(
            '<span class="fre-status %s">%s</span>',
            esc_attr( $class ),
            esc_html( $label )
        );
    }

    /**
     * Notification column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_notification( $item ) {
        if ( ! empty( $item['notification_sent'] ) ) {
            return '<span class="dashicons dashicons-yes" style="color:#46b450;" title="' . esc_attr__( 'Sent', 'form-runtime-engine' ) . '"></span>';
        }

        if ( isset( $item['notification_sent'] ) && $item['notification_sent'] === '0' ) {
            $title = ! empty( $item['notification_error'] )
                ? $item['notification_error']
                : __( 'Failed', 'form-runtime-engine' );

            return '<span class="dashicons dashicons-warning" style="color:#d63638;" title="' . esc_attr( $title ) . '"></span>';
        }

        return '<span class="dashicons dashicons-minus" style="color:#999;" title="' . esc_attr__( 'Not sent', 'form-runtime-engine' ) . '"></span>';
    }

    /**
     * Webhook delivery status column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_webhook( $item ) {
        $log = new FRE_Webhook_Log();

        if ( ! $log->table_exists() ) {
            return '<span class="dashicons dashicons-minus" style="color:#999;" title="' . esc_attr__( 'No log table', 'form-runtime-engine' ) . '"></span>';
        }

        $status = $log->get_entry_status( (int) $item['id'] );

        if ( $status === null ) {
            // No webhook was sent for this entry.
            return '<span class="dashicons dashicons-minus" style="color:#999;" title="' . esc_attr__( 'No webhook configured', 'form-runtime-engine' ) . '"></span>';
        }

        switch ( $status ) {
            case FRE_Webhook_Log::STATUS_SUCCESS:
                return '<span class="dashicons dashicons-yes" style="color:#46b450;" title="' . esc_attr__( 'Delivered', 'form-runtime-engine' ) . '"></span>';

            case FRE_Webhook_Log::STATUS_RETRYING:
                return '<span class="dashicons dashicons-update" style="color:#dba617;" title="' . esc_attr__( 'Retrying', 'form-runtime-engine' ) . '"></span>';

            case FRE_Webhook_Log::STATUS_PENDING:
                return '<span class="dashicons dashicons-clock" style="color:#999;" title="' . esc_attr__( 'Pending', 'form-runtime-engine' ) . '"></span>';

            case FRE_Webhook_Log::STATUS_FAILED:
                return '<span class="dashicons dashicons-warning" style="color:#d63638;" title="' . esc_attr__( 'Failed', 'form-runtime-engine' ) . '"></span>';

            default:
                return '<span class="dashicons dashicons-minus" style="color:#999;"></span>';
        }
    }

    /**
     * Date column.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_created_at( $item ) {
        $timestamp = strtotime( $item['created_at'] );

        return sprintf(
            '<abbr title="%s">%s</abbr>',
            esc_attr( date_i18n( 'Y-m-d H:i:s', $timestamp ) ),
            esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'form-runtime-engine' ) )
        );
    }

    /**
     * Get bulk actions.
     *
     * @return array
     */
    public function get_bulk_actions() {
        return array(
            'mark_read'   => __( 'Mark as Read', 'form-runtime-engine' ),
            'mark_unread' => __( 'Mark as Unread', 'form-runtime-engine' ),
            'mark_spam'   => __( 'Mark as Spam', 'form-runtime-engine' ),
            'delete'      => __( 'Delete', 'form-runtime-engine' ),
        );
    }

    /**
     * Process bulk actions (Fix #8: CSRF protection - nonce check first).
     */
    public function process_bulk_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action = $this->current_action();
        if ( empty( $action ) ) {
            return;
        }

        // Fix #8: Verify nonce BEFORE extracting entry_ids.
        check_admin_referer( 'bulk-entries' );

        $entry_ids = isset( $_REQUEST['entry_ids'] ) ? array_map( 'intval', $_REQUEST['entry_ids'] ) : array();

        if ( empty( $entry_ids ) ) {
            return;
        }

        $entry_repo = new FRE_Entry();

        foreach ( $entry_ids as $entry_id ) {
            switch ( $action ) {
                case 'mark_read':
                    $entry_repo->mark_read( $entry_id );
                    break;
                case 'mark_unread':
                    $entry_repo->mark_unread( $entry_id );
                    break;
                case 'mark_spam':
                    $entry_repo->mark_spam( $entry_id );
                    break;
                case 'delete':
                    $entry_repo->delete( $entry_id );
                    break;
            }
        }
    }

    /**
     * Extra table nav for filters.
     *
     * @param string $which Top or bottom.
     */
    public function extra_tablenav( $which ) {
        if ( $which !== 'top' ) {
            return;
        }

        $query   = new FRE_Entry_Query();
        $form_ids = $query->get_form_ids();

        $current_form   = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';
        $current_status = isset( $_GET['status'] ) ? sanitize_key( $_GET['status'] ) : '';
        $show_spam      = isset( $_GET['show_spam'] ) ? (bool) $_GET['show_spam'] : false;

        ?>
        <div class="alignleft actions">
            <select name="form_id">
                <option value=""><?php esc_html_e( 'All Forms', 'form-runtime-engine' ); ?></option>
                <?php foreach ( $form_ids as $form_id ) : ?>
                    <?php
                    $form  = fre()->registry->get( $form_id );
                    $title = $form && ! empty( $form['title'] ) ? $form['title'] : $form_id;
                    ?>
                    <option value="<?php echo esc_attr( $form_id ); ?>" <?php selected( $current_form, $form_id ); ?>>
                        <?php echo esc_html( $title ); ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <select name="status">
                <option value=""><?php esc_html_e( 'All Statuses', 'form-runtime-engine' ); ?></option>
                <option value="unread" <?php selected( $current_status, 'unread' ); ?>>
                    <?php esc_html_e( 'Unread', 'form-runtime-engine' ); ?>
                </option>
                <option value="read" <?php selected( $current_status, 'read' ); ?>>
                    <?php esc_html_e( 'Read', 'form-runtime-engine' ); ?>
                </option>
            </select>

            <label>
                <input type="checkbox" name="show_spam" value="1" <?php checked( $show_spam ); ?> />
                <?php esc_html_e( 'Include Spam', 'form-runtime-engine' ); ?>
            </label>

            <button type="submit" class="button"><?php esc_html_e( 'Filter', 'form-runtime-engine' ); ?></button>
        </div>
        <?php
    }

    /**
     * Prepare items for display.
     */
    public function prepare_items() {
        $this->process_bulk_action();

        $per_page     = 20;
        $current_page = $this->get_pagenum();

        // Build query.
        $this->query->reset();

        // Apply filters.
        if ( ! empty( $_GET['form_id'] ) ) {
            $this->query->form( sanitize_key( $_GET['form_id'] ) );
        }

        if ( ! empty( $_GET['status'] ) ) {
            $this->query->status( sanitize_key( $_GET['status'] ) );
        }

        if ( empty( $_GET['show_spam'] ) ) {
            $this->query->spam( false );
        }

        // Search.
        if ( ! empty( $_GET['s'] ) ) {
            $this->query->search( sanitize_text_field( wp_unslash( $_GET['s'] ) ) );
        }

        // Sorting.
        $orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'created_at';
        $order   = isset( $_GET['order'] ) ? sanitize_key( $_GET['order'] ) : 'DESC';
        $this->query->order_by( $orderby, $order );

        // Get total count.
        $total_items = $this->query->count();

        // Pagination.
        $this->query->page( $current_page, $per_page );

        // Get items.
        $this->items = $this->query->get();

        // Preload metadata for all visible entries to avoid N+1 queries.
        $this->preload_metadata();

        // Set up pagination.
        $this->set_pagination_args( array(
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'total_pages' => ceil( $total_items / $per_page ),
        ) );

        // Set column headers.
        $this->_column_headers = array(
            $this->get_columns(),
            array(),
            $this->get_sortable_columns(),
        );
    }

    /**
     * Message for no items.
     */
    public function no_items() {
        esc_html_e( 'No entries found.', 'form-runtime-engine' );
    }

    /**
     * Preload metadata for all visible entries.
     *
     * Performance optimization: Single batch query instead of N queries.
     */
    private function preload_metadata() {
        if ( empty( $this->items ) ) {
            return;
        }

        global $wpdb;

        $entry_ids = array_column( $this->items, 'id' );

        if ( empty( $entry_ids ) ) {
            return;
        }

        $table        = $wpdb->prefix . 'fre_entry_meta';
        $placeholders = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );

        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT entry_id, field_key, field_value FROM {$table}
                 WHERE entry_id IN ({$placeholders})",
                ...$entry_ids
            ),
            ARRAY_A
        );

        // Organize by entry_id.
        $this->preloaded_meta = array();
        foreach ( $results as $row ) {
            $entry_id  = $row['entry_id'];
            $field_key = $row['field_key'];
            $value     = maybe_unserialize( $row['field_value'] );

            if ( ! isset( $this->preloaded_meta[ $entry_id ] ) ) {
                $this->preloaded_meta[ $entry_id ] = array();
            }
            $this->preloaded_meta[ $entry_id ][ $field_key ] = $value;
        }
    }
}
