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
     * Constructor.
     */
    public function __construct() {
        global $wpdb;
        $this->wpdb          = $wpdb;
        $this->clients_table = $wpdb->prefix . 'fre_twilio_clients';

        add_action( 'admin_menu', array( $this, 'add_menu_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );

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
        add_submenu_page(
            'fre-entries',
            __( 'Twilio Text-Back', 'form-runtime-engine' ),
            __( 'Twilio Text-Back', 'form-runtime-engine' ),
            'manage_options',
            'fre-twilio',
            array( $this, 'render_main_page' )
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
     * @param array $input Raw input values.
     * @return array Sanitized values.
     */
    public function sanitize_settings( $input ) {
        $sanitized = array();

        if ( isset( $input['account_sid'] ) ) {
            $sid = sanitize_text_field( $input['account_sid'] );
            $sanitized['account_sid'] = FRE_Twilio_Client::encrypt_value( $sid );
        }

        if ( isset( $input['auth_token'] ) ) {
            $token = sanitize_text_field( $input['auth_token'] );
            $sanitized['auth_token'] = FRE_Twilio_Client::encrypt_value( $token );
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
            <h1><?php esc_html_e( 'Twilio Text-Back', 'form-runtime-engine' ); ?></h1>

            <nav class="nav-tab-wrapper">
                <a href="?page=fre-twilio&tab=clients"
                   class="nav-tab <?php echo $active_tab === 'clients' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Clients', 'form-runtime-engine' ); ?>
                </a>
                <a href="?page=fre-twilio&tab=settings"
                   class="nav-tab <?php echo $active_tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <?php esc_html_e( 'Settings', 'form-runtime-engine' ); ?>
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
                            <?php esc_html_e( 'Account SID', 'form-runtime-engine' ); ?>
                        </label>
                    </th>
                    <td>
                        <input type="text"
                               id="fre_twilio_account_sid"
                               name="fre_twilio_settings[account_sid]"
                               value="<?php echo $has_creds ? '••••••••••••••••' : ''; ?>"
                               class="regular-text"
                               placeholder="<?php esc_attr_e( 'AC...', 'form-runtime-engine' ); ?>"
                               autocomplete="off" />
                        <p class="description">
                            <?php esc_html_e( 'Found in your Twilio Console dashboard.', 'form-runtime-engine' ); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="fre_twilio_auth_token">
                            <?php esc_html_e( 'Auth Token', 'form-runtime-engine' ); ?>
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
                            <?php esc_html_e( 'Found in your Twilio Console dashboard.', 'form-runtime-engine' ); ?>
                        </p>
                    </td>
                </tr>
            </table>

            <p>
                <button type="button" id="fre-twilio-test-connection" class="button button-secondary"
                        <?php echo ! $has_creds ? 'disabled' : ''; ?>>
                    <?php esc_html_e( 'Test Connection', 'form-runtime-engine' ); ?>
                </button>
                <span id="fre-twilio-test-result" style="margin-left: 10px;"></span>
            </p>

            <?php if ( $has_creds ) : ?>
                <div class="notice notice-info inline" style="margin-top: 10px;">
                    <p>
                        <strong><?php esc_html_e( 'Webhook URLs', 'form-runtime-engine' ); ?></strong><br>
                        <?php esc_html_e( 'Configure these in your Twilio phone number settings:', 'form-runtime-engine' ); ?>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Voice URL:', 'form-runtime-engine' ); ?></strong>
                        <code><?php echo esc_url( rest_url( 'fre-twilio/v1/incoming-call' ) ); ?></code>
                    </p>
                    <p>
                        <strong><?php esc_html_e( 'Messaging URL:', 'form-runtime-engine' ); ?></strong>
                        <code><?php echo esc_url( rest_url( 'fre-twilio/v1/incoming-sms' ) ); ?></code>
                    </p>
                </div>
            <?php endif; ?>

            <?php submit_button(); ?>
        </form>

        <script>
        jQuery(function($) {
            $('#fre-twilio-test-connection').on('click', function() {
                var $btn = $(this);
                var $result = $('#fre-twilio-test-result');
                $btn.prop('disabled', true);
                $result.text('Testing...');

                $.post(ajaxurl, {
                    action: 'fre_twilio_test_connection',
                    _wpnonce: '<?php echo wp_create_nonce( 'fre_twilio_admin' ); ?>'
                }, function(response) {
                    $btn.prop('disabled', false);
                    if (response.success) {
                        $result.html('<span style="color: green;">&#10004; Connected successfully</span>');
                    } else {
                        $result.html('<span style="color: red;">&#10008; ' + response.data + '</span>');
                    }
                }).fail(function() {
                    $btn.prop('disabled', false);
                    $result.html('<span style="color: red;">&#10008; Request failed</span>');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render the Clients tab.
     *
     * @param bool $has_creds Whether Twilio credentials are configured.
     */
    private function render_clients_tab( $has_creds ) {
        if ( ! $has_creds ) {
            echo '<div class="notice notice-warning inline"><p>';
            esc_html_e( 'Please configure your Twilio credentials in the Settings tab before adding clients.', 'form-runtime-engine' );
            echo '</p></div>';
            return;
        }

        $clients = $this->get_all_clients();
        ?>

        <div id="fre-twilio-clients">
            <h2>
                <?php esc_html_e( 'Client Phone Numbers', 'form-runtime-engine' ); ?>
                <button type="button" id="fre-twilio-add-client" class="page-title-action">
                    <?php esc_html_e( 'Add Client', 'form-runtime-engine' ); ?>
                </button>
            </h2>

            <?php if ( empty( $clients ) ) : ?>
                <p><?php esc_html_e( 'No clients configured yet. Click "Add Client" to set up your first text-back number.', 'form-runtime-engine' ); ?></p>
            <?php else : ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Client Name', 'form-runtime-engine' ); ?></th>
                            <th><?php esc_html_e( 'Twilio Number', 'form-runtime-engine' ); ?></th>
                            <th><?php esc_html_e( 'Owner Phone', 'form-runtime-engine' ); ?></th>
                            <th><?php esc_html_e( 'Status', 'form-runtime-engine' ); ?></th>
                            <th><?php esc_html_e( 'Actions', 'form-runtime-engine' ); ?></th>
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
                                        <span style="color: green;">&#9679; <?php esc_html_e( 'Active', 'form-runtime-engine' ); ?></span>
                                    <?php else : ?>
                                        <span style="color: gray;">&#9679; <?php esc_html_e( 'Inactive', 'form-runtime-engine' ); ?></span>
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
                                        <?php esc_html_e( 'Edit', 'form-runtime-engine' ); ?>
                                    </button>
                                    <button type="button" class="button button-small fre-twilio-toggle-client"
                                            data-id="<?php echo esc_attr( $client['id'] ); ?>"
                                            data-active="<?php echo esc_attr( $client['is_active'] ); ?>">
                                        <?php echo $client['is_active'] ? esc_html__( 'Deactivate', 'form-runtime-engine' ) : esc_html__( 'Activate', 'form-runtime-engine' ); ?>
                                    </button>
                                    <button type="button" class="button button-small button-link-delete fre-twilio-delete-client"
                                            data-id="<?php echo esc_attr( $client['id'] ); ?>"
                                            data-name="<?php echo esc_attr( $client['client_name'] ); ?>">
                                        <?php esc_html_e( 'Delete', 'form-runtime-engine' ); ?>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Add/Edit Client Modal -->
        <div id="fre-twilio-client-modal" style="display:none;">
            <div class="fre-twilio-modal-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.5);z-index:100000;overflow-y:auto;">
                <div style="position:relative;max-width:600px;margin:80px auto;background:#fff;padding:24px;border-radius:4px;max-height:calc(100vh - 160px);overflow-y:auto;">
                    <h2 id="fre-twilio-modal-title"><?php esc_html_e( 'Add Client', 'form-runtime-engine' ); ?></h2>
                    <input type="hidden" id="fre-twilio-client-id" value="" />

                    <table class="form-table">
                        <tr>
                            <th><label for="fre-twilio-client-name"><?php esc_html_e( 'Business Name', 'form-runtime-engine' ); ?></label></th>
                            <td><input type="text" id="fre-twilio-client-name" class="regular-text" placeholder="ABC Roofing" /></td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-client-number"><?php esc_html_e( 'Twilio Number', 'form-runtime-engine' ); ?></label></th>
                            <td>
                                <input type="text" id="fre-twilio-client-number" class="regular-text" placeholder="+15551112222" />
                                <p class="description"><?php esc_html_e( 'E.164 format (e.g., +15551112222)', 'form-runtime-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-owner-phone"><?php esc_html_e( 'Owner Phone', 'form-runtime-engine' ); ?></label></th>
                            <td>
                                <input type="text" id="fre-twilio-owner-phone" class="regular-text" placeholder="+15553334444" />
                                <p class="description"><?php esc_html_e( 'Business owner\u2019s personal number for call forwarding and notifications.', 'form-runtime-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-owner-email"><?php esc_html_e( 'Owner Email', 'form-runtime-engine' ); ?></label></th>
                            <td><input type="email" id="fre-twilio-owner-email" class="regular-text" placeholder="owner@abcroofing.com" /></td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-auto-reply"><?php esc_html_e( 'Auto-Reply Template', 'form-runtime-engine' ); ?></label></th>
                            <td>
                                <textarea id="fre-twilio-auto-reply" rows="4" class="large-text"
                                    placeholder="Thanks for calling {business_name}! Sorry we missed you. How can we help?"
                                ></textarea>
                                <p class="description"><?php esc_html_e( 'Use {business_name} as a placeholder. Sent to callers when a call is missed.', 'form-runtime-engine' ); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="fre-twilio-webhook-url"><?php esc_html_e( 'Webhook URL', 'form-runtime-engine' ); ?></label></th>
                            <td>
                                <input type="url" id="fre-twilio-webhook-url" class="large-text" placeholder="https://script.google.com/macros/s/..." />
                                <p class="description"><?php esc_html_e( 'Google Apps Script endpoint for logging leads to this client\u2019s Google Sheet.', 'form-runtime-engine' ); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p>
                        <button type="button" id="fre-twilio-save-client" class="button button-primary">
                            <?php esc_html_e( 'Save Client', 'form-runtime-engine' ); ?>
                        </button>
                        <button type="button" id="fre-twilio-cancel-modal" class="button">
                            <?php esc_html_e( 'Cancel', 'form-runtime-engine' ); ?>
                        </button>
                        <span id="fre-twilio-save-result" style="margin-left: 10px;"></span>
                    </p>
                </div>
            </div>
        </div>

        <script>
        jQuery(function($) {
            var nonce = '<?php echo wp_create_nonce( 'fre_twilio_admin' ); ?>';

            // Add client button.
            $('#fre-twilio-add-client').on('click', function() {
                $('#fre-twilio-modal-title').text('Add Client');
                $('#fre-twilio-client-id').val('');
                $('#fre-twilio-client-name, #fre-twilio-client-number, #fre-twilio-owner-phone, #fre-twilio-owner-email, #fre-twilio-webhook-url').val('');
                $('#fre-twilio-auto-reply').val('Thanks for calling {business_name}! Sorry we missed you. How can we help?');
                $('#fre-twilio-client-modal').show();
            });

            // Cancel modal.
            $('#fre-twilio-cancel-modal, .fre-twilio-modal-overlay').on('click', function(e) {
                if (e.target === this) {
                    $('#fre-twilio-client-modal').hide();
                }
            });

            // Edit client button.
            $('.fre-twilio-edit-client').on('click', function() {
                var $btn = $(this);
                $('#fre-twilio-modal-title').text('Edit Client');
                $('#fre-twilio-client-id').val($btn.data('id'));
                $('#fre-twilio-client-name').val($btn.data('name'));
                $('#fre-twilio-client-number').val($btn.data('number'));
                $('#fre-twilio-owner-phone').val($btn.data('owner-phone'));
                $('#fre-twilio-owner-email').val($btn.data('owner-email'));
                $('#fre-twilio-auto-reply').val($btn.data('auto-reply'));
                $('#fre-twilio-webhook-url').val($btn.data('webhook-url'));
                $('#fre-twilio-client-modal').show();
            });

            // Save client.
            $('#fre-twilio-save-client').on('click', function() {
                var $result = $('#fre-twilio-save-result');
                $result.text('Saving...');

                $.post(ajaxurl, {
                    action: 'fre_twilio_save_client',
                    _wpnonce: nonce,
                    id: $('#fre-twilio-client-id').val(),
                    client_name: $('#fre-twilio-client-name').val(),
                    twilio_number: $('#fre-twilio-client-number').val(),
                    owner_phone: $('#fre-twilio-owner-phone').val(),
                    owner_email: $('#fre-twilio-owner-email').val(),
                    auto_reply_template: $('#fre-twilio-auto-reply').val(),
                    webhook_url: $('#fre-twilio-webhook-url').val()
                }, function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        $result.html('<span style="color:red;">' + response.data + '</span>');
                    }
                });
            });

            // Toggle client active/inactive.
            $('.fre-twilio-toggle-client').on('click', function() {
                var $btn = $(this);
                $.post(ajaxurl, {
                    action: 'fre_twilio_toggle_client',
                    _wpnonce: nonce,
                    id: $btn.data('id')
                }, function(response) {
                    if (response.success) { location.reload(); }
                });
            });

            // Delete client.
            $('.fre-twilio-delete-client').on('click', function() {
                var name = $(this).data('name');
                if (!confirm('Delete client "' + name + '"? This cannot be undone.')) return;

                $.post(ajaxurl, {
                    action: 'fre_twilio_delete_client',
                    _wpnonce: nonce,
                    id: $(this).data('id')
                }, function(response) {
                    if (response.success) { location.reload(); }
                });
            });
        });
        </script>
        <?php
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

        // Toggle the is_active value.
        $this->wpdb->query(
            $this->wpdb->prepare(
                "UPDATE {$this->clients_table} SET is_active = 1 - is_active, updated_at = %s WHERE id = %d",
                current_time( 'mysql' ),
                $client_id
            )
        );

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
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->clients_table} ORDER BY client_name ASC",
            ARRAY_A
        );
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
        $table = $wpdb->prefix . 'fre_twilio_clients';

        // Check if the table exists first.
        $exists = $wpdb->get_var(
            $wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
        );

        if ( $exists !== $table ) {
            return;
        }

        $clients = $wpdb->get_results(
            "SELECT form_id, client_name, webhook_url, webhook_secret FROM {$table} WHERE is_active = 1",
            ARRAY_A
        );

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
