<?php
/**
 * Admin Handler for Promptless Forms.
 *
 * NOTE: Nonce verification is performed at the start of handler methods
 * via check_admin_referer() or check_ajax_referer(). PHPCS flags subsequent
 * $_GET/$_POST access but verification has already occurred.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 * phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Admin interface handler.
 */
class PForms_Admin {

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
        add_action( 'wp_ajax_pforms_mark_read', array( $this, 'ajax_mark_read' ) );
        add_action( 'wp_ajax_pforms_mark_unread', array( $this, 'ajax_mark_unread' ) );
        add_action( 'wp_ajax_pforms_delete_entry', array( $this, 'ajax_delete_entry' ) );
        add_action( 'wp_ajax_pforms_mark_spam', array( $this, 'ajax_mark_spam' ) );

        // AJAX handlers for forms management.
        add_action( 'wp_ajax_pforms_save_form', array( 'PForms_Forms_Manager', 'ajax_save_form' ) );
        add_action( 'wp_ajax_pforms_delete_form', array( 'PForms_Forms_Manager', 'ajax_delete_form' ) );
        add_action( 'wp_ajax_pforms_test_webhook', array( 'PForms_Forms_Manager', 'ajax_test_webhook' ) );
        add_action( 'wp_ajax_pforms_preview_payload', array( 'PForms_Forms_Manager', 'ajax_preview_payload' ) );
        add_action( 'wp_ajax_pforms_regenerate_secret', array( 'PForms_Forms_Manager', 'ajax_regenerate_secret' ) );

