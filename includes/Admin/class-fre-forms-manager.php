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
     * @param string $form_id     Form ID.
     * @param string $title       Form title.
     * @param string $json_config JSON configuration.
     * @return array|WP_Error
     */
    public static function save_form( $form_id, $title, $json_config ) {
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

        // Validate structure.
        if ( ! isset( $config['fields'] ) || ! is_array( $config['fields'] ) ) {
            return new WP_Error( 'missing_fields', __( 'Configuration must have a "fields" array.', 'form-runtime-engine' ) );
        }

        // Validate each field has key and type.
        foreach ( $config['fields'] as $index => $field ) {
            if ( empty( $field['key'] ) ) {
                return new WP_Error(
                    'missing_field_key',
                    sprintf( __( 'Field at index %d is missing required "key" property.', 'form-runtime-engine' ), $index )
                );
            }
            if ( empty( $field['type'] ) ) {
                return new WP_Error(
                    'missing_field_type',
                    sprintf( __( 'Field "%s" is missing required "type" property.', 'form-runtime-engine' ), $field['key'] )
                );
            }
        }

        // Check for duplicate field keys.
        $keys       = array_column( $config['fields'], 'key' );
        $duplicates = array_diff_assoc( $keys, array_unique( $keys ) );
        if ( ! empty( $duplicates ) ) {
            return new WP_Error(
                'duplicate_keys',
                sprintf( __( 'Duplicate field keys found: %s', 'form-runtime-engine' ), implode( ', ', array_unique( $duplicates ) ) )
            );
        }

        // Get existing forms.
        $forms  = self::get_forms();
        $is_new = ! isset( $forms[ $form_id ] );

        // Use title from JSON if not provided.
        if ( empty( $title ) && ! empty( $config['title'] ) ) {
            $title = $config['title'];
        }

        // Save form.
        $forms[ $form_id ] = array(
            'id'       => $form_id,
            'title'    => sanitize_text_field( $title ),
            'config'   => $json_config,
            'created'  => $is_new ? time() : ( $forms[ $form_id ]['created'] ?? time() ),
            'modified' => time(),
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
                                <th scope="col" class="column-id"><?php esc_html_e( 'ID', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-title"><?php esc_html_e( 'Title', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-shortcode"><?php esc_html_e( 'Shortcode', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-modified"><?php esc_html_e( 'Modified', 'form-runtime-engine' ); ?></th>
                                <th scope="col" class="column-actions"><?php esc_html_e( 'Actions', 'form-runtime-engine' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ( $forms as $form_id => $form ) : ?>
                                <tr data-form-id="<?php echo esc_attr( $form_id ); ?>">
                                    <td class="column-id">
                                        <strong><?php echo esc_html( $form_id ); ?></strong>
                                    </td>
                                    <td class="column-title">
                                        <?php echo esc_html( $form['title'] ?: '—' ); ?>
                                    </td>
                                    <td class="column-shortcode">
                                        <code class="fre-forms-shortcode">[fre_form id="<?php echo esc_attr( $form_id ); ?>"]</code>
                                        <button type="button" class="button-link fre-forms-copy-btn" data-shortcode='[fre_form id="<?php echo esc_attr( $form_id ); ?>"]' title="<?php esc_attr_e( 'Copy to clipboard', 'form-runtime-engine' ); ?>">
                                            <span class="dashicons dashicons-clipboard"></span>
                                        </button>
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
                                    <td class="column-actions">
                                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-forms&action=edit&form=' . $form_id ) ); ?>" class="button button-small">
                                            <span class="dashicons dashicons-edit"></span>
                                            <?php esc_html_e( 'Edit', 'form-runtime-engine' ); ?>
                                        </a>
                                        <button type="button" class="button button-small fre-forms-delete-btn" data-form-id="<?php echo esc_attr( $form_id ); ?>">
                                            <span class="dashicons dashicons-trash"></span>
                                            <?php esc_html_e( 'Delete', 'form-runtime-engine' ); ?>
                                        </button>
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

                <div id="fre-forms-notices"></div>

                <form id="fre-forms-form" class="fre-forms-form">
                    <table class="form-table">
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
                                    <?php esc_html_e( 'Lowercase letters, numbers, dashes and underscores only.', 'form-runtime-engine' ); ?>
                                </p>
                            </td>
                        </tr>
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
                                    <?php esc_html_e( 'Optional. For display in admin. Can also be set in JSON config.', 'form-runtime-engine' ); ?>
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

        $form_id = isset( $_POST['form_id'] ) ? sanitize_key( $_POST['form_id'] ) : '';
        $title   = isset( $_POST['title'] ) ? sanitize_text_field( $_POST['title'] ) : '';
        $config  = isset( $_POST['config'] ) ? wp_unslash( $_POST['config'] ) : '';

        $result = self::save_form( $form_id, $title, $config );

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
