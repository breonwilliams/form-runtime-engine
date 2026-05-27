<?php
/**
 * Forms Manager for Promptless Forms.
 *
 * Handles CRUD operations for database-stored form configurations.
 *
 * NOTE: Uses $_GET parameters for form editing UI which is standard for
 * WordPress admin interfaces. AJAX handlers verify nonces via check_ajax_referer().
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
 * Forms manager class.
 *
 * Admin UI + AJAX handlers for database-stored forms. All storage concerns
 * delegate to FRE_Forms_Repository, which is the single CRUD path shared by
 * the admin UI and the Cowork REST connector (Phase 2+). The static methods
 * on this class are preserved as thin delegators so external callers of the
 * old API — specifically the fre_get_db_form(), fre_save_db_form(), etc.
 * wrapper functions in the main plugin file — continue to work unchanged.
 */
class FRE_Forms_Manager {

    /**
     * Option key for storing forms.
     *
     * Kept as a class constant for backward compatibility with any legacy
     * code that referenced it directly. The canonical source is
     * FRE_Forms_Repository::OPTION_KEY; both point at the same option row.
     *
     * @var string
     */
    const OPTION_KEY = FRE_Forms_Repository::OPTION_KEY;

    /**
     * Get all saved forms from database.
     *
     * @return array
     */
    public static function get_forms() {
        return FRE_Forms_Repository::get_all();
    }

    /**
     * Get a single form from database.
     *
     * @param string $form_id Form ID.
     * @return array|null
     */
    public static function get_form( $form_id ) {
        return FRE_Forms_Repository::get( $form_id );
    }

    /**
     * Save a form to database.
     *
     * Thin wrapper around FRE_Forms_Repository::save() that preserves the
     * positional-argument signature the admin AJAX handler and external
     * callers rely on. New callers (including the Cowork REST connector)
     * should use FRE_Forms_Repository::save() directly with a structured
     * input array — particularly when they need to set `managed_by`.
     *
     * @param string $form_id         Form ID.
     * @param string $title           Form title.
     * @param string $json_config     JSON configuration.
     * @param mixed  $custom_css      DEPRECATED in 1.6.5. Custom-CSS feature retired
     *                                for WordPress.org guideline 5 compliance. Argument
     *                                preserved positionally so callers don't break, but
     *                                the value is silently ignored.
     * @param bool   $webhook_enabled Whether webhook is enabled.
     * @param string $webhook_url     Webhook URL.
     * @param string $webhook_secret  Webhook signing secret (auto-generated if empty and webhook enabled).
     * @param string $webhook_preset  Webhook destination preset (google_sheets, zapier, make, custom).
     * @return array|WP_Error
     */
    public static function save_form( $form_id, $title, $json_config, $custom_css = '', $webhook_enabled = false, $webhook_url = '', $webhook_secret = '', $webhook_preset = 'custom' ) {
        unset( $custom_css ); // Intentionally unused — see @param note.
        return FRE_Forms_Repository::save(
            $form_id,
            array(
                'title'           => $title,
                'config'          => $json_config,
                'webhook_enabled' => (bool) $webhook_enabled,
                'webhook_url'     => $webhook_url,
                'webhook_secret'  => $webhook_secret,
                'webhook_preset'  => $webhook_preset,
                // No managed_by: repository defaults to 'admin' for new forms
                // and preserves the existing value on updates, which is correct
                // for the admin UI path.
            )
        );
    }

    /**
     * Delete a form from database.
     *
     * @param string $form_id Form ID.
     * @return bool
     */
    public static function delete_form( $form_id ) {
        return FRE_Forms_Repository::delete( $form_id );
    }

    /**
     * Register all database forms with the form registry.
     *
     * Called on fre_init hook.
     */
    public static function register_db_forms() {
        FRE_Forms_Repository::register_all_with_runtime_registry();
    }

