<?php
/**
 * Forms Manager for Form Runtime Engine.
 *
 * Handles CRUD operations for database-stored form configurations.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Forms manager class.
 */
class FRE_Forms_Manager {

    /**
     * Option key for storing forms.
     */
    const OPTION_KEY = 'fre_client_forms';

    /**
     * Get all saved forms from database.
     *
     * @return array
     */
    public static function get_forms() {
        return get_option( self::OPTION_KEY, array() );
    }

    /**
     * Get a single form from database.
     *
     * @param string $form_id Form ID.
     * @return array|null
     */
    public static function get_form( $form_id ) {
        $forms = self::get_forms();
        return isset( $forms[ $form_id ] ) ? $forms[ $form_id ] : null;
    }

    /**
     * Save a form to database.
     *
     * @param string $form_id         Form ID.
     * @param string $title           Form title.
     * @param string $json_config     JSON configuration.
     * @param string $custom_css      Custom CSS for the form.
     * @param bool   $webhook_enabled Whether webhook is enabled.
     * @param string $webhook_url     Webhook URL.
     * @return array|WP_Error
     */
    public static function save_form( $form_id, $title, $json_config, $custom_css = '', $webhook_enabled = false, $webhook_url = '' ) {
        // Validate form ID.
        if ( empty( $form_id ) ) {
            return new WP_Error( 'empty_id', __( 'Form ID is required.', 'form-runtime-engine' ) );
        }

        if ( ! preg_match( '/^[a-z0-9\-_]+$/', $form_id ) ) {
            return new WP_Error( 'invalid_id', __( 'Form ID must be lowercase alphanumeric with dashes or underscores only.', 'form-runtime-engine' ) );
        }

        // Validate JSON.
        $config = json_decode( $json_config, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            return new WP_Error( 'invalid_json', __( 'Invalid JSON syntax: ', 'form-runtime-engine' ) . json_last_error_msg() );
        }

        // JSON Schema Validation (comprehensive validation).
        $schema_result = FRE_JSON_Schema_Validator::validate( $config );
        if ( ! $schema_result['valid'] ) {
            return new WP_Error( 'schema_error', implode( ' ', $schema_result['errors'] ) );
        }

        // Log warnings (non-fatal issues) if WP_DEBUG is enabled.
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! empty( $schema_result['warnings'] ) ) {
            foreach ( $schema_result['warnings'] as $warning ) {
                // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
                error_log( 'FRE Form Schema Warning [' . $form_id . ']: ' . $warning );
            }
        }

        // CSS Validation (if provided).
        if ( ! empty( $custom_css ) ) {
            $css_result = FRE_CSS_Validator::validate( $custom_css );
            if ( is_wp_error( $css_result ) ) {
                return $css_result;
            }
            // Sanitize CSS.
            $custom_css = FRE_CSS_Validator::sanitize( $custom_css );
        }

        // Webhook URL validation (if enabled and URL provided).
        $webhook_enabled = (bool) $webhook_enabled;
        if ( $webhook_enabled && ! empty( $webhook_url ) ) {
            $webhook_result = FRE_Webhook_Validator::validate_and_sanitize( $webhook_url );
            if ( is_wp_error( $webhook_result ) ) {
                return $webhook_result;
            }
            $webhook_url = $webhook_result;
        } elseif ( ! $webhook_enabled ) {
            // Clear URL if webhook is disabled.
            $webhook_url = '';
        }

        // Get existing forms.
        $forms  = self::get_forms();
        $is_new = ! isset( $forms[ $form_id ] );

        // Use title from JSON if not provided.
        if ( empty( $title ) && ! empty( $config['title'] ) ) {
            $title = $config['title'];
        }

        // Save form (CSS is already sanitized by FRE_CSS_Validator).
        $forms[ $form_id ] = array(
            'id'              => $form_id,
            'title'           => sanitize_text_field( $title ),
            'config'          => $json_config,
            'custom_css'      => $custom_css,
            'webhook_enabled' => $webhook_enabled,
            'webhook_url'     => $webhook_url,
            'created'         => $is_new ? time() : ( $forms[ $form_id ]['created'] ?? time() ),
            'modified'        => time(),
        );

        update_option( self::OPTION_KEY, $forms );

