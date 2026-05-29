<?php
/**
 * Claude Connection admin page.
 *
 * Lives under Form Entries → Claude Connection. Exposes:
 *   - Connector enable toggle (gate 1).
 *   - Entry-read toggle (gate 2).
 *   - Generate / Revoke Connection (App Password).
 *   - Placeholder for the Phase 3 setup command.
 *   - Link to CONNECTOR_SPEC.md.
 *
 * All state read/write delegates to PForms_Connector_Settings and to WordPress
 * core's WP_Application_Passwords. This class holds no state itself.
 *
 * @package FormRuntimeEngine
 *
 * phpcs:disable WordPress.Security.NonceVerification.Recommended
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Connector admin page controller.
 */
class PForms_Connector_Admin {

    /**
     * Submenu slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'pforms-claude-connection';

    /**
     * Nonce action shared by all connector-admin AJAX handlers.
     *
     * Matches the existing pforms_admin_nonce used by PForms_Admin so the same
     * localized nonce value can gate both sets of handlers — one nonce per
     * admin session is simpler than one per subsystem.
     *
     * @var string
     */
    const NONCE_ACTION = 'pforms_admin_nonce';

    /**
     * Hook suffix for the connector submenu page. Captured at registration
     * time so admin_enqueue_scripts can gate asset loading to just this
     * page — see enqueue_assets() below.
     *
     * @var string
     */
    private $page_hook = '';

