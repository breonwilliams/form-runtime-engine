<?php
/**
 * Entries List Table for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
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
            'id'           => __( 'ID', 'form-runtime-engine' ),
            'form_id'      => __( 'Form', 'form-runtime-engine' ),
            'summary'      => __( 'Summary', 'form-runtime-engine' ),
            'status'       => __( 'Status', 'form-runtime-engine' ),
            'notification' => __( 'Email', 'form-runtime-engine' ),
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
            'id'         => array( 'id', false ),
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
     * ID column with view link.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_id( $item ) {
        $view_url = add_query_arg(
            array(
                'page'     => 'fre-entry',
                'entry_id' => $item['id'],
            ),
            admin_url( 'admin.php' )
        );

        $actions = array(
            'view'   => sprintf(
                '<a href="%s">%s</a>',
                esc_url( $view_url ),
                esc_html__( 'View', 'form-runtime-engine' )
            ),
            'delete' => sprintf(
                '<a href="#" class="fre-delete-entry" data-entry-id="%d">%s</a>',
                (int) $item['id'],
                esc_html__( 'Delete', 'form-runtime-engine' )
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

        return sprintf(
            '<a href="%s"><strong>#%d</strong></a>%s',
            esc_url( $view_url ),
            (int) $item['id'],
            $this->row_actions( $actions )
        );
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
     * Summary column - show first few field values.
     *
     * @param array $item Entry data.
     * @return string
     */
    public function column_summary( $item ) {
        $entry_repo = new FRE_Entry();
        $fields     = $entry_repo->get_all_meta( $item['id'] );

        if ( empty( $fields ) ) {
            return '<em>' . esc_html__( 'No data', 'form-runtime-engine' ) . '</em>';
        }

        $summary = array();
        $count   = 0;

        foreach ( $fields as $key => $value ) {
            if ( $count >= 2 ) {
                break;
            }

            if ( is_array( $value ) ) {
                $value = implode( ', ', $value );
            }

            // Truncate long values.
            if ( strlen( $value ) > 50 ) {
                $value = substr( $value, 0, 47 ) . '...';
            }

            if ( ! empty( $value ) ) {
                $summary[] = sprintf(
                    '<strong>%s:</strong> %s',
                    esc_html( ucfirst( str_replace( '_', ' ', $key ) ) ),
                    esc_html( $value )
                );
                $count++;
            }
        }

        return implode( '<br>', $summary );
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
     * Process bulk actions.
     */
    public function process_bulk_action() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $action    = $this->current_action();
        $entry_ids = isset( $_REQUEST['entry_ids'] ) ? array_map( 'intval', $_REQUEST['entry_ids'] ) : array();

        if ( empty( $action ) || empty( $entry_ids ) ) {
            return;
        }

        // Verify nonce.
        check_admin_referer( 'bulk-entries' );

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
            $this->query->search( sanitize_text_field( $_GET['s'] ) );
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
}