    /**
     * Render the forms management page.
     */
    public static function render_page() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'promptless-forms' ) );
        }

        $forms      = self::get_forms();
        $action     = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : 'list';
        $editing_id = isset( $_GET['form'] ) ? sanitize_key( $_GET['form'] ) : '';
        $edit_form  = $editing_id ? self::get_form( $editing_id ) : null;

        // Determine view mode.
        $is_add_view  = 'add' === $action;
        $is_edit_view = 'edit' === $action && $edit_form;
        $is_list_view = ! $is_add_view && ! $is_edit_view;

        // Get entry counts for all forms (used in list and edit views).
        $entry_counts = FRE_Admin::get_entry_counts_by_form();
        ?>

        <div class="wrap fre-forms-manager">
            <?php if ( $is_list_view ) : ?>
                <!-- List View -->
                <h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Forms', 'promptless-forms' ); ?></h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=add' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Add New', 'promptless-forms' ); ?>
                </a>
                <hr class="wp-header-end">

                <?php if ( empty( $forms ) ) : ?>
                    <div class="fre-forms-empty-state">
                        <h2><?php esc_html_e( 'No forms yet', 'promptless-forms' ); ?></h2>
                        <p><?php esc_html_e( 'Get started by adding a new form. Paste JSON configuration generated by AI.', 'promptless-forms' ); ?></p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=add' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'Add Your First Form', 'promptless-forms' ); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped fre-forms-table">
                        <thead>
                            <tr>
                                <th scope="col" class="column-form"><?php esc_html_e( 'Form', 'promptless-forms' ); ?></th>
                                <th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'promptless-forms' ); ?></th>
                                <th scope="col" class="column-entries"><?php esc_html_e( 'Entries', 'promptless-forms' ); ?></th>
                                <th scope="col" class="column-modified"><?php esc_html_e( 'Modified', 'promptless-forms' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $forms as $form_id => $form ) : ?>
                                <?php
                                $counts       = isset( $entry_counts[ $form_id ] ) ? $entry_counts[ $form_id ] : array( 'total' => 0, 'unread' => 0 );
                                $entries_url  = admin_url( 'admin.php?page=fre-entries&form_id=' . $form_id );
                                $edit_url     = admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $form_id );
                                $display_title = ! empty( $form['title'] ) ? $form['title'] : $form_id;
                                ?>
                                <tr data-form-id="<?php echo esc_attr( $form_id ); ?>">
                                    <td class="column-form">
                                        <strong>
                                            <a href="<?php echo esc_url( $edit_url ); ?>" class="row-title">
                                                <?php echo esc_html( $display_title ); ?>
                                            </a>
                                        </strong>
                                        <?php if ( ! empty( $form['title'] ) ) : ?>
                                            <span class="fre-form-id">— <?php echo esc_html( $form_id ); ?></span>
                                        <?php endif; ?>
                                        <div class="row-actions">
                                            <span class="edit">
                                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'promptless-forms' ); ?></a> |
                                            </span>
                                            <span class="entries">
                                                <a href="<?php echo esc_url( $entries_url ); ?>"><?php esc_html_e( 'Entries', 'promptless-forms' ); ?></a> |
                                            </span>
                                            <span class="delete">
                                                <a href="#" class="fre-forms-delete-btn submitdelete" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Delete', 'promptless-forms' ); ?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-shortcode">
                                        <code class="fre-forms-shortcode">[fre_form id="<?php echo esc_attr( $form_id ); ?>"]</code>
                                        <button type="button" class="button-link fre-forms-copy-btn" data-shortcode='[fre_form id="<?php echo esc_attr( $form_id ); ?>"]' title="<?php esc_attr_e( 'Copy to clipboard', 'promptless-forms' ); ?>">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </td>
                                    <td class="column-entries">
                                        <a href="<?php echo esc_url( $entries_url ); ?>" class="fre-entry-count">
                                            <span class="fre-count-total"><?php echo (int) $counts['total']; ?></span>
                                            <?php if ( $counts['unread'] > 0 ) : ?>
                                                <span class="fre-count-unread"><?php echo (int) $counts['unread']; ?> <?php esc_html_e( 'new', 'promptless-forms' ); ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </td>
                                    <td class="column-modified">
                                        <?php
                                        if ( ! empty( $form['modified'] ) ) {
                                            echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $form['modified'] ) );
                                        } else {
                                            echo '—';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>

            <?php else : ?>
                <!-- Add/Edit View -->
                <h1 class="wp-heading-inline">
                    <?php
                    if ( $is_edit_view ) {
                        esc_html_e( 'Edit Form', 'promptless-forms' );
                    } else {
                        esc_html_e( 'Add New Form', 'promptless-forms' );
                    }
                    ?>
                </h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Back to List', 'promptless-forms' ); ?>
                </a>
                <hr class="wp-header-end">

                <?php if ( $is_edit_view ) : ?>
                    <?php
                    $edit_counts  = isset( $entry_counts[ $editing_id ] ) ? $entry_counts[ $editing_id ] : array( 'total' => 0, 'unread' => 0 );
                    $edit_entries_url = admin_url( 'admin.php?page=fre-entries&form_id=' . $editing_id );
                    ?>
                    <h2 class="nav-tab-wrapper fre-form-tabs">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $editing_id ) ); ?>" class="nav-tab nav-tab-active">
                            <?php esc_html_e( 'Settings', 'promptless-forms' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $edit_entries_url ); ?>" class="nav-tab">
                            <?php esc_html_e( 'Entries', 'promptless-forms' ); ?>
                            <span class="fre-tab-count"><?php echo (int) $edit_counts['total']; ?></span>
                        </a>
                    </h2>
                <?php endif; ?>

                <div id="fre-forms-notices"></div>

                <form id="fre-forms-form" class="fre-forms-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="fre-form-title"><?php esc_html_e( 'Form Title', 'promptless-forms' ); ?></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="fre-form-title"
                                    name="title"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $edit_form['title'] ?? '' ); ?>"
                                >
                                <p class="description">
                                    <?php esc_html_e( 'For display in admin. Can also be set in JSON config.', 'promptless-forms' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fre-form-id"><?php esc_html_e( 'Form ID', 'promptless-forms' ); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <input
                                    type="text"
                                    id="fre-form-id"
                                    name="form_id"
                                    class="regular-text"
                                    value="<?php echo esc_attr( $editing_id ); ?>"
                                    pattern="[a-z0-9\-_]+"
                                    <?php echo $is_edit_view ? 'readonly' : ''; ?>
                                    required
                                >
                                <p class="description">
                                    <?php
                                    if ( $is_edit_view ) {
                                        esc_html_e( 'Form ID cannot be changed after creation.', 'promptless-forms' );
                                    } else {
                                        esc_html_e( 'Auto-generated from title. Lowercase letters, numbers, dashes and underscores only.', 'promptless-forms' );
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fre-form-config"><?php esc_html_e( 'Configuration (JSON)', 'promptless-forms' ); ?> <span class="required">*</span></label>
                            </th>
                            <td>
                                <textarea
                                    id="fre-form-config"
                                    name="config"
                                    class="large-text code"
                                    rows="20"
                                    required
                                ><?php
                                if ( $edit_form ) {
                                    // Pretty print the JSON for editing.
                                    $decoded = json_decode( $edit_form['config'], true );
                                    echo esc_textarea( wp_json_encode( $decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
                                }
                                ?></textarea>
                                <p class="description">
                                    <?php esc_html_e( 'Paste the JSON configuration. Must include a "fields" array.', 'promptless-forms' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Webhook Integration', 'promptless-forms' ); ?>
                            </th>
                            <td>
                                <fieldset>
                                    <label for="fre-webhook-enabled">
                                        <input
                                            type="checkbox"
                                            id="fre-webhook-enabled"
                                            name="webhook_enabled"
                                            value="1"
                                            <?php checked( ! empty( $edit_form['webhook_enabled'] ) ); ?>
                                        >
                                        <?php esc_html_e( 'Enable webhook for this form', 'promptless-forms' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Send form submissions to an external service like Zapier, Make.com, or Google Sheets.', 'promptless-forms' ); ?>
                                    </p>
                                </fieldset>

                                <?php
                                // Determine current preset (auto-detect from URL if not set).
                                $current_preset = $edit_form['webhook_preset'] ?? 'custom';
                                $current_url    = $edit_form['webhook_url'] ?? '';
                                if ( 'custom' === $current_preset && ! empty( $current_url ) ) {
                                    if ( strpos( $current_url, 'script.google.com' ) !== false ) {
                                        $current_preset = 'google_sheets';
                                    } elseif ( strpos( $current_url, 'hooks.zapier.com' ) !== false ) {
                                        $current_preset = 'zapier';
                                    } elseif ( strpos( $current_url, 'hook.us1.make.com' ) !== false || strpos( $current_url, 'hook.eu1.make.com' ) !== false || strpos( $current_url, 'hook.make.com' ) !== false ) {
                                        $current_preset = 'make';
                                    }
                                }
                                ?>

                                <div id="fre-webhook-settings-wrapper" style="margin-top: 15px; <?php echo empty( $edit_form['webhook_enabled'] ) ? 'display: none;' : ''; ?>">

                                    <!-- Destination Preset -->
                                    <div style="margin-bottom: 15px;">
                                        <label for="fre-webhook-preset"><?php esc_html_e( 'Destination', 'promptless-forms' ); ?></label>
                                        <select id="fre-webhook-preset" name="webhook_preset" style="margin-left: 8px;">
                                            <option value="google_sheets" <?php selected( $current_preset, 'google_sheets' ); ?>>
                                                <?php esc_html_e( 'Google Sheets (Free)', 'promptless-forms' ); ?>
                                            </option>
                                            <option value="zapier" <?php selected( $current_preset, 'zapier' ); ?>>
                                                <?php esc_html_e( 'Zapier', 'promptless-forms' ); ?>
                                            </option>
                                            <option value="make" <?php selected( $current_preset, 'make' ); ?>>
                                                <?php esc_html_e( 'Make (Integromat)', 'promptless-forms' ); ?>
                                            </option>
                                            <option value="custom" <?php selected( $current_preset, 'custom' ); ?>>
                                                <?php esc_html_e( 'Custom Endpoint', 'promptless-forms' ); ?>
                                            </option>
                                        </select>
                                    </div>

                                    <!-- Contextual Help (changes based on preset) -->
                                    <div id="fre-webhook-preset-help" class="fre-webhook-preset-help" style="margin-bottom: 15px; padding: 10px 15px; background: #f0f6fc; border-left: 4px solid #2271b1; display: <?php echo empty( $edit_form['webhook_enabled'] ) ? 'none' : 'block'; ?>;">
                                        <div id="fre-preset-help-google_sheets" class="fre-preset-help" style="<?php echo 'google_sheets' !== $current_preset ? 'display:none;' : ''; ?>">
                                            <strong><?php esc_html_e( 'Google Sheets Setup', 'promptless-forms' ); ?></strong>
                                            <ol style="margin: 8px 0 0 20px;">
                                                <li><?php esc_html_e( 'Create a Google Sheet', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Open Extensions > Apps Script', 'promptless-forms' ); ?></li>
                                                <li>
                                                    <?php
                                                    printf(
                                                        /* translators: %s: path to the template file */
                                                        esc_html__( 'Paste the template from %s', 'promptless-forms' ),
                                                        '<code>docs/google/apps-script-template.gs</code>'
                                                    );
                                                    ?>
                                                </li>
                                                <li><?php esc_html_e( 'Deploy as Web App (access: "Anyone")', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Paste the Web App URL below', 'promptless-forms' ); ?></li>
                                            </ol>
                                            <p style="margin-top: 8px;">
                                                <?php
                                                printf(
                                                    /* translators: %s: path to the setup guide */
                                                    esc_html__( 'Full setup guide: %s', 'promptless-forms' ),
                                                    '<code>docs/google/google-sheets-setup.md</code>'
                                                );
                                                ?>
                                            </p>
                                        </div>
                                        <div id="fre-preset-help-zapier" class="fre-preset-help" style="<?php echo 'zapier' !== $current_preset ? 'display:none;' : ''; ?>">
                                            <strong><?php esc_html_e( 'Zapier Setup', 'promptless-forms' ); ?></strong>
                                            <ol style="margin: 8px 0 0 20px;">
                                                <li><?php esc_html_e( 'Create a Zap with "Webhooks by Zapier" as the trigger', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Select "Catch Hook" as the trigger event', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Copy the webhook URL Zapier provides', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Paste it below, save, then use "Test Connection" to send sample data', 'promptless-forms' ); ?></li>
                                            </ol>
                                        </div>
                                        <div id="fre-preset-help-make" class="fre-preset-help" style="<?php echo 'make' !== $current_preset ? 'display:none;' : ''; ?>">
                                            <strong><?php esc_html_e( 'Make (Integromat) Setup', 'promptless-forms' ); ?></strong>
                                            <ol style="margin: 8px 0 0 20px;">
                                                <li><?php esc_html_e( 'Create a new Scenario in Make', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Add a "Webhooks" module as the trigger', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Select "Custom webhook" and create a new webhook', 'promptless-forms' ); ?></li>
                                                <li><?php esc_html_e( 'Copy the URL and paste it below', 'promptless-forms' ); ?></li>
                                            </ol>
                                        </div>
                                        <div id="fre-preset-help-custom" class="fre-preset-help" style="<?php echo 'custom' !== $current_preset ? 'display:none;' : ''; ?>">
                                            <strong><?php esc_html_e( 'Custom Endpoint', 'promptless-forms' ); ?></strong>
                                            <p style="margin: 8px 0 0 0;"><?php esc_html_e( 'Enter any HTTPS endpoint that accepts POST requests with JSON payloads. Use "Preview Payload" below to see the exact data structure your endpoint will receive.', 'promptless-forms' ); ?></p>
                                        </div>
                                    </div>

                                    <!-- Webhook URL -->
                                    <div style="margin-bottom: 15px;">
                                        <label for="fre-webhook-url"><?php esc_html_e( 'Webhook URL', 'promptless-forms' ); ?></label>
                                        <input
                                            type="url"
                                            id="fre-webhook-url"
                                            name="webhook_url"
                                            class="large-text"
                                            value="<?php echo esc_url( $edit_form['webhook_url'] ?? '' ); ?>"
                                            placeholder="https://"
                                        >
                                        <p class="description">
                                            <?php esc_html_e( 'Enter the full webhook URL. Must use HTTPS.', 'promptless-forms' ); ?>
                                        </p>
                                    </div>

                                    <!-- Webhook Secret (HMAC Signing) -->
                                    <div style="margin-bottom: 15px;">
                                        <label for="fre-webhook-secret"><?php esc_html_e( 'Webhook Secret', 'promptless-forms' ); ?></label>
                                        <div style="display: flex; align-items: center; gap: 8px; margin-top: 4px;">
                                            <input
                                                type="text"
                                                id="fre-webhook-secret"
                                                name="webhook_secret"
                                                class="regular-text code"
                                                value="<?php echo esc_attr( $edit_form['webhook_secret'] ?? '' ); ?>"
                                                readonly
                                            >
                                            <button type="button" class="button" id="fre-regenerate-secret-btn" <?php echo empty( $editing_id ) ? 'disabled' : ''; ?>>
                                                <?php esc_html_e( 'Regenerate', 'promptless-forms' ); ?>
                                            </button>
                                            <button type="button" class="button-link" id="fre-copy-secret-btn" title="<?php esc_attr_e( 'Copy to clipboard', 'promptless-forms' ); ?>">
                                                <span class="dashicons dashicons-clipboard"></span>
                                            </button>
                                        </div>
                                        <p class="description">
                                            <?php esc_html_e( 'Used to sign requests with HMAC-SHA256. Copy this secret to your receiving endpoint for verification. Auto-generated when webhook is enabled.', 'promptless-forms' ); ?>
                                            <br>
                                            <?php esc_html_e( 'Signature is sent in the X-FRE-Signature header as: sha256={hash}', 'promptless-forms' ); ?>
                                        </p>
                                    </div>

                                    <!-- Action Buttons: Test Connection & Preview Payload -->
                                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                                        <button type="button" class="button" id="fre-test-webhook-btn">
                                            <span class="dashicons dashicons-yes-alt" style="margin-top: 4px;"></span>
                                            <?php esc_html_e( 'Test Connection', 'promptless-forms' ); ?>
                                        </button>
                                        <?php if ( $is_edit_view ) : ?>
                                            <button type="button" class="button" id="fre-preview-payload-btn">
                                                <span class="dashicons dashicons-visibility" style="margin-top: 4px;"></span>
                                                <?php esc_html_e( 'Preview Payload', 'promptless-forms' ); ?>
                                            </button>
                                        <?php endif; ?>
                                        <span class="spinner" id="fre-webhook-test-spinner" style="float: none;"></span>
                                    </div>

                                    <!-- Test / Preview Results Area -->
                                    <div id="fre-webhook-result" style="display: none; margin-bottom: 15px; padding: 12px 15px; border-radius: 4px;"></div>

                                    <!-- Payload Preview Area -->
                                    <div id="fre-payload-preview" style="display: none; margin-bottom: 15px;">
                                        <label><?php esc_html_e( 'Sample Payload', 'promptless-forms' ); ?></label>
                                        <pre id="fre-payload-json" class="code" style="background: #f6f7f7; border: 1px solid #c3c4c7; padding: 12px; overflow-x: auto; max-height: 400px; margin-top: 4px;"></pre>
                                    </div>

                                </div>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large" id="fre-forms-save-btn">
                            <?php esc_html_e( 'Save Form', 'promptless-forms' ); ?>
                        </button>
                        <span class="spinner" id="fre-forms-spinner"></span>
                    </p>

                    <?php if ( $is_add_view || $is_edit_view ) : ?>
                        <div class="fre-forms-info-box">
                            <h4><?php esc_html_e( 'How to Use', 'promptless-forms' ); ?></h4>
                            <p>
                                <?php
                                if ( $is_edit_view ) {
                                    printf(
                                        /* translators: %s: shortcode */
                                        esc_html__( 'This form is available via shortcode: %s', 'promptless-forms' ),
                                        '<code>[fre_form id="' . esc_html( $editing_id ) . '"]</code>'
                                    );
                                } else {
                                    esc_html_e( 'After saving, your form will be available via shortcode:', 'promptless-forms' );
                                    echo ' <code>[fre_form id="your-form-id"]</code>';
                                }
                                ?>
                            </p>
                        </div>

                        <div class="fre-forms-info-box">
                            <h4><?php esc_html_e( 'Example JSON Configuration', 'promptless-forms' ); ?></h4>
                            <pre class="fre-forms-example">{
  "title": "Contact Us",
  "fields": [
    {"key": "name", "type": "text", "label": "Name", "required": true},
    {"key": "email", "type": "email", "label": "Email", "required": true},
    {"key": "message", "type": "textarea", "label": "Message", "required": true}
  ],
  "settings": {
    "submit_button_text": "Send Message",
    "success_message": "Thank you! We'll respond within 24 hours.",
    "notification": {
      "subject": "New Contact Form Submission",
      "reply_to": "{field:email}"
    }
  }
}</pre>
                        </div>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * AJAX: Save form.
     */
    public static function ajax_save_form() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id         = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '';
        $title           = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON is validated by FRE_Schema_Validator::validate().
        $config          = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
        $webhook_enabled = isset( $_POST['webhook_enabled'] ) && $_POST['webhook_enabled'] === '1';
        $webhook_url     = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
        $webhook_secret  = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
        $webhook_preset  = isset( $_POST['webhook_preset'] ) ? sanitize_key( wp_unslash( $_POST['webhook_preset'] ) ) : 'custom';

        // custom_css is no longer accepted (feature removed in 1.6.5 — see
        // FRE_Forms_Repository docblock). Positional empty-string keeps the
        // legacy save_form() signature stable for any external callers.
        $result = self::save_form( $form_id, $title, $config, '', $webhook_enabled, $webhook_url, $webhook_secret, $webhook_preset );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Form saved successfully!', 'promptless-forms' ),
            'form'    => $result,
        ) );
    }

    /**
     * AJAX: Delete form.
     */
    public static function ajax_delete_form() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'promptless-forms' ) ) );
        }

        $result = self::delete_form( $form_id );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Form not found.', 'promptless-forms' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Form deleted successfully!', 'promptless-forms' ) ) );
    }

    /**
     * AJAX: Test a webhook connection.
     *
     * Sends a test ping to the webhook URL and returns rich response data.
     */
    public static function ajax_test_webhook() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $webhook_url    = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';
        $webhook_secret = isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '';
        $form_id        = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : 'test';

        if ( empty( $webhook_url ) ) {
            wp_send_json_error( array( 'message' => __( 'Webhook URL is required.', 'promptless-forms' ) ) );
        }

        $result = FRE_Webhook_Dispatcher::test( $webhook_url, $form_id, $webhook_secret );

        if ( $result['success'] ) {
            wp_send_json_success( $result );
        } else {
            wp_send_json_error( $result );
        }
    }

    /**
     * AJAX: Preview webhook payload for a form.
     *
     * Generates a sample payload using the form's field definitions.
     */
    public static function ajax_preview_payload() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'promptless-forms' ) ) );
        }

        $payload = FRE_Webhook_Dispatcher::preview_payload( $form_id );

        if ( is_wp_error( $payload ) ) {
            wp_send_json_error( array( 'message' => $payload->get_error_message() ) );
        }

        wp_send_json_success( array(
            'payload' => $payload,
            'json'    => wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ),
        ) );
    }

    /**
     * AJAX: Regenerate webhook secret for a form.
     *
     * Delegates to FRE_Forms_Repository::regenerate_webhook_secret() which
     * also bumps the form's connector_version and modified timestamp.
     */
    public static function ajax_regenerate_secret() {
        if ( ! current_user_can( FRE_Capabilities::MANAGE_FORMS ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'promptless-forms' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( wp_unslash( $_POST['form_id'] ) ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'promptless-forms' ) ) );
        }

        $result = FRE_Forms_Repository::regenerate_webhook_secret( $form_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Webhook secret regenerated. Update your endpoint to match.', 'promptless-forms' ),
            'secret'  => $result,
        ) );
    }
}