        // AJAX handler for API key testing.
        add_action( 'wp_ajax_pforms_test_google_api_key', array( $this, 'ajax_test_google_api_key' ) );
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu_pages() {
        // Main menu.
        add_menu_page(
            __( 'Form Entries', 'promptless-forms' ),
            __( 'Form Entries', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            'pforms-entries',
            array( $this, 'render_entries_page' ),
            'dashicons-feedback',
            30
        );

        // Entry detail page (hidden from menu).
        add_submenu_page(
            null,
            __( 'Entry Details', 'promptless-forms' ),
            __( 'Entry Details', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            'pforms-entry',
            array( $this, 'render_entry_page' )
        );

        // Export page.
        add_submenu_page(
            'pforms-entries',
            __( 'Export Entries', 'promptless-forms' ),
            __( 'Export', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            'pforms-export',
            array( $this, 'render_export_page' )
        );

        // Forms management page.
        add_submenu_page(
            'pforms-entries',
            __( 'Manage Forms', 'promptless-forms' ),
            __( 'Forms', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            'pforms-forms',
            array( 'PForms_Forms_Manager', 'render_page' )
        );

        // Settings page.
        add_submenu_page(
            'pforms-entries',
            __( 'Settings', 'promptless-forms' ),
            __( 'Settings', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            'pforms-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting(
            'pforms_settings',
            'pforms_google_places_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        add_settings_section(
            'pforms_api_keys_section',
            __( 'API Keys', 'promptless-forms' ),
            array( $this, 'render_api_keys_section' ),
            'pforms-settings'
        );

        add_settings_field(
            'pforms_google_places_api_key',
            __( 'Google Places API Key', 'promptless-forms' ),
            array( $this, 'render_google_places_api_key_field' ),
            'pforms-settings',
            'pforms_api_keys_section'
        );
    }

    /**
     * Render API keys section description.
     */
    public function render_api_keys_section() {
        echo '<p>' . esc_html__( 'Configure API keys for advanced form field features.', 'promptless-forms' ) . '</p>';
    }

    /**
     * Render Google Places API key field.
     */
    public function render_google_places_api_key_field() {
        $value = get_option( 'pforms_google_places_api_key', '' );
        ?>
        <input type="password"
               id="pforms_google_places_api_key"
               name="pforms_google_places_api_key"
               value="<?php echo esc_attr( $value ); ?>"
               class="regular-text"
               autocomplete="off" />
        <button type="button" class="button fre-toggle-api-key" data-target="pforms_google_places_api_key">
            <?php esc_html_e( 'Show', 'promptless-forms' ); ?>
        </button>
        <button type="button" class="button fre-test-api-key" id="fre-test-api-key">
            <?php esc_html_e( 'Test Connection', 'promptless-forms' ); ?>
        </button>
        <span id="fre-api-key-status" class="fre-api-key-status"></span>
        <p class="description">
            <?php
            printf(
                /* translators: %s: link to Google Cloud Console */
                esc_html__( 'Required for address autocomplete fields. Get an API key from the %s.', 'promptless-forms' ),
                '<a href="https://console.cloud.google.com/apis/credentials" target="_blank" rel="noopener noreferrer">' .
                esc_html__( 'Google Cloud Console', 'promptless-forms' ) .
                '</a>'
            );
            ?>
        </p>
        <p class="description">
            <?php esc_html_e( 'Make sure to enable the Places API and Maps JavaScript API for your project.', 'promptless-forms' ); ?>
        </p>
        <?php
    }

    /**
     * Render settings page.
     */
    public function render_settings_page() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Promptless Forms Settings', 'promptless-forms' ); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'pforms_settings' );
                do_settings_sections( 'pforms-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
        // The Show/Hide API-key toggle handler lives in
        // assets/js/admin.js (enqueued on all FRE admin pages). No inline
        // <script> here per WordPress.org Plugin Check guidelines.
        // Translated Show / Hide labels are passed via wp_localize_script
        // in enqueue_admin_assets() below.
    }

    /**
     * Enqueue admin assets.
     *
     * @param string $hook Current admin page hook.
     */
    public function enqueue_admin_assets( $hook ) {
        // Only load on our pages.
        if ( strpos( $hook, 'fre-' ) === false && strpos( $hook, 'pforms_' ) === false ) {
            return;
        }

        wp_enqueue_style(
            'pforms-admin',
            PForms_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            PForms_VERSION
        );

        wp_enqueue_script(
            'pforms-admin',
            PForms_PLUGIN_URL . 'assets/js/admin.js',
            array( 'jquery' ),
            PForms_VERSION,
            true
        );

        wp_localize_script( 'pforms-admin', 'pformsAdmin', array(
            'ajaxUrl' => admin_url( 'admin-ajax.php' ),
            'nonce'   => wp_create_nonce( 'pforms_admin_nonce' ),
            'strings' => array(
                // Entry management strings.
                'confirmDelete'      => __( 'Are you sure you want to delete this entry? This cannot be undone.', 'promptless-forms' ),
                'confirmSpam'        => __( 'Mark this entry as spam?', 'promptless-forms' ),
                // Forms management strings.
                'confirmDeleteForm'  => __( 'Are you sure you want to delete this form? This cannot be undone.', 'promptless-forms' ),
                'copied'             => __( 'Shortcode copied to clipboard!', 'promptless-forms' ),
                'copyFailed'         => __( 'Failed to copy. Please select and copy manually.', 'promptless-forms' ),
                'saving'             => __( 'Saving...', 'promptless-forms' ),
                'deleting'           => __( 'Deleting...', 'promptless-forms' ),
                'formIdRequired'     => __( 'Form ID is required.', 'promptless-forms' ),
                'configRequired'     => __( 'Configuration is required.', 'promptless-forms' ),
                // API key testing strings.
                'testing'            => __( 'Testing...', 'promptless-forms' ),
                'testConnection'     => __( 'Test Connection', 'promptless-forms' ),
                'connectionError'    => __( 'Connection error. Please try again.', 'promptless-forms' ),
                // Show/Hide toggle for sensitive admin inputs (Google
                // Places API key, etc.). Used by the .fre-toggle-api-key
                // click handler in admin.js.
                'apiKeyShow'         => __( 'Show', 'promptless-forms' ),
                'apiKeyHide'         => __( 'Hide', 'promptless-forms' ),
            ),
        ) );
    }

    /**
     * Render entries list page.
     */
    public function render_entries_page() {
        // Check user capability.
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        // Create list table.
        $list_table = new PForms_Entries_List_Table();
        $list_table->prepare_items();

        // Get form filter options.
        $query     = new PForms_Entry_Query();
        $form_ids  = $query->get_form_ids();

        // Check if filtering by form.
        $current_form_id = isset( $_GET['form_id'] ) ? sanitize_key( $_GET['form_id'] ) : '';
        $form_title      = '';

        if ( $current_form_id ) {
            $form = pforms()->registry->get( $current_form_id );
            $form_title = $form && ! empty( $form['title'] ) ? $form['title'] : $current_form_id;
        }

        ?>
        <div class="wrap">
            <?php if ( $current_form_id ) : ?>
                <h1 class="wp-heading-inline">
                    <?php
                    printf(
                        /* translators: %s: form title */
                        esc_html__( 'Entries for: %s', 'promptless-forms' ),
                        esc_html( $form_title )
                    );
                    ?>
                </h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pforms-entries' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'View All Entries', 'promptless-forms' ); ?>
                </a>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=pforms-forms&action=edit&form=' . $current_form_id ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Edit Form', 'promptless-forms' ); ?>
                </a>
                <hr class="wp-header-end">

                <!-- Tab navigation to match form edit page -->
                <h2 class="nav-tab-wrapper fre-form-tabs">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pforms-forms&action=edit&form=' . $current_form_id ) ); ?>" class="nav-tab">
                        <?php esc_html_e( 'Settings', 'promptless-forms' ); ?>
                    </a>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=pforms-entries&form_id=' . $current_form_id ) ); ?>" class="nav-tab nav-tab-active">
                        <?php esc_html_e( 'Entries', 'promptless-forms' ); ?>
                    </a>
                </h2>
            <?php else : ?>
                <h1 class="wp-heading-inline"><?php esc_html_e( 'Form Entries', 'promptless-forms' ); ?></h1>
            <?php endif; ?>

            <form method="get">
                <input type="hidden" name="page" value="fre-entries" />
                <?php
                $list_table->search_box( __( 'Search Entries', 'promptless-forms' ), 'fre-search' );
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
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        $entry_id = isset( $_GET['entry_id'] ) ? (int) $_GET['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_die( esc_html__( 'Invalid entry ID.', 'promptless-forms' ) );
        }

        $entry_detail = new PForms_Entry_Detail( $entry_id );
        $entry_detail->render();
    }

    /**
     * Render export page.
     */
    public function render_export_page() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        $query    = new PForms_Entry_Query();
        $form_ids = $query->get_form_ids();

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Export Entries', 'promptless-forms' ); ?></h1>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="pforms_export_csv" />
                <?php wp_nonce_field( 'pforms_export_csv', 'pforms_export_nonce' ); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="form_id"><?php esc_html_e( 'Form', 'promptless-forms' ); ?></label>
                        </th>
                        <td>
                            <select name="form_id" id="form_id">
                                <option value=""><?php esc_html_e( 'All Forms', 'promptless-forms' ); ?></option>
                                <?php foreach ( $form_ids as $form_id ) : ?>
                                    <?php
                                    $form  = pforms()->registry->get( $form_id );
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
                            <label for="date_from"><?php esc_html_e( 'Date Range', 'promptless-forms' ); ?></label>
                        </th>
                        <td>
                            <input type="date" name="date_from" id="date_from" />
                            <span>&mdash;</span>
                            <input type="date" name="date_to" id="date_to" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">
                            <label for="status"><?php esc_html_e( 'Status', 'promptless-forms' ); ?></label>
                        </th>
                        <td>
                            <select name="status" id="status">
                                <option value=""><?php esc_html_e( 'All', 'promptless-forms' ); ?></option>
                                <option value="unread"><?php esc_html_e( 'Unread', 'promptless-forms' ); ?></option>
                                <option value="read"><?php esc_html_e( 'Read', 'promptless-forms' ); ?></option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"></th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_spam" value="1" checked />
                                <?php esc_html_e( 'Exclude spam entries', 'promptless-forms' ); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        <?php esc_html_e( 'Export CSV', 'promptless-forms' ); ?>
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
        $email_handler = new PForms_Email_Notification();
        $failures      = $email_handler->get_failure_count();

        if ( $failures > 5 ) {
            // is-dismissible: failure count is re-evaluated each load; once
            // failures drop below the threshold (auto-cleanup or successful
            // retries) the notice stops appearing. If the threshold is
            // crossed again, the notice reappears — self-healing pattern.
            ?>
            <div class="notice notice-warning is-dismissible">
                <p>
                    <strong><?php esc_html_e( 'Promptless Forms:', 'promptless-forms' ); ?></strong>
                    <?php
                    printf(
                        /* translators: %d: number of failed emails */
                        esc_html__( '%d email notifications have failed recently. Please check your email configuration.', 'promptless-forms' ),
                        (int) $failures
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
        add_action( 'admin_post_pforms_export_csv', array( $this, 'handle_csv_export' ) );
    }

    /**
     * Handle CSV export action.
     */
    public function handle_csv_export() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'Unauthorized', 'promptless-forms' ) );
        }

        check_admin_referer( 'pforms_export_csv', 'pforms_export_nonce' );

        $exporter = new PForms_CSV_Exporter();

        $args = array(
            'form_id'      => isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '',
            'date_from'    => isset( $_POST['date_from'] ) ? sanitize_text_field( wp_unslash( $_POST['date_from'] ) ) : '',
            'date_to'      => isset( $_POST['date_to'] ) ? sanitize_text_field( wp_unslash( $_POST['date_to'] ) ) : '',
            'status'       => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
            'exclude_spam' => ! empty( $_POST['exclude_spam'] ),
        );

        $exporter->export( $args );
    }

    /**
     * AJAX: Mark entry as read.
     */
    public function ajax_mark_read() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'pforms_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new PForms_Entry();
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
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'pforms_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new PForms_Entry();
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
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'pforms_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new PForms_Entry();
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
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => 'Unauthorized' ) );
        }

        check_ajax_referer( 'pforms_admin_nonce', 'nonce' );

        $entry_id = isset( $_POST['entry_id'] ) ? (int) $_POST['entry_id'] : 0;

        if ( ! $entry_id ) {
            wp_send_json_error( array( 'message' => 'Invalid entry ID' ) );
        }

        $entry_repo = new PForms_Entry();
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

    /**
     * AJAX: Test Google Places API key.
     */
    public function ajax_test_google_api_key() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'pforms_admin_nonce', 'nonce' );

        $api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

        if ( empty( $api_key ) ) {
            wp_send_json_error( array( 'message' => __( 'Please enter an API key.', 'promptless-forms' ) ) );
        }

        // Test the API key with Google Places Find Place From Text endpoint.
        $test_url = add_query_arg(
            array(
                'input'     => 'New York',
                'inputtype' => 'textquery',
                'fields'    => 'place_id',
                'key'       => $api_key,
            ),
            'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
        );

        $response = wp_remote_get( $test_url, array( 'timeout' => 15 ) );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Could not connect to Google API. Please check your server\'s internet connection.', 'promptless-forms' ),
                )
            );
        }

        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( ! $data ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Invalid response from Google API.', 'promptless-forms' ),
                )
            );
        }

        $status = isset( $data['status'] ) ? $data['status'] : '';

        switch ( $status ) {
            case 'OK':
            case 'ZERO_RESULTS':
                // Both indicate a valid, working API key.
                wp_send_json_success(
                    array(
                        'message' => __( 'API key is valid and working.', 'promptless-forms' ),
                    )
                );
                break;

            case 'REQUEST_DENIED':
                $error_message = isset( $data['error_message'] ) ? $data['error_message'] : '';

                if ( strpos( $error_message, 'not authorized' ) !== false || strpos( $error_message, 'API key' ) !== false ) {
                    wp_send_json_error(
                        array(
                            'message' => __( 'Invalid API key or Places API not enabled for this key.', 'promptless-forms' ),
                        )
                    );
                } else {
                    wp_send_json_error(
                        array(
                            /* translators: %s: error message from Google API */
                            'message' => sprintf( __( 'Request denied: %s', 'promptless-forms' ), $error_message ),
                        )
                    );
                }
                break;

            case 'OVER_QUERY_LIMIT':
                wp_send_json_error(
                    array(
                        'message' => __( 'API key works but you have exceeded your quota. Check your Google Cloud billing.', 'promptless-forms' ),
                    )
                );
                break;

            case 'INVALID_REQUEST':
                wp_send_json_error(
                    array(
                        'message' => __( 'Invalid request. The API key may be malformed.', 'promptless-forms' ),
                    )
                );
                break;

            default:
                $error_message = isset( $data['error_message'] ) ? $data['error_message'] : $status;
                wp_send_json_error(
                    array(
                        /* translators: %s: error status or message from Google API */
                        'message' => sprintf( __( 'API error: %s', 'promptless-forms' ), $error_message ),
                    )
                );
                break;
        }
    }
}