    /**
     * Hook wiring.
     *
     * Called from the main plugin init(). Separate from the constructor so
     * the class can be unit-tested without WP hooks in the way.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

        // AJAX handlers — only logged-in admins with the capability can hit them.
        add_action( 'wp_ajax_pforms_connector_toggle_enabled', array( $this, 'ajax_toggle_enabled' ) );
        add_action( 'wp_ajax_pforms_connector_toggle_entry_read', array( $this, 'ajax_toggle_entry_read' ) );
        add_action( 'wp_ajax_pforms_connector_generate_password', array( $this, 'ajax_generate_password' ) );
        add_action( 'wp_ajax_pforms_connector_revoke_password', array( $this, 'ajax_revoke_password' ) );

        // MCP script download. Intentionally public (no auth) so the one-line
        // bash setup command can curl it without credentials. The script file
        // is static JavaScript (no secrets) — identical approach to how the
        // Promptless connector serves its equivalent.
        add_action( 'wp_ajax_pforms_download_connector', array( $this, 'ajax_download_connector' ) );
        add_action( 'wp_ajax_nopriv_pforms_download_connector', array( $this, 'ajax_download_connector' ) );
    }

    /**
     * Register the admin submenu under Form Entries.
     *
     * Priority 20 so this appears after the existing Form Entries menu items.
     */
    public function register_submenu() {
        // Menu label is the neutral "Connector" to match Promptless / PRE /
        // FlowMint and stay future-proof for additional AI client integrations
        // (Codex, ChatGPT Desktop, etc.). The page H1 carries the
        // plugin-specific name ("The Form Engine Connector"). Renamed from
        // "Claude Connection" 2026-05-16 — the connector itself is vendor-
        // neutral; only the current default client happens to be Claude.
        $this->page_hook = add_submenu_page(
            'pforms-entries',
            __( 'The Form Engine Connector', 'promptless-forms' ),
            __( 'Connector', 'promptless-forms' ),
            PForms_Capabilities::MANAGE_FORMS,
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /**
     * Enqueue the connector-admin CSS + JS — only on our page.
     *
     * @param string $hook_suffix Current admin page hook (passed by WP).
     */
    public function enqueue_assets( $hook_suffix ) {
        if ( '' === $this->page_hook || $hook_suffix !== $this->page_hook ) {
            return;
        }

        $plugin_url = plugins_url( '', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );

        wp_enqueue_style(
            'pforms-connector-admin',
            $plugin_url . '/assets/css/connector-admin.css',
            array(),
            PForms_VERSION
        );

        wp_enqueue_script(
            'pforms-connector-admin',
            $plugin_url . '/assets/js/connector-admin.js',
            array(),
            PForms_VERSION,
            true
        );

        wp_localize_script(
            'pforms-connector-admin',
            'pformsConnectorAdmin',
            array(
                'ajaxUrl'             => admin_url( 'admin-ajax.php' ),
                'nonce'               => wp_create_nonce( self::NONCE_ACTION ),
                'connectorScriptUrl'  => admin_url( 'admin-ajax.php?action=pforms_download_connector' ),
                'siteUrl'             => home_url(),
                'i18n'                => array(
                    'enabled'        => __( 'Enabled.', 'promptless-forms' ),
                    'disabled'       => __( 'Disabled.', 'promptless-forms' ),
                    'generating'     => __( 'Generating...', 'promptless-forms' ),
                    'configured'     => __( 'Configured', 'promptless-forms' ),
                    'regenerate'     => __( 'Regenerate Connection', 'promptless-forms' ),
                    'copied'         => __( 'Copied', 'promptless-forms' ),
                    'revokeConfirm'  => __( 'Revoke the connector App Password? Claude Cowork will lose access immediately.', 'promptless-forms' ),
                ),
            )
        );
    }

    /**
     * Render the admin page.
     *
     * Kept intentionally simple — inline form + small script. This page sees
     * low traffic (one admin configuring, then forgotten). Optimizing asset
     * load for it is not worth the complexity.
     */
    public function render_page() {
        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        $is_enabled             = PForms_Connector_Settings::is_enabled();
        $is_entry_read_enabled  = PForms_Connector_Settings::is_entry_read_enabled();
        $configured_at          = PForms_Connector_Settings::configured_at();
        $current_user           = wp_get_current_user();
        $rest_base_url          = rest_url( PForms_Connector_API::NAMESPACE_PREFIX . '/' . PForms_Connector_API::ROUTE_BASE );
        $spec_url               = plugins_url( 'docs/CONNECTOR_SPEC.md', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );
        $mcp_setup_url          = plugins_url( 'docs/MCP_CONNECTOR_SETUP.md', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );

        // The nonce, connector-script URL and site URL that used to be
        // computed here for inline-JS interpolation now flow through
        // wp_localize_script() in enqueue_assets() above. Don't recompute.

        // App-password availability check — see PRE/Promptless equivalents
        // for the rationale. Returns true on HTTPS sites OR local dev
        // environments via WP_ENVIRONMENT_TYPE='local'.
        $app_passwords_available = wp_is_application_passwords_available();
        ?>
        <div class="wrap fre-connector-settings">
            <h1><?php esc_html_e( 'The Form Engine Connector', 'promptless-forms' ); ?></h1>
            <p class="fre-connector-subtitle">
                <?php esc_html_e( 'Connect Claude Desktop to your WordPress site so it can create, update, and (optionally) read forms.', 'promptless-forms' ); ?>
            </p>

            <?php if ( ! $app_passwords_available ) : ?>
                <?php // is-dismissible: notice is page-scoped (only renders on the connector settings page). Re-evaluates on every page load — if app passwords become available, notice stops appearing. Dismissal is harmless UX (the underlying limitation persists; reload re-evaluates). ?>
                <div class="notice notice-warning is-dismissible" style="margin: 12px 0 20px;">
                    <p><strong><?php esc_html_e( 'Application passwords not available on this site.', 'promptless-forms' ); ?></strong>
                    <?php esc_html_e( "WordPress requires either HTTPS or a local environment to issue application passwords. Until that's set up, the \"Generate Connection\" button will return an error.", 'promptless-forms' ); ?></p>
                    <ul style="margin: 6px 0 6px 24px; list-style: disc;">
                        <li><?php echo wp_kses( __( '<strong>On a production site:</strong> enable HTTPS / install an SSL certificate.', 'promptless-forms' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( "<strong>For local development:</strong> add <code>define('WP_ENVIRONMENT_TYPE', 'local');</code> to your <code>wp-config.php</code>. Most local environments (Local by Flywheel, wp-env, LocalWP) set this automatically.", 'promptless-forms' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Connection Status card. Status pill + the two security
                 toggles (site-wide kill-switch + entry-read permission)
                 live here so the 3-step setup below stays focused on the
                 install flow. Entry-read sits next to kill-switch because
                 they are both permission decisions, not setup steps. -->
            <div class="fre-connector-card" id="fre-connector-status-card">
                <h2><?php esc_html_e( 'Connection Status', 'promptless-forms' ); ?></h2>
                <div class="fre-connector-status-row">
                    <span class="fre-connector-status-badge <?php echo $configured_at > 0 ? 'fre-connector-status-active' : 'fre-connector-status-inactive'; ?>" id="fre-connector-status-pill">
                        <?php echo $configured_at > 0 ? esc_html__( 'Configured', 'promptless-forms' ) : esc_html__( 'Not Connected', 'promptless-forms' ); ?>
                    </span>
                    <label class="fre-connector-killswitch">
                        <input type="checkbox"
                            id="fre-connector-enabled"
                            <?php checked( $is_enabled ); ?>
                            data-ajax-action="pforms_connector_toggle_enabled"
                        >
                        <span><?php esc_html_e( 'Allow Claude Cowork to call this site', 'promptless-forms' ); ?></span>
                        <span class="fre-connector-toggle-status" id="fre-enabled-status" aria-live="polite"></span>
                    </label>
                </div>

                <!-- Entry-read permission — separate row so the label has room
                     for its long description. Off by default. Lets Cowork
                     read submission data (names, emails, message bodies).
                     Distinct from the kill-switch above which controls
                     ALL connector access. -->
                <label class="fre-connector-permission-toggle">
                    <input type="checkbox"
                        id="fre-connector-entry-read"
                        <?php checked( $is_entry_read_enabled ); ?>
                        data-ajax-action="pforms_connector_toggle_entry_read"
                    >
                    <span class="fre-connector-permission-label"><?php esc_html_e( 'Also allow Claude Cowork to read form submissions', 'promptless-forms' ); ?></span>
                    <span class="fre-connector-toggle-status" id="fre-entry-read-status" aria-live="polite"></span>
                </label>
                <p class="fre-connector-status-help">
                    <?php if ( $configured_at > 0 ) : ?>
                        <?php
                        printf(
                            /* translators: %s: localized timestamp */
                            esc_html__( 'Last configured: %s. The entry-read toggle controls whether Cowork can read submission data (names, emails, message bodies) — off by default for privacy.', 'promptless-forms' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Follow the steps below to connect Claude Desktop to your site. The entry-read toggle above controls whether Cowork can read submission data — off by default for privacy.', 'promptless-forms' ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Step 1: Generate Connection -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 1: Generate Connection', 'promptless-forms' ); ?></h2>
                <p><?php esc_html_e( 'This creates a secure application password that allows Claude to communicate with your site. Any existing connection will be replaced.', 'promptless-forms' ); ?></p>
                <p>
                    <button type="button" id="fre-generate-password-btn" class="button button-primary">
                        <?php
                        echo $configured_at > 0
                            ? esc_html__( 'Regenerate Connection', 'promptless-forms' )
                            : esc_html__( 'Generate Connection', 'promptless-forms' );
                        ?>
                    </button>
                    <?php if ( $configured_at > 0 ) : ?>
                        <button type="button" id="fre-revoke-password-btn" class="button">
                            <?php esc_html_e( 'Revoke Connection', 'promptless-forms' ); ?>
                        </button>
                    <?php endif; ?>
                </p>

                <div id="fre-credential-display" class="fre-connector-success-notice" style="display:none;">
                    <p><strong><?php esc_html_e( 'Connection generated successfully!', 'promptless-forms' ); ?></strong> <?php esc_html_e( 'Now proceed to Step 2.', 'promptless-forms' ); ?></p>
                </div>
            </div>

            <!-- Step 2: Run Setup Command -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 2: Run Setup Command', 'promptless-forms' ); ?></h2>
                <p><?php esc_html_e( 'Copy the command below and paste it into', 'promptless-forms' ); ?> <strong><?php esc_html_e( 'Terminal', 'promptless-forms' ); ?></strong> <?php esc_html_e( 'on your Mac. This automatically installs and configures the Form Engine Connector.', 'promptless-forms' ); ?></p>

                <div class="fre-connector-requirements">
                    <strong><?php esc_html_e( 'Requirements:', 'promptless-forms' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'macOS with Terminal', 'promptless-forms' ); ?></li>
                        <li><?php esc_html_e( 'Node.js installed (v14 or higher)', 'promptless-forms' ); ?></li>
                        <li><?php esc_html_e( 'Claude Desktop app installed', 'promptless-forms' ); ?></li>
                    </ul>
                </div>

                <div id="fre-setup-command-container" style="display:none;">
                    <div class="fre-connector-code-block">
                        <pre id="fre-setup-command"></pre>
                        <button type="button" class="button fre-connector-copy-btn" id="fre-copy-setup-command"><?php esc_html_e( 'Copy Command', 'promptless-forms' ); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e( 'After running the command, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next session.', 'promptless-forms' ); ?></p>
                </div>

                <div id="fre-setup-command-placeholder">
                    <p class="description" style="color:#999;">
                        <?php if ( $configured_at > 0 ) : ?>
                            <?php esc_html_e( 'Your connection is configured. To see the setup command again, click "Regenerate Connection" in Step 1.', 'promptless-forms' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Generate a connection in Step 1 first, then your setup command will appear here.', 'promptless-forms' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Step 3: Verify Connection -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 3: Verify Connection', 'promptless-forms' ); ?></h2>
                <p><?php esc_html_e( 'After running the setup command and restarting Claude Desktop, start a new conversation and type:', 'promptless-forms' ); ?></p>
                <div class="fre-connector-code-block">
                    <pre><?php esc_html_e( 'List the forms on my WordPress site.', 'promptless-forms' ); ?></pre>
                </div>
                <p><?php esc_html_e( 'Claude should respond with your forms, confirming the connection is active.', 'promptless-forms' ); ?></p>
            </div>

            <!-- Developer info — collapsed by default. Hides technical refs
                 (REST endpoint URL, spec link, MCP setup notes) that end
                 users do not need but devs may want for debugging or
                 scripting. -->
            <details class="fre-connector-dev-info">
                <summary><?php esc_html_e( 'Developer info', 'promptless-forms' ); ?></summary>
                <dl>
                    <dt><?php esc_html_e( 'REST base URL', 'promptless-forms' ); ?></dt>
                    <dd><code><?php echo esc_html( $rest_base_url ); ?></code></dd>
                    <dt><?php esc_html_e( 'Authenticated user', 'promptless-forms' ); ?></dt>
                    <dd><code><?php echo esc_html( $current_user->user_login ); ?></code></dd>
                    <dt><?php esc_html_e( 'Documentation', 'promptless-forms' ); ?></dt>
                    <dd>
                        <a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connector specification', 'promptless-forms' ); ?></a>
                        &middot;
                        <a href="<?php echo esc_url( $mcp_setup_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MCP setup notes', 'promptless-forms' ); ?></a>
                    </dd>
                </dl>
            </details>
        </div>
        <?php
        // Styles + JS for this page are enqueued via enqueue_assets() — the
        // page-hook gate keeps them off other admin screens. No inline
        // <style> / <script> blocks here (Plugin Check guideline). Asset
        // files live at assets/css/connector-admin.css + assets/js/connector-admin.js.
    }

    /*
     * LEGACY MARKER (1.6.5):
     * The inline styles and JS that previously lived in render_page() were
     * extracted to:
     *   - assets/css/connector-admin.css
     *   - assets/js/connector-admin.js
     * Dynamic data (ajax URL, nonce, connector script URL, site URL, i18n
     * strings) flows from PHP → JS through wp_localize_script() in
     * enqueue_assets() above. If you're editing the connector admin UI
     * behavior, those are the files to touch — not this one.
     */

    // ---------------------------------------------------------------------
    // AJAX handlers
    // ---------------------------------------------------------------------

    /**
     * Verify AJAX request's nonce and capability. Send JSON error and halt on
     * failure. Returns normally when OK.
     */
    private function verify_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( PForms_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'promptless-forms' ) ), 403 );
        }
    }

    /**
     * AJAX: toggle the connector enable flag.
     */
    public function ajax_toggle_enabled() {
        $this->verify_ajax(); // Calls check_ajax_referer() + capability check on line 597.

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above in verify_ajax() via check_ajax_referer().
        $enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        PForms_Connector_Settings::set_enabled( $enabled );

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __( 'Connector enabled.', 'promptless-forms' )
                : __( 'Connector disabled.', 'promptless-forms' ),
        ) );
    }

