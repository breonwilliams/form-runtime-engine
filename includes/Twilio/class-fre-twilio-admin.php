<?php
/**
 * Admin interface for Twilio text-back management.
 *
 * Provides settings for Twilio credentials and a management interface
 * for client phone number configurations.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Twilio admin interface.
 */
class FRE_Twilio_Admin {

    /**
     * WordPress database instance.
     *
     * @var wpdb
     */
    private $wpdb;

    /**
     * Clients table name.
     *
     * @var string
     */
    private $clients_table;

    /**
     * Hook suffix for the Twilio admin page. Captured at registration so
     * enqueue_assets can gate the JS/CSS to just this screen.
     *
     * @var string
     */
    private $page_hook = '';

    /**
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->clients_table = $wpdb->prefix . 'fre_twilio_clients';

        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers.
        add_action( 'wp_ajax_fre_twilio_save_client', array( $this, 'ajax_save_client' ) );
        add_action( 'wp_ajax_fre_twilio_delete_client', array( $this, 'ajax_delete_client' ) );
        add_action( 'wp_ajax_fre_twilio_toggle_client', array( $this, 'ajax_toggle_client' ) );
        add_action( 'wp_ajax_fre_twilio_test_connection', array( $this, 'ajax_test_connection' ) );
    }

    /**
     * Add admin menu pages.
     */
    public function add_menu_pages() {
        // Twilio settings as a submenu under Form Entries.
        $this->page_hook = add_submenu_page(
            'fre-entries',
            __( 'Twilio Text-Back', 'promptless-forms' ),
            __( 'Twilio Text-Back', 'promptless-forms' ),
            'manage_options',
            'fre-twilio',
            array( $this, 'render_main_page' )
        );
    }

