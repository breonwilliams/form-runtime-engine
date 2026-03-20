<?php
/**
 * Entry Detail View for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Entry detail view handler.
 */
class FRE_Entry_Detail {

    /**
     * Entry ID.
     *
     * @var int
     */
    private $entry_id;

    /**
     * Entry data.
     *
     * @var array|null
     */
    private $entry;

    /**
     * Form configuration.
     *
     * @var array|null
     */
    private $form;

    /**
     * Constructor.
     *
     * @param int $entry_id Entry ID.
     */
    public function __construct( $entry_id ) {
        $this->entry_id = (int) $entry_id;
        $this->load_entry();
    }

    /**
     * Load entry data.
     */
    private function load_entry() {
        $entry_repo  = new FRE_Entry();
        $this->entry = $entry_repo->get( $this->entry_id );

        if ( $this->entry ) {
            $this->form = fre()->registry->get( $this->entry['form_id'] );

            // Mark as read.
            if ( $this->entry['status'] === 'unread' ) {
                $entry_repo->mark_read( $this->entry_id );
                $this->entry['status'] = 'read';
            }
        }
    }

    /**
     * Render the entry detail view.
     */
    public function render() {
        if ( ! $this->entry ) {
            wp_die( esc_html__( 'Entry not found.', 'form-runtime-engine' ) );
        }

        // Build back URL - preserve form_id filter if coming from filtered view.
        $back_url = admin_url( 'admin.php?page=fre-entries' );

        // Check the referer for form_id filter.
        $referer = isset( $_SERVER['HTTP_REFERER'] ) ? wp_unslash( $_SERVER['HTTP_REFERER'] ) : '';
        if ( $referer && strpos( $referer, 'form_id=' ) !== false ) {
            // Use the form_id from this entry.
            $back_url = add_query_arg( 'form_id', $this->entry['form_id'], $back_url );
        }

        // Also check if form_id was passed directly in the URL.
        if ( isset( $_GET['form_id'] ) ) {
            $back_url = add_query_arg( 'form_id', sanitize_key( $_GET['form_id'] ), admin_url( 'admin.php?page=fre-entries' ) );
        }

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <?php
                printf(
                    /* translators: %d: entry ID */
                    esc_html__( 'Entry #%d', 'form-runtime-engine' ),
                    $this->entry_id
                );
                ?>
            </h1>

            <a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action">
                <?php esc_html_e( 'Back to Entries', 'form-runtime-engine' ); ?>
            </a>

            <hr class="wp-header-end">

            <div id="poststuff">
                <div id="post-body" class="metabox-holder columns-2">

                    <!-- Main Content -->
                    <div id="post-body-content">
                        <div class="postbox">
                            <h2 class="hndle"><?php esc_html_e( 'Submission Data', 'form-runtime-engine' ); ?></h2>
                            <div class="inside">
                                <?php $this->render_fields(); ?>
                            </div>
                        </div>

                        <?php if ( ! empty( $this->entry['files'] ) ) : ?>
                            <div class="postbox">
                                <h2 class="hndle"><?php esc_html_e( 'Uploaded Files', 'form-runtime-engine' ); ?></h2>
                                <div class="inside">
                                    <?php $this->render_files(); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- Sidebar -->
                    <div id="postbox-container-1" class="postbox-container">
                        <div class="postbox">
                            <h2 class="hndle"><?php esc_html_e( 'Entry Details', 'form-runtime-engine' ); ?></h2>
                            <div class="inside">
                                <?php $this->render_sidebar(); ?>
                            </div>
                        </div>

                        <div class="postbox">
                            <h2 class="hndle"><?php esc_html_e( 'Actions', 'form-runtime-engine' ); ?></h2>
                            <div class="inside">
                                <?php $this->render_actions(); ?>
                            </div>
                        </div>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render field values.
     */
    private function render_fields() {
        $fields = $this->entry['fields'] ?? array();

        if ( empty( $fields ) ) {
            echo '<p>' . esc_html__( 'No data submitted.', 'form-runtime-engine' ) . '</p>';
            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr><th style="width:30%;">' . esc_html__( 'Field', 'form-runtime-engine' ) . '</th><th>' . esc_html__( 'Value', 'form-runtime-engine' ) . '</th></tr></thead>';
        echo '<tbody>';

        foreach ( $fields as $field_key => $value ) {
            $field_config = $this->get_field_config( $field_key );
            $label        = $field_config && ! empty( $field_config['label'] )
                ? $field_config['label']
                : ucfirst( str_replace( '_', ' ', $field_key ) );

            echo '<tr>';
            echo '<td><strong>' . esc_html( $label ) . '</strong></td>';
            echo '<td>' . $this->render_field_value( $field_key, $value, $field_config ) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render a field value safely (Fix #7: Stored XSS protection).
     *
     * @param string     $key    Field key.
     * @param mixed      $value  Field value.
     * @param array|null $config Field configuration.
     * @return string
     */
    private function render_field_value( $key, $value, $config = null ) {
        if ( $value === null || $value === '' ) {
            return '<em>' . esc_html__( '(empty)', 'form-runtime-engine' ) . '</em>';
        }

        $type = $config ? ( $config['type'] ?? 'text' ) : 'text';

        // Get field instance for formatting.
        $field_class = FRE_Autoloader::get_field_class( $type );
        if ( $field_class && class_exists( $field_class ) ) {
            $field_instance = new $field_class();
            // Fix #7: Apply wp_kses_post to sanitize output from field formatters.
            return wp_kses_post( $field_instance->format_value( $value, $config ?? array() ) );
        }

        // Fallback formatting.
        if ( is_array( $value ) ) {
            return esc_html( implode( ', ', array_map( 'strval', $value ) ) );
        }

        // Escape and handle newlines.
        return nl2br( esc_html( $value ) );
    }

    /**
     * Render uploaded files.
     */
    private function render_files() {
        $files = $this->entry['files'];

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Field', 'form-runtime-engine' ) . '</th>';
        echo '<th>' . esc_html__( 'Filename', 'form-runtime-engine' ) . '</th>';
        echo '<th>' . esc_html__( 'Size', 'form-runtime-engine' ) . '</th>';
        echo '<th>' . esc_html__( 'Type', 'form-runtime-engine' ) . '</th>';
        echo '<th>' . esc_html__( 'Actions', 'form-runtime-engine' ) . '</th>';
        echo '</tr></thead>';
        echo '<tbody>';

        foreach ( $files as $file ) {
            $field_config = $this->get_field_config( $file['field_key'] );
            $label        = $field_config && ! empty( $field_config['label'] )
                ? $field_config['label']
                : ucfirst( str_replace( '_', ' ', $file['field_key'] ) );

            $download_url = $file['attachment_id']
                ? wp_get_attachment_url( $file['attachment_id'] )
                : '';

            echo '<tr>';
            echo '<td>' . esc_html( $label ) . '</td>';
            echo '<td>' . esc_html( $file['file_name'] ) . '</td>';
            echo '<td>' . esc_html( size_format( $file['file_size'] ) ) . '</td>';
            echo '<td>' . esc_html( $file['mime_type'] ) . '</td>';
            echo '<td>';
            if ( $download_url ) {
                echo '<a href="' . esc_url( $download_url ) . '" download class="button button-small">';
                echo esc_html__( 'Download', 'form-runtime-engine' );
                echo '</a>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * Render sidebar with entry metadata.
     */
    private function render_sidebar() {
        $entry     = $this->entry;
        $form_title = $this->form ? ( $this->form['title'] ?: $entry['form_id'] ) : $entry['form_id'];

        ?>
        <ul class="fre-entry-meta">
            <li>
                <strong><?php esc_html_e( 'Form:', 'form-runtime-engine' ); ?></strong>
                <?php echo esc_html( $form_title ); ?>
                <?php if ( ! $this->form ) : ?>
                    <em>(<?php esc_html_e( 'deleted', 'form-runtime-engine' ); ?>)</em>
                <?php endif; ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Status:', 'form-runtime-engine' ); ?></strong>
                <?php
                if ( ! empty( $entry['is_spam'] ) ) {
                    echo '<span style="color:#d63638;">' . esc_html__( 'Spam', 'form-runtime-engine' ) . '</span>';
                } else {
                    echo esc_html( ucfirst( $entry['status'] ) );
                }
                ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'Submitted:', 'form-runtime-engine' ); ?></strong>
                <?php echo esc_html( date_i18n( 'F j, Y \a\t g:i a', strtotime( $entry['created_at'] ) ) ); ?>
            </li>
            <li>
                <strong><?php esc_html_e( 'IP Address:', 'form-runtime-engine' ); ?></strong>
                <?php echo esc_html( $entry['ip_address'] ?: '-' ); ?>
            </li>
            <?php if ( $entry['user_id'] ) : ?>
                <li>
                    <strong><?php esc_html_e( 'User:', 'form-runtime-engine' ); ?></strong>
                    <?php
                    $user = get_user_by( 'id', $entry['user_id'] );
                    if ( $user ) {
                        echo '<a href="' . esc_url( get_edit_user_link( $user->ID ) ) . '">';
                        echo esc_html( $user->display_name );
                        echo '</a>';
                    } else {
                        echo esc_html( $entry['user_id'] );
                    }
                    ?>
                </li>
            <?php endif; ?>
            <li>
                <strong><?php esc_html_e( 'Email Notification:', 'form-runtime-engine' ); ?></strong>
                <?php
                if ( ! empty( $entry['notification_sent'] ) ) {
                    echo '<span style="color:#46b450;">' . esc_html__( 'Sent', 'form-runtime-engine' ) . '</span>';
                    if ( ! empty( $entry['notification_sent_at'] ) ) {
                        echo ' <small>(' . esc_html( date_i18n( 'M j, g:i a', strtotime( $entry['notification_sent_at'] ) ) ) . ')</small>';
                    }
                } elseif ( isset( $entry['notification_sent'] ) && $entry['notification_sent'] === '0' ) {
                    echo '<span style="color:#d63638;">' . esc_html__( 'Failed', 'form-runtime-engine' ) . '</span>';
                    if ( ! empty( $entry['notification_error'] ) ) {
                        echo '<br><small>' . esc_html( $entry['notification_error'] ) . '</small>';
                    }
                } else {
                    echo esc_html__( 'Not sent', 'form-runtime-engine' );
                }
                ?>
            </li>
        </ul>

        <?php if ( ! empty( $entry['user_agent'] ) ) : ?>
            <details style="margin-top:15px;">
                <summary style="cursor:pointer;"><?php esc_html_e( 'User Agent', 'form-runtime-engine' ); ?></summary>
                <p style="font-size:11px;word-break:break-all;margin-top:5px;">
                    <?php echo esc_html( $entry['user_agent'] ); ?>
                </p>
            </details>
        <?php endif; ?>
        <?php
    }

    /**
     * Render action buttons.
     */
    private function render_actions() {
        $entry = $this->entry;

        ?>
        <div class="fre-entry-actions">
            <?php if ( $entry['status'] === 'read' ) : ?>
                <button type="button" class="button fre-mark-unread" data-entry-id="<?php echo (int) $entry['id']; ?>">
                    <?php esc_html_e( 'Mark as Unread', 'form-runtime-engine' ); ?>
                </button>
            <?php else : ?>
                <button type="button" class="button fre-mark-read" data-entry-id="<?php echo (int) $entry['id']; ?>">
                    <?php esc_html_e( 'Mark as Read', 'form-runtime-engine' ); ?>
                </button>
            <?php endif; ?>

            <?php if ( empty( $entry['is_spam'] ) ) : ?>
                <button type="button" class="button fre-mark-spam" data-entry-id="<?php echo (int) $entry['id']; ?>">
                    <?php esc_html_e( 'Mark as Spam', 'form-runtime-engine' ); ?>
                </button>
            <?php endif; ?>

            <button type="button" class="button button-link-delete fre-delete-entry" data-entry-id="<?php echo (int) $entry['id']; ?>" style="color:#b32d2e;">
                <?php esc_html_e( 'Delete Entry', 'form-runtime-engine' ); ?>
            </button>
        </div>
        <?php
    }

    /**
     * Get field configuration by key.
     *
     * @param string $field_key Field key.
     * @return array|null
     */
    private function get_field_config( $field_key ) {
        if ( ! $this->form ) {
            return null;
        }

        foreach ( $this->form['fields'] as $field ) {
            if ( $field['key'] === $field_key ) {
                return $field;
            }
        }

        return null;
    }
}
