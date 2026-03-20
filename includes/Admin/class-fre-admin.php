<?php
/**
 * Admin Handler for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin interface handler.
 */
class FRE_Admin {

    /**
     * Constructor.
     */
    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );
        add_action( 'admin_notices', array( $this, 'admin_notices' ) );
        add_action( 'admin_init', array( $this, 'handle_actions' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

        // AJAX handlers for entries.
        add_action( 'wp_ajax_fre_mark_read', array( $this, 'ajax_mark_read' ) );
        add_action( 'wp_ajax_fre_mark_unread', array( $this, 'ajax_mark_unread' ) );
        add_action( 'wp_ajax_fre_delete_entry', array( $this, 'ajax_delete_entry' ) );
        add_action( 'wp_ajax_fre_mark_spam', array( $this, 'ajax_mark_spam' ) );

        // AJAX handlers for forms management.
        add_action( 'wp_ajax_fre_save_form', array( 'FRE_Forms_Manager', 'ajax_save_form' ) );
        add_action( 'wp_ajax_fre_delete_form', array( 'FRE_Forms_Manager', 'ajax_delete_form' ) );
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu_pages() {
        // Main menu.
        add_menu_page(
            __( 'Form Entries', 'form-runtime-engine' ),
            __( 'Form Entries', 'form-runtime-engine' ),
            'manage_options',
            'fre-entries',
            array( $this, 'render_entries_page' ),
            'dashicons-feedback',
            30
        );

        // Entry detail page (hidden from menu).
        add_submenu_page(
            null,
            __( 'Entry Details', 'form-runtime-engine' ),
            __( 'Entry Details', 'form-runtime-engine' ),
            'manage_options',
            'fre-entry',
            array( $this, 'render_entry_page' )
        );

        // Export page.
        add_submenu_page(
            'fre-entries',
            __( 'Export Entries', 'form-runtime-engine' ),
            __( 'Export', 'form-runtime-engine' ),
            'manage_options',
            'fre-export',
            array( $this, 'render_export_page' )
        );

        // Forms management page.
        add_submenu_page(
            'fre-entries',
            __( 'Manage Forms', 'form-runtime-engine' ),
            __( 'Forms', 'form-runtime-engine' ),
            'manage_options',
            'fre-forms',
            array( 'FRE_Forms_Manager', 'render_page' )
        );

        // Settings page.
        add_submenu_page(
            'fre-entries',
            __( 'Settings', 'form-runtime-engine' ),
            __( 'Settings', 'form-runtime-engine' ),
            'manage_options',
            'fre-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            'fre_settings',
            'fre_google_places_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        add_settings_section(
            'fre_api_keys_section',
            __( 'API Keys', 'form-runtime-engine' ),
            array( $this, 'render_api_keys_section' ),
            'fre-settings'
        );

        add_settings_field(
            'fre_google_places_api_key',
            __( 'Google Places API Key', 'form-runtime-engine' ),
            array( $this, 'render_google_places_api_key_field' ),
            'fre-settings',
            'fre_api_keys_section'
        );
    }

    /**
     * Render API keys section description.
     */
    public function render_api_keys_section() {
        echo '<p>' . esc_html__( 'Configure API keys for advanced form field features.', 'form-runtime-engine' ) . '</p>';
    }

    /**
     * Render Google Places API key field.
     */
    public function render_google_places_api_key_field() {
        $value = get_option( 'fre_google_places_api_key', '' );
        ?>
        <input type="password"
               id="fre_google_places_api_key"
               name="fre_google_places_api_key"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
               autocomplete="off" />
        <button type="button" class="button fre-toggle-api-key" data-target="fre_google_places_api_key">
            <?php esc_html_e( 'Show', 'form-runtime-engine' ); ?>
        </button>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to Google Cloud Console */
                esc_html__( 'Required for address autocomplete fields. Get an API key from the %s.', 'form-runtime-engine' ),
                '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' .
                esc_html__( 'Google Cloud Console', 'form-runtime-engine' ) .
                '</a>'
            );
            ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Make sure to enable the Places API and Maps JavaScript API for your project.', 'form-runtime-engine' ); ?>
        </p>
        <?php
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Form Runtime Engine Settings', 'form-runtime-engine' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'fre_settings' );
                do_settings_sections( 'fre-settings' );
                submit_button();
                ?>
            </form>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('.fre-toggle-api-key').on('click', function() {
                var $btn = $(this);
                var $input = $('#' + $btn.data('target'));
                if ($input.attr('type') === 'password') {
                    $input.attr('type', 'text');
                    $btn.text('<?php echo esc_js( __( 'Hide', 'form-runtime-engine' ) ); ?>');
                } else {
                    $input.attr('type', 'password');
                    $btn.text('<?php echo esc_js( __( 'Show', 'form-runtime-engine' ) ); ?>');
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // Only load on our pages.
        if ( strpos( $hook, 'fre-' ) === false && strpos( $hook, 'fre_' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'fre-admin',
            FRE_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            FRE_VERSION
        );

        wp_enqueue_script(
            'fre-admin',
            FRE_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            FRE_VERSION,
            true
        );

        wp_localize_script( 'fre-admin', 'freAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'fre_admin_nonce' ),
            'strings' => array(
                // Entry management strings.
                'confirmDelete'      => __( 'Are you sure you want to delete this entry? This cannot be undone.', 'form-runtime-engine' ),
                'confirmSpam'        => __( 'Mark this entry as spam?', 'form-runtime-engine' ),
                // Forms management strings.
                'confirmDeleteForm'  => __( 'Are you sure you want to delete this form? This cannot be undone.', 'form-runtime-engine' ),
                'copied'             => __( 'Shortcode copied to clipboard!', 'form-runtime-engine' ),
                'copyFailed'         => __( 'Failed to copy. Please select and copy manually.', 'form-runtime-engine' ),
                'saving'             => __( 'Saving...', 'form-runtime-engine' ),
                'deleting'           => __( 'Deleting...', 'form-runtime-engine' ),
                'formIdRequired'     => __( 'Form ID is required.', 'form-runtime-engine' ),
                'configRequired'     => __( 'Configuration is required.', 'form-runtime-engine' ),
            ),
        ) );
    }

    /**
     * Render entries list page.
     */
    public function render_entries_page() {
        // Check user capability.
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
        }

        // Create list table.
        $list_table = new FRE_Entries_List_Table();
        $list_table->prepare_items();

        // Get form filter options.
        $query     = new FRE_Entry_Query();
        $form_ids  = $query->get_form_ids();

        // Check if filtering by form.
        $current_form_id = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';
        $form_title      = '';

        if ( $current_form_id ) {
            $form = fre()->registry->get( $current_form_id );
            $form_title = $form && ! empty( $form['title'] ) ? $form['title'] : $current_form_id;
        }

        ?>
        <div class="wrap">
            <?php if ( $current_form_id ) : ?>
                <h1 class="wp-heading-inline">
                    <?php
                    printf(
                        /* translators: %s: form title */
                        esc_html__( 'Entries for: %s', 'form-runtime-engine' ),
                        esc_html( $form_title )
                    );
                    ?>
                </h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-entries' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'View All Entries', 'form-runtime-engine' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $current_form_id ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Edit Form', 'form-runtime-engine' ); ?>
                </a>
                <hr class="wp-header-end">

                <!-- Tab navigation to match form edit page -->
                <h2 class="nav-tab-wrapper fre-form-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $current_form_id ) ); ?>" class="nav-tab">
                        <?php esc_html_e( 'Settings', 'form-runtime-engine' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-entries&form_id=' . $current_form_id ) ); ?>" class="nav-tab nav-tab-active">
                        <?php esc_html_e( 'Entries', 'form-runtime-engine' ); ?>
                    </a>
                </h2>
            <?php else : ?>
                <h1 class="wp-heading-inline"><?php esc_html_e( 'Form Entries', 'form-runtime-engine' ); ?></h1>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="fre-entries" />
                <?php
                $list_table->search_box( __( 'Search Entries', 'form-runtime-engine' ), 'fre-search' );
                $list_table->display();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Render single entry page.
     */
    public function render_entry_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_die( esc_html__( 'Invalid entry ID.', 'form-runtime-engine' ) );
        }

        $entry_detail = new FRE_Entry_Detail( $entry_id );
        $entry_detail->render();
    }

    /**
     * Render export page.
     */
    public function render_export_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
        }

        $query    = new FRE_Entry_Query();
        $form_ids = $query->get_form_ids();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Export Entries', 'form-runtime-engine' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="fre_export_csv" />
                <?php wp_nonce_field( 'fre_export_csv', 'fre_export_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="form_id"><?php esc_html_e( 'Form', 'form-runtime-engine' ); ?></label>
                        </th>
                        <td>
                            <select name="form_id" id="form_id">
                                <option value=""><?php esc_html_e( 'All Forms', 'form-runtime-engine' ); ?></option>
                                <?php foreach ( $form_ids as $form_id ) : ?>
                                    <?php
                                    $form  = fre()->registry->get( $form_id );
                                    $title = $form ? $form['title'] : $form_id;
                                    ?>
                                    <option value="<?php echo esc_attr( $form_id ); ?>">
                                        <?php echo esc_html( $title ?: $form_id ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="date_from"><?php esc_html_e( 'Date Range', 'form-runtime-engine' ); ?></label>
                        </th>
                        <td>
                            <input type="date" name="date_from" id="date_from" />
                            <span>&mdash;</span>
                            <input type="date" name="date_to" id="date_to" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="status"><?php esc_html_e( 'Status', 'form-runtime-engine' ); ?></label>
                        </th>
                        <td>
                            <select name="status" id="status">
                                <option value=""><?php esc_html_e( 'All', 'form-runtime-engine' ); ?></option>
                                <option value="unread"><?php esc_html_e( 'Unread', 'form-runtime-engine' ); ?></option>
                                <option value="read"><?php esc_html_e( 'Read', 'form-runtime-engine' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_spam" value="1" checked />
                                <?php esc_html_e( 'Exclude spam entries', 'form-runtime-engine' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Export CSV', 'form-runtime-engine' ); ?>
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Display admin notices.
     */
    public function admin_notices() {
        // Check email failures.
        $email_handler = new FRE_Email_Notification();
        $failures      = $email_handler->get_failure_count();

        if ( $failures > 5 ) {
            ?>
            <div class="notice notice-warning">
                <p>
                    <strong><?php esc_html_e( 'Form Runtime Engine:', 'form-runtime-engine' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of failed emails */
                        esc_html__( '%d email notifications have failed recently. Please check your email configuration.', 'form-runtime-engine' ),
                        $failures
                    );
                    ?>
                </p>
            </div>
            <?php
        }
    }

    /**
     * Handle admin actions.
     */
    public function handle_actions() {
        // Handle CSV export.
        add_action( 'admin_post_fre_export_csv', array( $this, 'handle_csv_export' ) );
    }

    /**
     * Handle CSV export action.
     */
    public function handle_csv_export() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'form-runtime-engine' ) );
        }

        check_admin_referer( 'fre_export_csv', 'fre_export_nonce' );

        $exporter = new FRE_CSV_Exporter();

        $args = array(
            'form_id'      => isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '',
            'date_from'    => isset( $_POST['date_from'] ) ? sanitize_text_field( $_POST['date_from'] ) : '',
            'date_to'      => isset( $_POST['date_to'] ) ? sanitize_text_field( $_POST['date_to'] ) : '',
            'status'       => isset( $_POST['status'] ) ? sanitize_key( $_POST['status'] ) : '',
            'exclude_spam' => ! empty( $_POST['exclude_spam'] ),
        );

        $exporter->export( $args );
    }

    /**
     * AJAX: Mark entry as read.
     */
    public function ajax_mark_read() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new FRE_Entry();
        $result     = $entry_repo->mark_read( $entry_id );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Failed to update entry' ) );
        }
    }

    /**
     * AJAX: Mark entry as unread.
     */
    public function ajax_mark_unread() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new FRE_Entry();
        $result     = $entry_repo->mark_unread( $entry_id );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Failed to update entry' ) );
        }
    }

    /**
     * AJAX: Delete entry.
     */
    public function ajax_delete_entry() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new FRE_Entry();
        $result     = $entry_repo->delete( $entry_id );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Failed to delete entry' ) );
        }
    }

    /**
     * AJAX: Mark entry as spam.
     */
    public function ajax_mark_spam() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new FRE_Entry();
        $result     = $entry_repo->mark_spam( $entry_id );

        if ( $result ) {
            wp_send_json_success();
        } else {
            wp_send_json_error( array( 'message' => 'Failed to update entry' ) );
        }
    }

    /**
     * Get entry counts grouped by form ID.
     *
     * Returns an array with total and unread counts for each form.
     * Uses a single efficient query with GROUP BY.
     *
     * @return array Associative array keyed by form_id, each with 'total' and 'unread' counts.
     */
    public static function get_entry_counts_by_form() {
        global $wpdb;

        $table = $wpdb->prefix . 'fre_entries';

        $results = $wpdb->get_results(
            "SELECT form_id,
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'unread' THEN 1 ELSE 0 END) as unread
             FROM {$table}
             WHERE is_spam = 0
             GROUP BY form_id",
            ARRAY_A
        );

        $counts = array();
        foreach ( $results as $row ) {
            $counts[ $row['form_id'] ] = array(
                'total'  => (int) $row['total'],
                'unread' => (int) $row['unread'],
            );
        }

        return $counts;
    }
}