    /**
     * Enqueue Twilio admin CSS + JS — only on the Twilio admin page.
     *
     * @param string $hook_suffix Current admin page hook (passed by WP).
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
            return;
        }

        $plugin_url = plugins_url( '', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );

        wp_enqueue_style(
            'fre-twilio-admin',
            $plugin_url . '/assets/css/twilio-admin.css',
            array(),
            FRE_VERSION
        );

        wp_enqueue_script(
            'fre-twilio-admin',
            $plugin_url . '/assets/js/twilio-admin.js',
            array( 'jquery' ),
            FRE_VERSION,
            true
        );

        wp_localize_script(
            'fre-twilio-admin',
            'freTwilioAdmin',
            array(
                'nonce' => wp_create_nonce( 'fre_twilio_admin' ),
                'i18n'  => array(
                    'testing'          => __( 'Testing...', 'promptless-forms' ),
                    'connectedOk'      => __( 'Connected successfully', 'promptless-forms' ),
                    'requestFailed'    => __( 'Request failed', 'promptless-forms' ),
                    'saving'           => __( 'Saving...', 'promptless-forms' ),
                    'modalTitleAdd'    => __( 'Add Client', 'promptless-forms' ),
                    'modalTitleEdit'   => __( 'Edit Client', 'promptless-forms' ),
                    'defaultAutoReply' => __( 'Thanks for calling {business_name}! Sorry we missed you. How can we help?', 'promptless-forms' ),
                    /* translators: %s: client name */
                    'confirmDelete'    => __( 'Delete client "%s"? This cannot be undone.', 'promptless-forms' ),
                ),
            )
        );
    }

    /**
     * Register Twilio settings.
     */
    public function register_settings() {
        register_setting(
            'fre_twilio_settings',
            'fre_twilio_settings',
            array(
                'type'              => 'array',
                'sanitize_callback' => array( $this, 'sanitize_settings' ),
            )
        );
    }

    /**
     * Sanitize Twilio settings before saving.
     *
     * Encrypts sensitive credentials.
     *
     * Wave 1 audit fix (item I1): when encrypt_value() reports an
     * error (typically because the openssl extension is unavailable
     * on this host), refuse to save the credential and surface the
     * reason via add_settings_error so the admin sees an actionable
     * message instead of silently storing an unprotected value.
     * Existing stored credentials are preserved unchanged.
     *
     * @param array $input Raw input values.
     * @return array Sanitized values, with failed-encryption fields
     *               omitted (preserving any prior stored value).
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();
        $existing  = (array) get_option( 'fre_twilio_settings', array() );

        // Map of input field → admin-message verb, kept in one place so
        // the per-field handlers below stay parallel.
        $secret_fields = array(
            'account_sid' => __( 'Account SID', 'promptless-forms' ),
            'auth_token'  => __( 'Auth Token', 'promptless-forms' ),
        );

        foreach ( $secret_fields as $field => $label ) {
            if ( ! isset( $input[ $field ] ) ) {
                continue;
            }

            $raw = sanitize_text_field( $input[ $field ] );

            // Empty submission means "leave as-is" — don't overwrite a
            // previously-saved credential with an empty value.
            if ( $raw === '' ) {
                if ( isset( $existing[ $field ] ) ) {
                    $sanitized[ $field ] = $existing[ $field ];
                }
                continue;
            }

            $encrypted = FRE_Twilio_Client::encrypt_value( $raw );

            if ( is_wp_error( $encrypted ) ) {
                // Surface the error to the admin and PRESERVE any
                // previously-stored value rather than overwriting with
                // garbage. settings_errors() will render this on the
                // next admin page load.
                add_settings_error(
                    'fre_twilio_settings',
                    $encrypted->get_error_code(),
                    sprintf(
                        /* translators: 1: field label, 2: error message */
                        __( 'Twilio %1$s was not saved: %2$s', 'promptless-forms' ),
                        $label,
                        $encrypted->get_error_message()
                    ),
                    'error'
                );

                if ( isset( $existing[ $field ] ) ) {
                    $sanitized[ $field ] = $existing[ $field ];
                }
                continue;
            }

            $sanitized[ $field ] = $encrypted;
        }

        return $sanitized;
    }

    /**
     * Render the main Twilio admin page.
     */
    public function render_main_page() {
        // Check user capabilities.
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $active_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'clients';
        $settings   = get_option( 'fre_twilio_settings', array() );
        $has_creds  = ! empty( $settings['account_sid'] ) && ! empty( $settings['auth_token'] );

        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Twilio Text-Back', 'promptless-forms' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=fre-twilio&tab=clients"
                   class="nav-tab <?php echo $active_tab === 'clients' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Clients', 'promptless-forms' ); ?>
                </a>
                <a href="?page=fre-twilio&tab=settings"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'promptless-forms' ); ?>
                </a>
            </nav>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                if ( $active_tab === 'settings' ) {
                    $this->render_settings_tab( $settings, $has_creds );
                } else {
                    $this->render_clients_tab( $has_creds );
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Render the Settings tab.
     *
     * @param array $settings Current settings.
     * @param bool  $has_creds Whether credentials are configured.
     */
    private function render_settings_tab( $settings, $has_creds ) {
        ?>
        <form method="post" action="options.php">
            <?php settings_fields( 'fre_twilio_settings' ); ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="fre_twilio_account_sid">
                            <?php esc_html_e( 'Account SID', 'promptless-forms' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="fre_twilio_account_sid"
                               name="fre_twilio_settings[account_sid]"
                               value="<?php echo $has_creds ? '••••••••••••••••' : ''; ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'AC...', 'promptless-forms' ); ?>"
                               autocomplete="off" />
                        <p class="description">
                            <?php esc_html_e( 'Found in your Twilio Console dashboard.', 'promptless-forms' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fre_twilio_auth_token">
                            <?php esc_html_e( 'Auth Token', 'promptless-forms' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="password"
                               id="fre_twilio_auth_token"
                               name="fre_twilio_settings[auth_token]"
                               value="<?php echo $has_creds ? '••••••••••••••••' : ''; ?>"
                               class="regular-text"
                               autocomplete="off" />
                        <p class="description">
                            <?php esc_html_e( 'Found in your Twilio Console dashboard.', 'promptless-forms' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="fre-twilio-test-connection" class="button button-secondary"
                        <?php echo ! $has_creds ? 'disabled' : ''; ?>>
                    <?php esc_html_e( 'Test Connection', 'promptless-forms' ); ?>
                </button>
                <span id="fre-twilio-test-result" style="margin-left: 10px;"></span>
            </p>

            <?php if ( $has_creds ) : ?>
                <div class="notice notice-info inline" style="margin-top: 10px;">
                    <p>
                        <strong><?php esc_html_e( 'Webhook URLs', 'promptless-forms' ); ?></strong><br>
                        <?php esc_html_e( 'Configure these in your Twilio phone number settings:', 'promptless-forms' ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Voice URL:', 'promptless-forms' ); ?></strong>
                        <code><?php echo esc_url( rest_url( 'fre-twilio/v1/incoming-call' ) ); ?></code>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Messaging URL:', 'promptless-forms' ); ?></strong>
                        <code><?php echo esc_url( rest_url( 'fre-twilio/v1/incoming-sms' ) ); ?></code>
                    </p>
                </div>
            <?php endif; ?>

            <?php submit_button(); ?>
        </form>
        <?php
        // The Test Connection click handler lives in
        // assets/js/twilio-admin.js (enqueued on this page only). No
        // inline <script> here — Plugin Check guideline.
    }

    /**
     * Render the Clients tab.
     *
     * @param bool $has_creds Whether Twilio credentials are configured.
     */
    private function render_clients_tab( $has_creds ) {
        if ( ! $has_creds ) {
            echo '<div class="notice notice-warning inline"><p>';
            esc_html_e( 'Please configure your Twilio credentials in the Settings tab before adding clients.', 'promptless-forms' );
            echo '</p></div>';
            return;
        }

        $clients = $this->get_all_clients();
        ?>

        <div id="fre-twilio-clients">
            <h2>
                <?php esc_html_e( 'Client Phone Numbers', 'promptless-forms' ); ?>
                <button type="button" id="fre-twilio-add-client" class="page-title-action">
                    <?php esc_html_e( 'Add Client', 'promptless-forms' ); ?>
                </button>
            </h2>

            <?php if ( empty( $clients ) ) : ?>
                <p><?php esc_html_e( 'No clients configured yet. Click "Add Client" to set up your first text-back number.', 'promptless-forms' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Client Name', 'promptless-forms' ); ?></th>
                            <th><?php esc_html_e( 'Twilio Number', 'promptless-forms' ); ?></th>
                            <th><?php esc_html_e( 'Owner Phone', 'promptless-forms' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'promptless-forms' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'promptless-forms' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $clients as $client ) : ?>
                            <tr>
                                <td><strong><?php echo esc_html( $client['client_name'] ); ?></strong></td>
                                <td><code><?php echo esc_html( $client['twilio_number'] ); ?></code></td>
                                <td><?php echo esc_html( $client['owner_phone'] ); ?></td>
                                <td>
                                    <?php if ( $client['is_active'] ) : ?>
                                        <span class="fre-twilio-status-active">&#9679; <?php esc_html_e( 'Active', 'promptless-forms' ); ?></span>
                                    <?php else : ?>
                                        <span class="fre-twilio-status-inactive">&#9679; <?php esc_html_e( 'Inactive', 'promptless-forms' ); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="button button-small fre-twilio-edit-client"
                                            data-id="<?php echo esc_attr( $client['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $client['client_name'] ); ?>"
                                            data-number="<?php echo esc_attr( $client['twilio_number'] ); ?>"
                                            data-owner-phone="<?php echo esc_attr( $client['owner_phone'] ); ?>"
                                            data-owner-email="<?php echo esc_attr( $client['owner_email'] ); ?>"
                                            data-auto-reply="<?php echo esc_attr( $client['auto_reply_template'] ); ?>"
                                            data-webhook-url="<?php echo esc_attr( $client['webhook_url'] ); ?>">
                                        <?php esc_html_e( 'Edit', 'promptless-forms' ); ?>
                                    </button>
                                    <button type="button" class="button button-small fre-twilio-toggle-client"
                                            data-id="<?php echo esc_attr( $client['id'] ); ?>"
                                            data-active="<?php echo esc_attr( $client['is_active'] ); ?>">
                                        <?php echo $client['is_active'] ? esc_html__( 'Deactivate', 'promptless-forms' ) : esc_html__( 'Activate', 'promptless-forms' ); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete fre-twilio-delete-client"
                                            data-id="<?php echo esc_attr( $client['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $client['client_name'] ); ?>">
                                        <?php esc_html_e( 'Delete', 'promptless-forms' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Add/Edit Client Modal -->
        <div id="fre-twilio-client-modal">
            <div class="fre-twilio-modal-overlay">
                <div class="fre-twilio-modal-dialog">
                    <h2 id="fre-twilio-modal-title"><?php esc_html_e( 'Add Client', 'promptless-forms' ); ?></h2>
                    <input type="hidden" id="fre-twilio-client-id" value="" />

                    <table class="form-table">
                        <tr>
                            <th><label for="fre-twilio-client-name"><?php esc_html_e( 'Business Name', 'promptless-forms' ); ?></label></th>
                            <td><input type="text" id="fre-twilio-client-name" class="regular-text" placeholder="ABC Roofing" /></td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-client-number"><?php esc_html_e( 'Twilio Number', 'promptless-forms' ); ?></label></th>
                            <td>
                                <input type="text" id="fre-twilio-client-number" class="regular-text" placeholder="+15551112222" />
                                <p class="description"><?php esc_html_e( 'E.164 format (e.g., +15551112222)', 'promptless-forms' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-owner-phone"><?php esc_html_e( 'Owner Phone', 'promptless-forms' ); ?></label></th>
                            <td>
                                <input type="text" id="fre-twilio-owner-phone" class="regular-text" placeholder="+15553334444" />
                                <p class="description"><?php esc_html_e( 'Business owner\u2019s personal number for call forwarding and notifications.', 'promptless-forms' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-owner-email"><?php esc_html_e( 'Owner Email', 'promptless-forms' ); ?></label></th>
                            <td><input type="email" id="fre-twilio-owner-email" class="regular-text" placeholder="owner@abcroofing.com" /></td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-auto-reply"><?php esc_html_e( 'Auto-Reply Template', 'promptless-forms' ); ?></label></th>
                            <td>
                                <textarea id="fre-twilio-auto-reply" rows="4" class="large-text"
                                    placeholder="Thanks for calling {business_name}! Sorry we missed you. How can we help?"
                                ></textarea>
                                <p class="description"><?php esc_html_e( 'Use {business_name} as a placeholder. Sent to callers when a call is missed.', 'promptless-forms' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-webhook-url"><?php esc_html_e( 'Webhook URL', 'promptless-forms' ); ?></label></th>
                            <td>
                                <input type="url" id="fre-twilio-webhook-url" class="large-text" placeholder="https://script.google.com/macros/s/..." />
                                <p class="description"><?php esc_html_e( 'Google Apps Script endpoint for logging leads to this client\u2019s Google Sheet.', 'promptless-forms' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="button" id="fre-twilio-save-client" class="button button-primary">
                            <?php esc_html_e( 'Save Client', 'promptless-forms' ); ?>
                        </button>
                        <button type="button" id="fre-twilio-cancel-modal" class="button">
                            <?php esc_html_e( 'Cancel', 'promptless-forms' ); ?>
                        </button>
                        <span id="fre-twilio-save-result"></span>
                    </p>
                </div>
            </div>
        </div>
        <?php
        // The clients-tab modal CRUD handlers (add, edit, save, toggle,
        // delete) live in assets/js/twilio-admin.js — enqueued on this
        // page only via FRE_Twilio_Admin::enqueue_assets(). No inline
        // <script> here per WordPress.org Plugin Check guidelines.
    }

    // ──────────────────────────────────────────────────────────────
    // AJAX Handlers
    // ──────────────────────────────────────────────────────────────

    /**
     * AJAX: Save a client configuration.
     */
    public function ajax_save_client() {
        check_ajax_referer( 'fre_twilio_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $client_id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
        $client_name    = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
        $twilio_number  = isset( $_POST['twilio_number'] ) ? sanitize_text_field( wp_unslash( $_POST['twilio_number'] ) ) : '';
        $owner_phone    = isset( $_POST['owner_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['owner_phone'] ) ) : '';
        $owner_email    = isset( $_POST['owner_email'] ) ? sanitize_email( wp_unslash( $_POST['owner_email'] ) ) : '';
        $auto_reply     = isset( $_POST['auto_reply_template'] ) ? sanitize_textarea_field( wp_unslash( $_POST['auto_reply_template'] ) ) : '';
        $webhook_url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';

        // Validate required fields.
        if ( empty( $client_name ) || empty( $twilio_number ) || empty( $owner_phone ) ) {
            wp_send_json_error( 'Client name, Twilio number, and owner phone are required.' );
        }

        // Validate E.164 format.
        if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $twilio_number ) ) {
            wp_send_json_error( 'Invalid Twilio number format. Use E.164 format (e.g., +15551112222).' );
        }

        if ( ! preg_match( '/^\+[1-9]\d{1,14}$/', $owner_phone ) ) {
            wp_send_json_error( 'Invalid owner phone format. Use E.164 format (e.g., +15551112222).' );
        }

        // Generate form_id from client name.
        $form_id = 'twilio-' . sanitize_title( $client_name );

        // Generate webhook secret.
        $webhook_secret = wp_generate_password( 32, false );

        $data = array(
            'client_name'         => $client_name,
            'twilio_number'       => $twilio_number,
            'owner_phone'         => $owner_phone,
            'owner_email'         => $owner_email,
            'auto_reply_template' => $auto_reply,
            'form_id'             => $form_id,
            'webhook_url'         => $webhook_url,
            'webhook_secret'      => $webhook_secret,
            'updated_at'          => current_time( 'mysql' ),
        );

        $format = array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' );

        if ( $client_id > 0 ) {
            // Update existing client.
            $result = $this->wpdb->update(
                $this->clients_table,
                $data,
                array( 'id' => $client_id ),
                $format,
                array( '%d' )
            );
        } else {
            // Insert new client.
            $data['is_active']  = 1;
            $data['created_at'] = current_time( 'mysql' );

            $result = $this->wpdb->insert(
                $this->clients_table,
                $data,
                array_merge( $format, array( '%d', '%s' ) )
            );
        }

        if ( $result === false ) {
            wp_send_json_error( 'Database error: ' . $this->wpdb->last_error );
        }

        // Register the virtual form for this client.
        $this->register_virtual_form( $form_id, $client_name, $webhook_url, $webhook_secret );

        wp_send_json_success();
    }

    /**
     * AJAX: Delete a client configuration.
     */
    public function ajax_delete_client() {
        check_ajax_referer( 'fre_twilio_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $client_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( $client_id <= 0 ) {
            wp_send_json_error( 'Invalid client ID.' );
        }

        $this->wpdb->delete(
            $this->clients_table,
            array( 'id' => $client_id ),
            array( '%d' )
        );

        wp_send_json_success();
    }

    /**
     * AJAX: Toggle client active/inactive status.
     */
    public function ajax_toggle_client() {
        check_ajax_referer( 'fre_twilio_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $client_id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

        if ( $client_id <= 0 ) {
            wp_send_json_error( 'Invalid client ID.' );
        }

        // Toggle the is_active value. Table name is a plugin-owned property
        // ($this->clients_table is set from $wpdb->prefix . 'fre_twilio_clients'),
        // not user input, so interpolating it directly is safe. Placeholders
        // (%s, %d) are used for the actual user-supplied values.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->clients_table} SET is_active = 1 - is_active, updated_at = %s WHERE id = %d",
                current_time( 'mysql' ),
                $client_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        wp_send_json_success();
    }

    /**
     * AJAX: Test Twilio connection.
     */
    public function ajax_test_connection() {
        check_ajax_referer( 'fre_twilio_admin' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $client = FRE_Twilio_Client::from_settings();

        if ( is_wp_error( $client ) ) {
            wp_send_json_error( $client->get_error_message() );
        }

        $result = $client->test_connection();

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( $result->get_error_message() );
        }

        wp_send_json_success();
    }

    // ──────────────────────────────────────────────────────────────
    // Helper Methods
    // ──────────────────────────────────────────────────────────────

    /**
     * Get all configured clients.
     *
     * @return array Array of client records.
     */
    private function get_all_clients() {
        // $this->clients_table is set from $wpdb->prefix + hardcoded suffix; no user input.
        // Direct query is required — Twilio clients live in a plugin-specific table outside the WP query API.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $clients = $this->wpdb->get_results(
            "SELECT * FROM {$this->clients_table} ORDER BY client_name ASC",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        return $clients;
    }

    /**
     * Register a virtual FRE form for a Twilio client.
     *
     * This ensures the webhook dispatcher and admin entries list
     * work correctly for Twilio-originated leads.
     *
     * @param string $form_id       Virtual form ID.
     * @param string $client_name   Client business name.
     * @param string $webhook_url   Google Apps Script endpoint.
     * @param string $webhook_secret HMAC secret for webhook signing.
     */
    private function register_virtual_form( $form_id, $client_name, $webhook_url, $webhook_secret ) {
        // Store the form in the database-stored forms (same as FRE_Forms_Manager).
        $forms = get_option( 'fre_client_forms', array() );

        $forms[ $form_id ] = array(
            'id'              => $form_id,
            'title'           => $client_name . ' (Twilio Text-Back)',
            'config'          => wp_json_encode( array(
                'title'  => $client_name . ' (Twilio Text-Back)',
                'fields' => array(
                    array( 'key' => 'phone', 'type' => 'tel', 'label' => 'Phone Number', 'required' => true ),
                    array( 'key' => '_source_type', 'type' => 'hidden', 'label' => 'Source Type' ),
                    array( 'key' => '_call_sid', 'type' => 'hidden', 'label' => 'Call SID' ),
                    array( 'key' => '_call_status', 'type' => 'hidden', 'label' => 'Call Status' ),
                    array( 'key' => '_client_name', 'type' => 'hidden', 'label' => 'Client Name' ),
                ),
                'settings' => array(
                    'store_entries'    => true,
                    'show_title'       => false,
                    'success_message'  => '',
                    'notification'     => array(
                        'enabled' => false, // We handle notifications manually.
                    ),
                ),
            ) ),
            'webhook_enabled' => ! empty( $webhook_url ),
            'webhook_url'     => $webhook_url,
            'webhook_secret'  => $webhook_secret,
            'webhook_preset'  => 'custom',
        );

        update_option( 'fre_client_forms', $forms );
    }

    /**
     * Register all active Twilio client forms.
     *
     * Called during plugin initialization to ensure virtual forms
     * exist in the FRE registry for all configured clients.
     */
    public static function register_client_forms() {
        global $wpdb;
        // $table is built from $wpdb->prefix + hardcoded suffix; no user input.
        // Direct queries are required — Twilio clients live in a plugin-specific table outside the WP query API.
        $table = $wpdb->prefix . 'fre_twilio_clients';

        // Check if the table exists first.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

        if ( $exists !== $table ) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $clients = $wpdb->get_results(
            "SELECT form_id, client_name, webhook_url, webhook_secret FROM {$table} WHERE is_active = 1",
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( empty( $clients ) ) {
            return;
        }

        $forms = get_option( 'fre_client_forms', array() );
        $updated = false;

        foreach ( $clients as $client ) {
            if ( ! isset( $forms[ $client['form_id'] ] ) ) {
                $admin = new self();
                $admin->register_virtual_form(
                    $client['form_id'],
                    $client['client_name'],
                    $client['webhook_url'],
                    $client['webhook_secret']
                );
                $updated = true;
            }
        }
    }
}