        return $forms[ $form_id ];
    }

    /**
     * Delete a form from database.
     *
     * @param string $form_id Form ID.
     * @return bool
     */
    public static function delete_form( $form_id ) {
        $forms = self::get_forms();

        if ( ! isset( $forms[ $form_id ] ) ) {
            return false;
        }

        unset( $forms[ $form_id ] );
        update_option( self::OPTION_KEY, $forms );

        return true;
    }

    /**
     * Register all database forms with the form registry.
     *
     * Called on fre_init hook.
     */
    public static function register_db_forms() {
        $forms = self::get_forms();

        foreach ( $forms as $form_id => $form_data ) {
            $config = json_decode( $form_data['config'], true );

            if ( $config ) {
                // Add title if not in config.
                if ( ! empty( $form_data['title'] ) && empty( $config['title'] ) ) {
                    $config['title'] = $form_data['title'];
                }

                fre_register_form( $form_id, $config );
            }
        }
    }

    /**
     * Render the forms management page.
     */
    public static function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', 'form-runtime-engine' ) );
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
                <h1 class="wp-heading-inline"><?php esc_html_e( 'Manage Forms', 'form-runtime-engine' ); ?></h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=add' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Add New', 'form-runtime-engine' ); ?>
                </a>
                <hr class="wp-header-end">

                <?php if ( empty( $forms ) ) : ?>
                    <div class="fre-forms-empty-state">
                        <h2><?php esc_html_e( 'No forms yet', 'form-runtime-engine' ); ?></h2>
                        <p><?php esc_html_e( 'Get started by adding a new form. Paste JSON configuration generated by AI.', 'form-runtime-engine' ); ?></p>
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=add' ) ); ?>" class="button button-primary">
                            <?php esc_html_e( 'Add Your First Form', 'form-runtime-engine' ); ?>
                        </a>
                    </div>
                <?php else : ?>
                    <table class="wp-list-table widefat fixed striped fre-forms-table">
                        <thead>
                            <tr>
                                <th scope="col" class="column-form"><?php esc_html_e( 'Form', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-entries"><?php esc_html_e( 'Entries', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-modified"><?php esc_html_e( 'Modified', 'form-runtime-engine' ); ?></th>
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
                                                <a href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'form-runtime-engine' ); ?></a> |
                                            </span>
                                            <span class="entries">
                                                <a href="<?php echo esc_url( $entries_url ); ?>"><?php esc_html_e( 'Entries', 'form-runtime-engine' ); ?></a> |
                                            </span>
                                            <span class="delete">
                                                <a href="#" class="fre-forms-delete-btn submitdelete" data-form-id="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Delete', 'form-runtime-engine' ); ?></a>
                                            </span>
                                        </div>
                                    </td>
                                    <td class="column-shortcode">
                                        <code class="fre-forms-shortcode">[fre_form id="<?php echo esc_attr( $form_id ); ?>"]</code>
                                        <button type="button" class="button-link fre-forms-copy-btn" data-shortcode='[fre_form id="<?php echo esc_attr( $form_id ); ?>"]' title="<?php esc_attr_e( 'Copy to clipboard', 'form-runtime-engine' ); ?>">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
                                    </td>
                                    <td class="column-entries">
                                        <a href="<?php echo esc_url( $entries_url ); ?>" class="fre-entry-count">
                                            <span class="fre-count-total"><?php echo (int) $counts['total']; ?></span>
                                            <?php if ( $counts['unread'] > 0 ) : ?>
                                                <span class="fre-count-unread"><?php echo (int) $counts['unread']; ?> <?php esc_html_e( 'new', 'form-runtime-engine' ); ?></span>
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
                        esc_html_e( 'Edit Form', 'form-runtime-engine' );
                    } else {
                        esc_html_e( 'Add New Form', 'form-runtime-engine' );
                    }
                    ?>
                </h1>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms' ) ); ?>" class="page-title-action">
                    <?php esc_html_e( 'Back to List', 'form-runtime-engine' ); ?>
                </a>
                <hr class="wp-header-end">

                <?php if ( $is_edit_view ) : ?>
                    <?php
                    $edit_counts  = isset( $entry_counts[ $editing_id ] ) ? $entry_counts[ $editing_id ] : array( 'total' => 0, 'unread' => 0 );
                    $edit_entries_url = admin_url( 'admin.php?page=fre-entries&form_id=' . $editing_id );
                    ?>
                    <h2 class="nav-tab-wrapper fre-form-tabs">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $editing_id ) ); ?>" class="nav-tab nav-tab-active">
                            <?php esc_html_e( 'Settings', 'form-runtime-engine' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $edit_entries_url ); ?>" class="nav-tab">
                            <?php esc_html_e( 'Entries', 'form-runtime-engine' ); ?>
                            <span class="fre-tab-count"><?php echo (int) $edit_counts['total']; ?></span>
                        </a>
                    </h2>
                <?php endif; ?>

                <div id="fre-forms-notices"></div>

                <form id="fre-forms-form" class="fre-forms-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row">
                                <label for="fre-form-title"><?php esc_html_e( 'Form Title', 'form-runtime-engine' ); ?></label>
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
                                    <?php esc_html_e( 'For display in admin. Can also be set in JSON config.', 'form-runtime-engine' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fre-form-id"><?php esc_html_e( 'Form ID', 'form-runtime-engine' ); ?> <span class="required">*</span></label>
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
                                        esc_html_e( 'Form ID cannot be changed after creation.', 'form-runtime-engine' );
                                    } else {
                                        esc_html_e( 'Auto-generated from title. Lowercase letters, numbers, dashes and underscores only.', 'form-runtime-engine' );
                                    }
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fre-form-config"><?php esc_html_e( 'Configuration (JSON)', 'form-runtime-engine' ); ?> <span class="required">*</span></label>
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
                                    <?php esc_html_e( 'Paste the JSON configuration. Must include a "fields" array.', 'form-runtime-engine' ); ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <label for="fre-form-custom-css"><?php esc_html_e( 'Custom CSS (Optional)', 'form-runtime-engine' ); ?></label>
                            </th>
                            <td>
                                <textarea
                                    id="fre-form-custom-css"
                                    name="custom_css"
                                    class="large-text code"
                                    rows="10"
                                ><?php echo esc_textarea( $edit_form['custom_css'] ?? '' ); ?></textarea>
                                <p class="description">
                                    <?php
                                    printf(
                                        /* translators: %1$s: form-specific selector example, %2$s: general form selector */
                                        esc_html__( 'Styles for this form only. Use %1$s to target this specific form, or %2$s for general styles.', 'form-runtime-engine' ),
                                        $editing_id ? '<code>#fre-form-' . esc_html( $editing_id ) . '</code>' : '<code>#fre-form-{id}</code>',
                                        '<code>.fre-form</code>'
                                    );
                                    ?>
                                </p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">
                                <?php esc_html_e( 'Webhook Integration', 'form-runtime-engine' ); ?>
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
                                        <?php esc_html_e( 'Enable webhook for this form', 'form-runtime-engine' ); ?>
                                    </label>
                                    <p class="description">
                                        <?php esc_html_e( 'Send form submissions to an external service like Zapier, Make.com, or any webhook endpoint.', 'form-runtime-engine' ); ?>
                                    </p>
                                </fieldset>
                                <div id="fre-webhook-url-wrapper" style="margin-top: 15px; <?php echo empty( $edit_form['webhook_enabled'] ) ? 'display: none;' : ''; ?>">
                                    <label for="fre-webhook-url"><?php esc_html_e( 'Webhook URL', 'form-runtime-engine' ); ?></label>
                                    <input
                                        type="url"
                                        id="fre-webhook-url"
                                        name="webhook_url"
                                        class="large-text"
                                        value="<?php echo esc_url( $edit_form['webhook_url'] ?? '' ); ?>"
                                        placeholder="https://hooks.zapier.com/..."
                                    >
                                    <p class="description">
                                        <?php esc_html_e( 'Enter the full webhook URL. Must start with http:// or https://.', 'form-runtime-engine' ); ?>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary button-large" id="fre-forms-save-btn">
                            <?php esc_html_e( 'Save Form', 'form-runtime-engine' ); ?>
                        </button>
                        <span class="spinner" id="fre-forms-spinner"></span>
                    </p>

                    <?php if ( $is_add_view || $is_edit_view ) : ?>
                        <div class="fre-forms-info-box">
                            <h4><?php esc_html_e( 'How to Use', 'form-runtime-engine' ); ?></h4>
                            <p>
                                <?php
                                if ( $is_edit_view ) {
                                    printf(
                                        /* translators: %s: shortcode */
                                        esc_html__( 'This form is available via shortcode: %s', 'form-runtime-engine' ),
                                        '<code>[fre_form id="' . esc_html( $editing_id ) . '"]</code>'
                                    );
                                } else {
                                    esc_html_e( 'After saving, your form will be available via shortcode:', 'form-runtime-engine' );
                                    echo ' <code>[fre_form id="your-form-id"]</code>';
                                }
                                ?>
                            </p>
                        </div>

                        <div class="fre-forms-info-box">
                            <h4><?php esc_html_e( 'Example JSON Configuration', 'form-runtime-engine' ); ?></h4>
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
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'form-runtime-engine' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id         = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';
        $title           = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
        $config          = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';
        $custom_css      = isset( $_POST['custom_css'] ) ? wp_unslash( $_POST['custom_css'] ) : '';
        $webhook_enabled = isset( $_POST['webhook_enabled'] ) && $_POST['webhook_enabled'] === '1';
        $webhook_url     = isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '';

        $result = self::save_form( $form_id, $title, $config, $custom_css, $webhook_enabled, $webhook_url );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message' => __( 'Form saved successfully!', 'form-runtime-engine' ),
            'form'    => $result,
        ) );
    }

    /**
     * AJAX: Delete form.
     */
    public static function ajax_delete_form() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Unauthorized', 'form-runtime-engine' ) ) );
        }

        check_ajax_referer( 'fre_admin_nonce', 'nonce' );

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';

        if ( empty( $form_id ) ) {
            wp_send_json_error( array( 'message' => __( 'Form ID is required.', 'form-runtime-engine' ) ) );
        }

        $result = self::delete_form( $form_id );

        if ( ! $result ) {
            wp_send_json_error( array( 'message' => __( 'Form not found.', 'form-runtime-engine' ) ) );
        }

        wp_send_json_success( array( 'message' => __( 'Form deleted successfully!', 'form-runtime-engine' ) ) );
    }
}