    /**
     * AJAX: toggle the entry-read flag.
     */
    public function ajax_toggle_entry_read() {
        $this->verify_ajax(); // Calls check_ajax_referer() + capability check on line 597.

        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified above in verify_ajax() via check_ajax_referer().
        $enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        PForms_Connector_Settings::set_entry_read_enabled( $enabled );

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __( 'Entry read access enabled.', 'promptless-forms' )
                : __( 'Entry read access disabled.', 'promptless-forms' ),
        ) );
    }

    /**
     * AJAX: generate a new App Password for the current user.
     *
     * Revokes any prior connector App Password for this user before creating
     * the new one. The plaintext password is returned in the response for
     * one-time display; it is never stored by this plugin.
     */
    public function ajax_generate_password() {
        $this->verify_ajax();

        if ( ! class_exists( 'WP_Application_Passwords' ) ) {
            wp_send_json_error( array(
                'message' => __( 'This WordPress version does not support Application Passwords. Requires WordPress 5.6+.', 'promptless-forms' ),
            ) );
        }

        $user_id = get_current_user_id();

        // Revoke any prior Promptless Forms App Password for this user.
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && PForms_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                }
            }
        }

        $created = WP_Application_Passwords::create_new_application_password(
            $user_id,
            array( 'name' => PForms_Connector_Settings::APP_PASSWORD_NAME )
        );

        if ( is_wp_error( $created ) ) {
            wp_send_json_error( array( 'message' => $created->get_error_message() ) );
        }

        // WP returns [ $password_string, $item_metadata ].
        list( $password_string, $item ) = $created;

        PForms_Connector_Settings::mark_configured( $user_id );

        $current_user = wp_get_current_user();

        wp_send_json_success( array(
            'username' => $current_user->user_login,
            'password' => $password_string,
            'uuid'     => $item['uuid'] ?? '',
            'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', 'promptless-forms' ),
        ) );
    }

    /**
     * AJAX: serve the MCP connector JavaScript file.
     *
     * Intentionally public — no nonce, no capability check. The served file is
     * a static JavaScript MCP server with no embedded secrets; it reads
     * credentials from environment variables at runtime. Keeping this endpoint
     * unauthenticated lets the one-line bash setup command curl it without
     * juggling cookies or tokens.
     *
     * Route: /wp-admin/admin-ajax.php?action=pforms_download_connector
     */
    public function ajax_download_connector() {
        $path = PForms_PLUGIN_DIR . 'includes/Connector/assets/form-engine-connector.js';

        if ( ! file_exists( $path ) ) {
            status_header( 404 );
            echo '// Promptless Forms connector script not found on this install.';
            exit;
        }

        header( 'Content-Type: application/javascript; charset=utf-8' );
        header( 'Content-Disposition: inline; filename="form-engine-connector.js"' );
        header( 'Cache-Control: no-cache, must-revalidate' );

        // File is a static plugin-shipped JavaScript asset (the MCP connector),
        // not user-controlled input. Output must be raw JS, so it is intentionally
        // not run through esc_*; the content is plugin-controlled and has no XSS surface.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.Security.EscapeOutput.OutputNotEscaped
        echo file_get_contents( $path );
        exit;
    }

    /**
     * AJAX: revoke the connector App Password for the current user.
     */
    public function ajax_revoke_password() {
        $this->verify_ajax();

        if ( ! class_exists( 'WP_Application_Passwords' ) ) {
            wp_send_json_error( array(
                'message' => __( 'WordPress 5.6+ is required.', 'promptless-forms' ),
            ) );
        }

        $user_id  = get_current_user_id();
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        $count    = 0;

        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && PForms_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                    $count++;
                }
            }
        }

        PForms_Connector_Settings::clear_configured( $user_id );

        wp_send_json_success( array(
            'revoked_count' => $count,
            'message'       => sprintf(
                /* translators: %d: count of revoked app passwords */
                _n( '%d connector credential revoked.', '%d connector credentials revoked.', $count, 'promptless-forms' ),
                $count
            ),
        ) );
    }
}
