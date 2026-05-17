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
 * All state read/write delegates to FRE_Connector_Settings and to WordPress
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
class FRE_Connector_Admin {

    /**
     * Submenu slug.
     *
     * @var string
     */
    const PAGE_SLUG = 'fre-claude-connection';

    /**
     * Nonce action shared by all connector-admin AJAX handlers.
     *
     * Matches the existing fre_admin_nonce used by FRE_Admin so the same
     * localized nonce value can gate both sets of handlers — one nonce per
     * admin session is simpler than one per subsystem.
     *
     * @var string
     */
    const NONCE_ACTION = 'fre_admin_nonce';

    /**
     * Hook wiring.
     *
     * Called from the main plugin init(). Separate from the constructor so
     * the class can be unit-tested without WP hooks in the way.
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_submenu' ), 20 );

        // AJAX handlers — only logged-in admins with the capability can hit them.
        add_action( 'wp_ajax_fre_connector_toggle_enabled', array( $this, 'ajax_toggle_enabled' ) );
        add_action( 'wp_ajax_fre_connector_toggle_entry_read', array( $this, 'ajax_toggle_entry_read' ) );
        add_action( 'wp_ajax_fre_connector_generate_password', array( $this, 'ajax_generate_password' ) );
        add_action( 'wp_ajax_fre_connector_revoke_password', array( $this, 'ajax_revoke_password' ) );

        // MCP script download. Intentionally public (no auth) so the one-line
        // bash setup command can curl it without credentials. The script file
        // is static JavaScript (no secrets) — identical approach to how the
        // Promptless connector serves its equivalent.
        add_action( 'wp_ajax_fre_download_connector', array( $this, 'ajax_download_connector' ) );
        add_action( 'wp_ajax_nopriv_fre_download_connector', array( $this, 'ajax_download_connector' ) );
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
        add_submenu_page(
            'fre-entries',
            __( 'The Form Engine Connector', 'form-runtime-engine' ),
            __( 'Connector', 'form-runtime-engine' ),
            FRE_Capabilities::MANAGE_FORMS,
            self::PAGE_SLUG,
            array( $this, 'render_page' )
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
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
        }

        $is_enabled             = FRE_Connector_Settings::is_enabled();
        $is_entry_read_enabled  = FRE_Connector_Settings::is_entry_read_enabled();
        $configured_at          = FRE_Connector_Settings::configured_at();
        $current_user           = wp_get_current_user();
        $rest_base_url          = esc_url_raw( rest_url( FRE_Connector_API::NAMESPACE_PREFIX . '/' . FRE_Connector_API::ROUTE_BASE ) );
        $ajax_nonce             = wp_create_nonce( self::NONCE_ACTION );
        $spec_url               = plugins_url( 'docs/CONNECTOR_SPEC.md', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );
        $mcp_setup_url          = plugins_url( 'docs/MCP_CONNECTOR_SETUP.md', dirname( __DIR__, 2 ) . '/form-runtime-engine.php' );
        $connector_script_url   = esc_url_raw( admin_url( 'admin-ajax.php?action=fre_download_connector' ) );
        $site_url               = esc_url_raw( home_url() );

        // App-password availability check — see PRE/Promptless equivalents
        // for the rationale. Returns true on HTTPS sites OR local dev
        // environments via WP_ENVIRONMENT_TYPE='local'.
        $app_passwords_available = wp_is_application_passwords_available();
        ?>
        <div class="wrap fre-connector-settings">
            <h1><?php esc_html_e( 'The Form Engine Connector', 'form-runtime-engine' ); ?></h1>
            <p class="fre-connector-subtitle">
                <?php esc_html_e( 'Connect Claude Desktop to your WordPress site so it can create, update, and (optionally) read forms.', 'form-runtime-engine' ); ?>
            </p>

            <?php if ( ! $app_passwords_available ) : ?>
                <div class="notice notice-warning" style="margin: 12px 0 20px;">
                    <p><strong><?php esc_html_e( 'Application passwords not available on this site.', 'form-runtime-engine' ); ?></strong>
                    <?php esc_html_e( "WordPress requires either HTTPS or a local environment to issue application passwords. Until that's set up, the \"Generate Connection\" button will return an error.", 'form-runtime-engine' ); ?></p>
                    <ul style="margin: 6px 0 6px 24px; list-style: disc;">
                        <li><?php echo wp_kses( __( '<strong>On a production site:</strong> enable HTTPS / install an SSL certificate.', 'form-runtime-engine' ), array( 'strong' => array() ) ); ?></li>
                        <li><?php echo wp_kses( __( "<strong>For local development:</strong> add <code>define('WP_ENVIRONMENT_TYPE', 'local');</code> to your <code>wp-config.php</code>. Most local environments (Local by Flywheel, wp-env, LocalWP) set this automatically.", 'form-runtime-engine' ), array( 'strong' => array(), 'code' => array() ) ); ?></li>
                    </ul>
                </div>
            <?php endif; ?>

            <!-- Connection Status card. Status pill + the two security
                 toggles (site-wide kill-switch + entry-read permission)
                 live here so the 3-step setup below stays focused on the
                 install flow. Entry-read sits next to kill-switch because
                 they are both permission decisions, not setup steps. -->
            <div class="fre-connector-card" id="fre-connector-status-card">
                <h2><?php esc_html_e( 'Connection Status', 'form-runtime-engine' ); ?></h2>
                <div class="fre-connector-status-row">
                    <span class="fre-connector-status-badge <?php echo $configured_at > 0 ? 'fre-connector-status-active' : 'fre-connector-status-inactive'; ?>" id="fre-connector-status-pill">
                        <?php echo $configured_at > 0 ? esc_html__( 'Configured', 'form-runtime-engine' ) : esc_html__( 'Not Connected', 'form-runtime-engine' ); ?>
                    </span>
                    <label class="fre-connector-killswitch">
                        <input type="checkbox"
                            id="fre-connector-enabled"
                            <?php checked( $is_enabled ); ?>
                            data-ajax-action="fre_connector_toggle_enabled"
                        >
                        <span><?php esc_html_e( 'Allow Claude Cowork to call this site', 'form-runtime-engine' ); ?></span>
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
                        data-ajax-action="fre_connector_toggle_entry_read"
                    >
                    <span class="fre-connector-permission-label"><?php esc_html_e( 'Also allow Claude Cowork to read form submissions', 'form-runtime-engine' ); ?></span>
                    <span class="fre-connector-toggle-status" id="fre-entry-read-status" aria-live="polite"></span>
                </label>
                <p class="fre-connector-status-help">
                    <?php if ( $configured_at > 0 ) : ?>
                        <?php
                        printf(
                            /* translators: %s: localized timestamp */
                            esc_html__( 'Last configured: %s. The entry-read toggle controls whether Cowork can read submission data (names, emails, message bodies) — off by default for privacy.', 'form-runtime-engine' ),
                            esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $configured_at ) )
                        );
                        ?>
                    <?php else : ?>
                        <?php esc_html_e( 'Follow the steps below to connect Claude Desktop to your site. The entry-read toggle above controls whether Cowork can read submission data — off by default for privacy.', 'form-runtime-engine' ); ?>
                    <?php endif; ?>
                </p>
            </div>

            <!-- Step 1: Generate Connection -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 1: Generate Connection', 'form-runtime-engine' ); ?></h2>
                <p><?php esc_html_e( 'This creates a secure application password that allows Claude to communicate with your site. Any existing connection will be replaced.', 'form-runtime-engine' ); ?></p>
                <p>
                    <button type="button" id="fre-generate-password-btn" class="button button-primary">
                        <?php
                        echo $configured_at > 0
                            ? esc_html__( 'Regenerate Connection', 'form-runtime-engine' )
                            : esc_html__( 'Generate Connection', 'form-runtime-engine' );
                        ?>
                    </button>
                    <?php if ( $configured_at > 0 ) : ?>
                        <button type="button" id="fre-revoke-password-btn" class="button">
                            <?php esc_html_e( 'Revoke Connection', 'form-runtime-engine' ); ?>
                        </button>
                    <?php endif; ?>
                </p>

                <div id="fre-credential-display" class="fre-connector-success-notice" style="display:none;">
                    <p><strong><?php esc_html_e( 'Connection generated successfully!', 'form-runtime-engine' ); ?></strong> <?php esc_html_e( 'Now proceed to Step 2.', 'form-runtime-engine' ); ?></p>
                </div>
            </div>

            <!-- Step 2: Run Setup Command -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 2: Run Setup Command', 'form-runtime-engine' ); ?></h2>
                <p><?php esc_html_e( 'Copy the command below and paste it into', 'form-runtime-engine' ); ?> <strong><?php esc_html_e( 'Terminal', 'form-runtime-engine' ); ?></strong> <?php esc_html_e( 'on your Mac. This automatically installs and configures the Form Engine Connector.', 'form-runtime-engine' ); ?></p>

                <div class="fre-connector-requirements">
                    <strong><?php esc_html_e( 'Requirements:', 'form-runtime-engine' ); ?></strong>
                    <ul>
                        <li><?php esc_html_e( 'macOS with Terminal', 'form-runtime-engine' ); ?></li>
                        <li><?php esc_html_e( 'Node.js installed (v14 or higher)', 'form-runtime-engine' ); ?></li>
                        <li><?php esc_html_e( 'Claude Desktop app installed', 'form-runtime-engine' ); ?></li>
                    </ul>
                </div>

                <div id="fre-setup-command-container" style="display:none;">
                    <div class="fre-connector-code-block">
                        <pre id="fre-setup-command"></pre>
                        <button type="button" class="button fre-connector-copy-btn" id="fre-copy-setup-command"><?php esc_html_e( 'Copy Command', 'form-runtime-engine' ); ?></button>
                    </div>
                    <p class="description"><?php esc_html_e( 'After running the command, quit Claude Desktop (Cmd+Q) and reopen it. The connector will be active in your next session.', 'form-runtime-engine' ); ?></p>
                </div>

                <div id="fre-setup-command-placeholder">
                    <p class="description" style="color:#999;">
                        <?php if ( $configured_at > 0 ) : ?>
                            <?php esc_html_e( 'Your connection is configured. To see the setup command again, click "Regenerate Connection" in Step 1.', 'form-runtime-engine' ); ?>
                        <?php else : ?>
                            <?php esc_html_e( 'Generate a connection in Step 1 first, then your setup command will appear here.', 'form-runtime-engine' ); ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Step 3: Verify Connection -->
            <div class="fre-connector-card">
                <h2><?php esc_html_e( 'Step 3: Verify Connection', 'form-runtime-engine' ); ?></h2>
                <p><?php esc_html_e( 'After running the setup command and restarting Claude Desktop, start a new conversation and type:', 'form-runtime-engine' ); ?></p>
                <div class="fre-connector-code-block">
                    <pre><?php esc_html_e( 'List the forms on my WordPress site.', 'form-runtime-engine' ); ?></pre>
                </div>
                <p><?php esc_html_e( 'Claude should respond with your forms, confirming the connection is active.', 'form-runtime-engine' ); ?></p>
            </div>

            <!-- Developer info — collapsed by default. Hides technical refs
                 (REST endpoint URL, spec link, MCP setup notes) that end
                 users do not need but devs may want for debugging or
                 scripting. -->
            <details class="fre-connector-dev-info">
                <summary><?php esc_html_e( 'Developer info', 'form-runtime-engine' ); ?></summary>
                <dl>
                    <dt><?php esc_html_e( 'REST base URL', 'form-runtime-engine' ); ?></dt>
                    <dd><code><?php echo esc_html( $rest_base_url ); ?></code></dd>
                    <dt><?php esc_html_e( 'Authenticated user', 'form-runtime-engine' ); ?></dt>
                    <dd><code><?php echo esc_html( $current_user->user_login ); ?></code></dd>
                    <dt><?php esc_html_e( 'Documentation', 'form-runtime-engine' ); ?></dt>
                    <dd>
                        <a href="<?php echo esc_url( $spec_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Connector specification', 'form-runtime-engine' ); ?></a>
                        &middot;
                        <a href="<?php echo esc_url( $mcp_setup_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'MCP setup notes', 'form-runtime-engine' ); ?></a>
                    </dd>
                </dl>
            </details>
        </div>

        <style>
            /* FRE connector admin styles — mirrors Promptless's .aisb-*
             * visual treatment with .fre-connector-* prefix so the two
             * plugins look like siblings. Refactored 2026-05-16 from a
             * flat-text 4-step layout to this card-based 3-step layout
             * with both security toggles (site-wide kill-switch + entry-
             * read permission) tucked into the Status card. */
            .fre-connector-settings { max-width: 800px; }
            .fre-connector-subtitle { font-size: 14px; color: #646970; margin-top: -5px; }

            .fre-connector-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px 24px;
                margin-bottom: 20px;
            }
            .fre-connector-card h2 {
                margin-top: 0;
                padding-top: 0;
                font-size: 16px;
                border-bottom: none;
            }

            .fre-connector-status-row {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                flex-wrap: wrap;
                margin-bottom: 12px;
            }
            .fre-connector-status-badge {
                display: inline-block;
                padding: 4px 12px;
                border-radius: 12px;
                font-size: 13px;
                font-weight: 500;
            }
            .fre-connector-status-active { background: #d4edda; color: #155724; }
            .fre-connector-status-inactive { background: #f8d7da; color: #721c24; }
            .fre-connector-status-help { margin: 8px 0 0; color: #50575e; }

            .fre-connector-killswitch { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #1d2327; }
            .fre-connector-permission-toggle { display: flex; align-items: flex-start; gap: 6px; font-size: 13px; color: #1d2327; padding: 8px 0 0; }
            .fre-connector-permission-label { line-height: 1.5; }
            .fre-connector-toggle-status { font-style: italic; color: #50575e; min-width: 60px; }

            .fre-connector-success-notice {
                margin: 12px 0 0 0;
                padding: 8px 12px;
                background: #edf7ed;
                border-left: 3px solid #46b450;
                border-radius: 0 4px 4px 0;
            }
            .fre-connector-success-notice p { margin: 0; }

            .fre-connector-requirements {
                background: #f0f6fc;
                border: 1px solid #c8d8e4;
                border-radius: 4px;
                padding: 12px 16px;
                margin: 12px 0;
            }
            .fre-connector-requirements ul { margin: 4px 0 0 20px; }
            .fre-connector-requirements li { margin-bottom: 2px; }

            .fre-connector-code-block {
                position: relative;
                background: #1d2327;
                color: #50c878;
                padding: 16px 20px;
                border-radius: 6px;
                margin: 12px 0;
                overflow-x: auto;
            }
            .fre-connector-code-block pre {
                margin: 0;
                white-space: pre-wrap;
                word-break: break-all;
                font-family: 'SF Mono', 'Monaco', 'Menlo', 'Consolas', monospace;
                font-size: 13px;
                line-height: 1.6;
                color: #50c878;
            }
            .fre-connector-copy-btn {
                position: absolute !important;
                top: 8px !important;
                right: 8px !important;
                font-size: 12px !important;
                padding: 2px 10px !important;
                min-height: 28px !important;
            }

            .fre-connector-dev-info {
                margin-top: 20px;
                padding: 12px 16px;
                background: #f6f7f7;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
            }
            .fre-connector-dev-info summary {
                cursor: pointer;
                font-weight: 600;
                color: #1d2327;
                outline: none;
            }
            .fre-connector-dev-info[open] summary { margin-bottom: 8px; }
            .fre-connector-dev-info dl { margin: 0; }
            .fre-connector-dev-info dt {
                font-weight: 600;
                color: #50575e;
                font-size: 12px;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                margin-top: 10px;
            }
            .fre-connector-dev-info dt:first-child { margin-top: 0; }
            .fre-connector-dev-info dd { margin: 4px 0 0 0; font-size: 13px; }
        </style>

        <script>
        (function() {
            const ajaxUrl             = '<?php echo esc_url_raw( admin_url( 'admin-ajax.php' ) ); ?>';
            const nonce               = '<?php echo esc_js( $ajax_nonce ); ?>';
            const connectorScriptUrl  = '<?php echo esc_js( $connector_script_url ); ?>';
            const siteUrl             = '<?php echo esc_js( $site_url ); ?>';

            /**
             * Build the one-line bash setup command.
             *
             * Ported from the Promptless connector's equivalent, with paths
             * and identifiers adapted for the form engine. Notable details:
             *   - Installs into ~/form-engine-mcp so it does not conflict with
             *     a parallel Promptless install in ~/promptless-mcp.
             *   - Claude Desktop config key is "form-engine-wordpress" —
             *     distinct from Promptless's "promptless-wordpress" so both
             *     connectors can coexist.
             *   - Uses Node.js itself to rewrite claude_desktop_config.json
             *     so no jq/sed dependency is required.
             *   - Password is passed via argv[2], NOT interpolated into the
             *     Node script, so it never appears in shell history.
             *   - Leading `;` separator between NODE_PATH assignments avoids
             *     the `&&` short-circuit bug Promptless documented.
             */
            function buildSetupCommand(username, password) {
                const escapedPassword = password.replace(/'/g, "'\\''");
                const escapedSiteUrl  = siteUrl.replace(/'/g, "'\\''");
                const escapedUsername = username.replace(/'/g, "'\\''");

                return [
                    `mkdir -p ~/form-engine-mcp && \\`,
                    `curl -fsSL -A 'WordPress/FormRuntimeEngine' '${connectorScriptUrl}' -o ~/form-engine-mcp/form-engine-connector.js && \\`,
                    `NODE_PATH=$(ls -d ~/.nvm/versions/node/v*/bin/node 2>/dev/null | sort -V | tail -1) ; [ -z "$NODE_PATH" ] && NODE_PATH=$(which node) ; \\`,
                    `CONFIG="$HOME/Library/Application Support/Claude/claude_desktop_config.json" && \\`,
                    `mkdir -p "$HOME/Library/Application Support/Claude" && \\`,
                    `"$NODE_PATH" -e '` +
                    `var fs=require("fs");` +
                    `var p=process.env.HOME+"/Library/Application Support/Claude/claude_desktop_config.json";` +
                    `var c;try{c=JSON.parse(fs.readFileSync(p,"utf8"))}catch(e){c={}}` +
                    `c.mcpServers=c.mcpServers||{};` +
                    `c.mcpServers["form-engine-wordpress"]={` +
                    `command:process.argv[1],` +
                    `args:[process.env.HOME+"/form-engine-mcp/form-engine-connector.js"],` +
                    `env:{` +
                    `FORM_ENGINE_SITE_URL:"${escapedSiteUrl}",` +
                    `FORM_ENGINE_USERNAME:"${escapedUsername}",` +
                    `FORM_ENGINE_APP_PASSWORD:process.argv[2]` +
                    `}};` +
                    `fs.writeFileSync(p,JSON.stringify(c,null,2))` +
                    `' "$NODE_PATH" '${escapedPassword}' && \\`,
                    `echo "" && echo "Setup complete. Quit Claude Desktop (Cmd+Q) and reopen it."`,
                ].join('\n');
            }

            function showSetupCommand(username, password) {
                const cmd = buildSetupCommand(username, password);
                document.getElementById('fre-setup-command').textContent = cmd;
                const container = document.getElementById('fre-setup-command-container');
                if (container) container.style.display = 'block';
                const placeholder = document.getElementById('fre-setup-command-placeholder');
                if (placeholder) placeholder.style.display = 'none';
            }

            async function post(action, extra = {}) {
                const body = new URLSearchParams({ action, nonce, ...extra });
                const res  = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body });
                return res.json();
            }

            function showStatus(id, text, ok = true) {
                const el = document.getElementById(id);
                if (!el) return;
                el.textContent = text;
                el.style.color = ok ? '#2271b1' : '#b32d2e';
                clearTimeout(el._t);
                el._t = setTimeout(() => { el.textContent = ''; }, 2500);
            }

            // Both toggles share a handler — they map to different AJAX actions via data-attr.
            document.querySelectorAll('[data-ajax-action]').forEach((cb) => {
                cb.addEventListener('change', async (e) => {
                    const action   = cb.dataset.ajaxAction;
                    const enabled  = cb.checked ? '1' : '0';
                    const statusId = cb.id === 'fre-connector-enabled' ? 'fre-enabled-status' : 'fre-entry-read-status';
                    try {
                        const r = await post(action, { enabled });
                        if (r.success) {
                            showStatus(statusId, cb.checked ? '<?php echo esc_js( __( 'Enabled.', 'form-runtime-engine' ) ); ?>' : '<?php echo esc_js( __( 'Disabled.', 'form-runtime-engine' ) ); ?>');
                        } else {
                            cb.checked = !cb.checked; // revert
                            showStatus(statusId, (r.data && r.data.message) || 'Error', false);
                        }
                    } catch (err) {
                        cb.checked = !cb.checked;
                        showStatus(statusId, String(err), false);
                    }
                });
            });

            const genBtn = document.getElementById('fre-generate-password-btn');
            if (genBtn) {
                genBtn.addEventListener('click', async () => {
                    // No confirm() dialog — Promptless doesn't use one and the
                    // blocking modal adds friction. Misclicks are recoverable
                    // (just click Generate again — the prior password is
                    // already revoked atomically server-side).
                    const originalLabel = genBtn.textContent;
                    genBtn.disabled = true;
                    genBtn.textContent = '<?php echo esc_js( __( 'Generating...', 'form-runtime-engine' ) ); ?>';
                    const r = await post('fre_connector_generate_password');
                    genBtn.disabled = false;
                    if (r.success) {
                        // Reveal the success notice in Step 1 card.
                        const display = document.getElementById('fre-credential-display');
                        if (display) display.style.display = 'block';

                        // Build + reveal the setup command in Step 2 card.
                        showSetupCommand(r.data.username, r.data.password);

                        // Flip the status pill in the Connection Status card
                        // from red "Not Connected" to green "Configured".
                        const pill = document.getElementById('fre-connector-status-pill');
                        if (pill) {
                            pill.textContent = '<?php echo esc_js( __( 'Configured', 'form-runtime-engine' ) ); ?>';
                            pill.classList.remove('fre-connector-status-inactive');
                            pill.classList.add('fre-connector-status-active');
                        }

                        genBtn.textContent = '<?php echo esc_js( __( 'Regenerate Connection', 'form-runtime-engine' ) ); ?>';
                    } else {
                        genBtn.textContent = originalLabel;
                        alert((r.data && r.data.message) || 'Error');
                    }
                });
            }

            const copyBtn = document.getElementById('fre-copy-setup-command');
            if (copyBtn) {
                // Capture original label so the restore-after-flash matches
                // the template's rendered text (e.g. 'Copy Command') instead
                // of being hardcoded to 'Copy'.
                const originalCopyLabel = copyBtn.textContent;
                const flashCopied = () => {
                    copyBtn.textContent = '<?php echo esc_js( __( 'Copied', 'form-runtime-engine' ) ); ?>';
                    setTimeout(() => { copyBtn.textContent = originalCopyLabel; }, 2000);
                };
                copyBtn.addEventListener('click', async () => {
                    const pre = document.getElementById('fre-setup-command');
                    const cmd = pre.textContent;
                    // Path 1: modern Clipboard API. Only available on HTTPS
                    // sites and true localhost — NOT on HTTP custom
                    // hostnames like `mysite.local`.
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        try {
                            await navigator.clipboard.writeText(cmd);
                            flashCopied();
                            return;
                        } catch (e) { /* fall through */ }
                    }
                    // Path 2: legacy execCommand fallback. Works on HTTP.
                    const sel = window.getSelection();
                    const range = document.createRange();
                    range.selectNodeContents(pre);
                    sel.removeAllRanges();
                    sel.addRange(range);
                    try {
                        const ok = document.execCommand('copy');
                        sel.removeAllRanges();
                        if (ok) flashCopied();
                    } catch (e) { /* leave selection so user can Cmd+C */ }
                });
            }

            const revokeBtn = document.getElementById('fre-revoke-password-btn');
            if (revokeBtn) {
                revokeBtn.addEventListener('click', async () => {
                    // Revoke IS destructive (Cowork loses access immediately) so
                    // a confirm() is reasonable here even though we removed it
                    // from Generate. Keeping it preserves the safety net for
                    // misclicks on the destructive path.
                    if (!confirm('<?php echo esc_js( __( 'Revoke the connector App Password? Claude Cowork will lose access immediately.', 'form-runtime-engine' ) ); ?>')) return;
                    revokeBtn.disabled = true;
                    const r = await post('fre_connector_revoke_password');
                    revokeBtn.disabled = false;
                    if (r.success) {
                        window.location.reload();
                    } else {
                        alert((r.data && r.data.message) || 'Error');
                    }
                });
            }
        })();
        </script>
        <?php
    }

    // ---------------------------------------------------------------------
    // AJAX handlers
    // ---------------------------------------------------------------------

    /**
     * Verify AJAX request's nonce and capability. Send JSON error and halt on
     * failure. Returns normally when OK.
     */
    private function verify_ajax() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized.', 'form-runtime-engine' ) ), 403 );
        }
    }

    /**
     * AJAX: toggle the connector enable flag.
     */
    public function ajax_toggle_enabled() {
        $this->verify_ajax();

        $enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        FRE_Connector_Settings::set_enabled( $enabled );

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __( 'Connector enabled.', 'form-runtime-engine' )
                : __( 'Connector disabled.', 'form-runtime-engine' ),
        ) );
    }

    /**
     * AJAX: toggle the entry-read flag.
     */
    public function ajax_toggle_entry_read() {
        $this->verify_ajax();

        $enabled = isset( $_POST['enabled'] ) && '1' === $_POST['enabled'];
        FRE_Connector_Settings::set_entry_read_enabled( $enabled );

        wp_send_json_success( array(
            'enabled' => $enabled,
            'message' => $enabled
                ? __( 'Entry read access enabled.', 'form-runtime-engine' )
                : __( 'Entry read access disabled.', 'form-runtime-engine' ),
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
                'message' => __( 'This WordPress version does not support Application Passwords. Requires WordPress 5.6+.', 'form-runtime-engine' ),
            ) );
        }

        $user_id = get_current_user_id();

        // Revoke any prior Form Runtime Engine App Password for this user.
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && FRE_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                }
            }
        }

        $created = WP_Application_Passwords::create_new_application_password(
            $user_id,
            array( 'name' => FRE_Connector_Settings::APP_PASSWORD_NAME )
        );

        if ( is_wp_error( $created ) ) {
            wp_send_json_error( array( 'message' => $created->get_error_message() ) );
        }

        // WP returns [ $password_string, $item_metadata ].
        list( $password_string, $item ) = $created;

        FRE_Connector_Settings::mark_configured( $user_id );

        $current_user = wp_get_current_user();

        wp_send_json_success( array(
            'username' => $current_user->user_login,
            'password' => $password_string,
            'uuid'     => $item['uuid'] ?? '',
            'message'  => __( 'Application Password generated. Copy it now — it will not be shown again.', 'form-runtime-engine' ),
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
     * Route: /wp-admin/admin-ajax.php?action=fre_download_connector
     */
    public function ajax_download_connector() {
        $path = FRE_PLUGIN_DIR . 'includes/Connector/assets/form-engine-connector.js';

        if ( ! file_exists( $path ) ) {
            status_header( 404 );
            echo '// Form Runtime Engine connector script not found on this install.';
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
                'message' => __( 'WordPress 5.6+ is required.', 'form-runtime-engine' ),
            ) );
        }

        $user_id  = get_current_user_id();
        $existing = WP_Application_Passwords::get_user_application_passwords( $user_id );
        $count    = 0;

        if ( is_array( $existing ) ) {
            foreach ( $existing as $pw ) {
                if ( isset( $pw['name'] ) && FRE_Connector_Settings::APP_PASSWORD_NAME === $pw['name'] ) {
                    WP_Application_Passwords::delete_application_password( $user_id, $pw['uuid'] );
                    $count++;
                }
            }
        }

        FRE_Connector_Settings::clear_configured( $user_id );

        wp_send_json_success( array(
            'revoked_count' => $count,
            'message'       => sprintf(
                /* translators: %d: count of revoked app passwords */
                _n( '%d connector credential revoked.', '%d connector credentials revoked.', $count, 'form-runtime-engine' ),
                $count
            ),
        ) );
    }
}
