<?php
/**
 * Email Notification Handler for Form Runtime Engine.
 *
 * NOTE: Uses direct database query for retrieving failed email queue.
 * Caching is avoided to ensure accurate real-time failure counts.
 *
 * @package FormRuntimeEngine
 *
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
 * Email notification handler.
 */
class FRE_Email_Notification {

    /**
     * Entry repository instance.
     *
     * @var FRE_Entry
     */
    private $entry_repo;

    /**
     * Maximum retry attempts.
     *
     * @var int
     */
    private const MAX_RETRIES = 3;

    /**
     * Retry delays in seconds (exponential backoff).
     *
     * @var array
     */
    private const RETRY_DELAYS = array( 300, 1800, 7200 ); // 5 min, 30 min, 2 hours

    /**
     * Constructor.
     */
    public function __construct() {
        $this->entry_repo = new FRE_Entry();
    }

    /**
     * Initialize WP-Cron hooks for email retry queue (Fix #1).
     *
     * Call this method during plugin initialization.
     */
    public static function init_hooks() {
        add_action( 'fre_retry_failed_email', array( __CLASS__, 'process_retry' ), 10, 2 );
        add_action( 'fre_process_email_queue', array( __CLASS__, 'process_queue' ) );

        // Schedule hourly queue processing if not already scheduled.
        if ( ! wp_next_scheduled( 'fre_process_email_queue' ) ) {
            wp_schedule_event( time(), 'hourly', 'fre_process_email_queue' );
        }
    }

    /**
     * Send email notification.
     *
     * @param int   $entry_id      Entry ID.
     * @param array $form_config   Form configuration.
     * @param array $entry_data    Submitted data.
     * @param array $uploaded_files Uploaded files data.
     * @return bool True on success.
     */
    public function send( $entry_id, array $form_config, array $entry_data, array $uploaded_files = array() ) {
        $notification = $form_config['settings']['notification'];

        if ( empty( $notification['enabled'] ) ) {
            return true;
        }

        // Build recipient list.
        $to = $this->parse_recipients( $notification['to'], $entry_data );

        if ( empty( $to ) ) {
            $this->update_notification_status( $entry_id, false, 'No valid recipients' );
            return false;
        }

        // Build subject.
        $subject = $this->parse_template( $notification['subject'], $entry_data, $form_config );
        $subject = $this->sanitize_email_header( $subject );

        // Build body.
        $body = $this->build_email_body( $form_config, $entry_data, $uploaded_files );

        // Build headers.
        $headers = $this->build_headers( $form_config, $entry_data );

        // Build attachments.
        $attachments = $this->get_attachments( $uploaded_files );

        /**
         * Filter notification body before sending.
         *
         * @param string $body        Email body.
         * @param array  $form_config Form configuration.
         * @param array  $entry_data  Entry data.
         * @param int    $entry_id    Entry ID.
         */
        $body = apply_filters( 'fre_notification_body', $body, $form_config, $entry_data, $entry_id );

        // Send email.
        $sent = wp_mail( $to, $subject, $body, $headers, $attachments );

        // Update entry with notification status.
        $this->update_notification_status( $entry_id, $sent, $sent ? null : 'wp_mail returned false' );

        if ( ! $sent ) {
            FRE_Logger::error( "Email notification failed for entry {$entry_id}" );
            $this->increment_failure_counter();

            // Fix #1: Schedule retry for failed email.
            $this->schedule_retry( $entry_id, $form_config, $entry_data, $uploaded_files );
        }

        /**
         * Fires after notification is sent.
         *
         * @param bool   $sent        Whether email was sent.
         * @param int    $entry_id    Entry ID.
         * @param array  $form_config Form configuration.
         * @param array  $entry_data  Entry data.
         */
        do_action( 'fre_notification_sent', $sent, $entry_id, $form_config, $entry_data );

        return $sent;
    }

    /**
     * Parse recipient string(s).
     *
     * @param string|array $recipients Recipient(s).
     * @param array        $entry_data Entry data.
     * @return array Valid email addresses.
     */
    private function parse_recipients( $recipients, array $entry_data ) {
        if ( is_string( $recipients ) ) {
            $recipients = explode( ',', $recipients );
        }

        $valid = array();

        foreach ( $recipients as $recipient ) {
            $recipient = trim( $recipient );
            $recipient = $this->parse_template( $recipient, $entry_data );
            $recipient = sanitize_email( $recipient );

            if ( is_email( $recipient ) ) {
                $valid[] = $recipient;
            }
        }

        return $valid;
    }

    /**
     * Parse template variables.
     *
     * Supports: {field:key}, {admin_email}, {site_name}, {site_url}, {entry_id}
     *
     * @param string $template    Template string.
     * @param array  $entry_data  Entry data.
     * @param array  $form_config Form configuration (optional).
     * @return string Parsed string.
     */
    private function parse_template( $template, array $entry_data, array $form_config = array() ) {
        // Replace field placeholders.
        $template = preg_replace_callback(
            '/\{field:([^}]+)\}/',
            function( $matches ) use ( $entry_data ) {
                $field_key = $matches[1];
                if ( isset( $entry_data[ $field_key ] ) ) {
                    $value = $entry_data[ $field_key ];
                    return is_array( $value ) ? implode( ', ', $value ) : $value;
                }
                return '';
            },
            $template
        );

        // System variables.
        $replacements = array(
            '{admin_email}' => get_option( 'admin_email' ),
            '{site_name}'   => get_bloginfo( 'name' ),
            '{site_url}'    => home_url(),
            '{form_title}'  => isset( $form_config['title'] ) ? $form_config['title'] : '',
        );

        return str_replace( array_keys( $replacements ), array_values( $replacements ), $template );
    }

    /**
     * Build email body.
     *
     * @param array $form_config   Form configuration.
     * @param array $entry_data    Entry data.
     * @param array $uploaded_files Uploaded files.
     * @return string HTML email body.
     */
    private function build_email_body( array $form_config, array $entry_data, array $uploaded_files ) {
        $fields = $form_config['fields'];

        ob_start();

        // Try to load template.
        $template = FRE_PLUGIN_DIR . 'templates/email/notification.php';

        if ( file_exists( $template ) ) {
            include $template;
        } else {
            // Fallback inline template.
            $this->render_default_template( $form_config, $entry_data, $fields, $uploaded_files );
        }

        return ob_get_clean();
    }

    /**
     * Render default email template.
     *
     * @param array $form_config   Form configuration.
     * @param array $entry_data    Entry data.
     * @param array $fields        Field configurations.
     * @param array $uploaded_files Uploaded files.
     */
    private function render_default_template( $form_config, $entry_data, $fields, $uploaded_files ) {
        $site_name = get_bloginfo( 'name' );
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
        </head>
        <body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
            <div style="background: #f8f9fa; border-radius: 8px; padding: 20px; margin-bottom: 20px;">
                <h2 style="margin: 0 0 10px 0; color: #1a1a1a;">
                    <?php echo esc_html( $form_config['title'] ?: __( 'New Form Submission', 'form-runtime-engine' ) ); ?>
                </h2>
                <p style="margin: 0; color: #666; font-size: 14px;">
                    <?php
                    printf(
                        /* translators: %s: date and time */
                        esc_html__( 'Received on %s', 'form-runtime-engine' ),
                        esc_html( current_time( 'F j, Y \a\t g:i a' ) )
                    );
                    ?>
                </p>
            </div>

            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ( $fields as $field ) : ?>
                    <?php
                    // Skip non-storing fields.
                    if ( $field['type'] === 'message' ) {
                        continue;
                    }

                    $value = isset( $entry_data[ $field['key'] ] ) ? $entry_data[ $field['key'] ] : '';

                    // Format value.
                    if ( is_array( $value ) ) {
                        $value = implode( ', ', $value );
                    }

                    // Skip empty optional fields.
                    if ( empty( $value ) && empty( $field['required'] ) ) {
                        continue;
                    }

                    $label = ! empty( $field['label'] ) ? $field['label'] : ucfirst( $field['key'] );
                    ?>
                    <tr>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; width: 30%; font-weight: 600; color: #555;">
                            <?php echo esc_html( $label ); ?>
                        </td>
                        <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: top;">
                            <?php
                            if ( $field['type'] === 'email' && ! empty( $value ) ) {
                                echo '<a href="mailto:' . esc_attr( $value ) . '">' . esc_html( $value ) . '</a>';
                            } elseif ( $field['type'] === 'textarea' ) {
                                echo nl2br( esc_html( $value ) );
                            } elseif ( $field['type'] === 'checkbox' && empty( $field['options'] ) ) {
                                echo $value ? esc_html__( 'Yes', 'form-runtime-engine' ) : esc_html__( 'No', 'form-runtime-engine' );
                            } else {
                                echo esc_html( $value ?: '-' );
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>

                <?php if ( ! empty( $uploaded_files ) ) : ?>
                    <?php foreach ( $uploaded_files as $field_key => $files ) : ?>
                        <?php
                        $field_label = $field_key;
                        foreach ( $fields as $f ) {
                            if ( $f['key'] === $field_key && ! empty( $f['label'] ) ) {
                                $field_label = $f['label'];
                                break;
                            }
                        }

                        $file_list = isset( $files[0] ) ? $files : array( $files );
                        ?>
                        <tr>
                            <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: top; width: 30%; font-weight: 600; color: #555;">
                                <?php echo esc_html( $field_label ); ?>
                            </td>
                            <td style="padding: 10px; border-bottom: 1px solid #eee; vertical-align: top;">
                                <?php foreach ( $file_list as $file ) : ?>
                                    <div style="margin-bottom: 5px;">
                                        <a href="<?php echo esc_url( $file['file_url'] ); ?>" style="color: #0066cc;">
                                            <?php echo esc_html( $file['file_name'] ); ?>
                                        </a>
                                        <span style="color: #999; font-size: 12px;">
                                            (<?php echo esc_html( size_format( $file['file_size'] ) ); ?>)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>

            <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #eee; color: #999; font-size: 12px;">
                <?php
                printf(
                    /* translators: %s: site name */
                    esc_html__( 'This email was sent from %s', 'form-runtime-engine' ),
                    esc_html( $site_name )
                );
                ?>
            </div>
        </body>
        </html>
        <?php
    }

    /**
     * Build email headers.
     *
     * @param array $form_config Form configuration.
     * @param array $entry_data  Entry data.
     * @return array Headers.
     */
    private function build_headers( array $form_config, array $entry_data ) {
        $headers      = array();
        $notification = $form_config['settings']['notification'];

        // From header.
        $from_name = isset( $notification['from_name'] )
            ? $this->sanitize_email_header( $this->parse_template( $notification['from_name'], $entry_data, $form_config ) )
            : get_bloginfo( 'name' );

        $from_email = isset( $notification['from_email'] )
            ? sanitize_email( $this->parse_template( $notification['from_email'], $entry_data ) )
            : get_option( 'admin_email' );

        if ( is_email( $from_email ) ) {
            $headers[] = "From: {$from_name} <{$from_email}>";
        }

        // Reply-To header.
        if ( ! empty( $notification['reply_to'] ) ) {
            $reply_to = sanitize_email(
                $this->parse_template( $notification['reply_to'], $entry_data )
            );

            if ( is_email( $reply_to ) ) {
                $headers[] = "Reply-To: {$reply_to}";
            }
        }

        // Content type.
        $headers[] = 'Content-Type: text/html; charset=UTF-8';

        return $headers;
    }

    /**
     * Sanitize email header value (Fix #17: Enhanced header injection protection).
     *
     * @param string $value Header value.
     * @return string Sanitized value.
     */
    private function sanitize_email_header( $value ) {
        // Strip null bytes.
        $value = str_replace( "\0", '', $value );

        // Strip newlines and carriage returns to prevent header injection.
        $value = preg_replace( '/[\r\n]/', '', $value );

        // Remove URL-encoded newlines.
        $value = preg_replace( '/%0[ad]/i', '', $value );

        // Remove other control characters.
        $value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $value );

        // Remove header injection attempts (with any amount of whitespace).
        $headers = 'to|cc|bcc|from|reply-to|subject|content-type|mime-version|content-transfer-encoding';
        $value = preg_replace( '/(' . $headers . ')\s*:/i', '', $value );

        return sanitize_text_field( $value );
    }

    /**
     * Get file attachments.
     *
     * @param array $uploaded_files Uploaded files data.
     * @return array File paths for attachment.
     */
    private function get_attachments( array $uploaded_files ) {
        $attachments = array();

        foreach ( $uploaded_files as $files ) {
            $file_list = isset( $files[0] ) ? $files : array( $files );

            foreach ( $file_list as $file ) {
                if ( ! empty( $file['file_path'] ) && file_exists( $file['file_path'] ) ) {
                    $attachments[] = $file['file_path'];
                }
            }
        }

        return $attachments;
    }

    /**
     * Update entry notification status.
     *
     * @param int         $entry_id Entry ID.
     * @param bool        $sent     Whether sent successfully.
     * @param string|null $error    Error message if failed.
     */
    private function update_notification_status( $entry_id, $sent, $error = null ) {
        if ( ! $entry_id ) {
            return;
        }

        $this->entry_repo->update( $entry_id, array(
            'notification_sent'    => $sent ? 1 : 0,
            'notification_sent_at' => $sent ? current_time( 'mysql' ) : null,
            'notification_error'   => $error,
        ) );
    }

    /**
     * Increment email failure counter.
     */
    private function increment_failure_counter() {
        $failures = get_option( 'fre_email_failures', 0 );
        update_option( 'fre_email_failures', $failures + 1 );
    }

    /**
     * Reset email failure counter.
     */
    public function reset_failure_counter() {
        delete_option( 'fre_email_failures' );
    }

    /**
     * Get current failure count.
     *
     * @return int
     */
    public function get_failure_count() {
        return (int) get_option( 'fre_email_failures', 0 );
    }

    /**
     * Schedule a retry for failed email notification (Fix #1).
     *
     * @param int   $entry_id       Entry ID.
     * @param array $form_config    Form configuration.
     * @param array $entry_data     Submitted data.
     * @param array $uploaded_files Uploaded files.
     * @param int   $attempt        Current attempt number (0-indexed).
     */
    private function schedule_retry( $entry_id, array $form_config, array $entry_data, array $uploaded_files, $attempt = 0 ) {
        if ( $attempt >= self::MAX_RETRIES ) {
            // Max retries reached - add to failed queue for admin review.
            $this->add_to_failed_queue( $entry_id, $form_config );
            return;
        }

        $delay = self::RETRY_DELAYS[ $attempt ] ?? 7200;

        wp_schedule_single_event(
            time() + $delay,
            'fre_retry_failed_email',
            array( $entry_id, $attempt + 1 )
        );

        FRE_Logger::info( sprintf(
            'Scheduled email retry for entry %d, attempt %d, delay %d seconds',
            $entry_id,
            $attempt + 1,
            $delay
        ) );
    }

    /**
     * Process a scheduled email retry (Fix #1).
     *
     * @param int $entry_id Entry ID.
     * @param int $attempt  Attempt number (1-indexed).
     */
    public static function process_retry( $entry_id, $attempt ) {
        $handler = new self();
        $entry   = $handler->entry_repo->get( $entry_id );

        if ( ! $entry ) {
            FRE_Logger::error( "Email retry failed - entry {$entry_id} not found" );
            return;
        }

        // Check if already sent.
        if ( ! empty( $entry['notification_sent'] ) ) {
            return;
        }

        // Get form configuration.
        $form_config = fre()->registry->get( $entry['form_id'] );

        if ( ! $form_config || empty( $form_config['settings']['notification']['enabled'] ) ) {
            return;
        }

        // Rebuild notification.
        $notification = $form_config['settings']['notification'];
        $entry_data   = $entry['fields'];

        // Build recipient list.
        $to = $handler->parse_recipients( $notification['to'], $entry_data );

        if ( empty( $to ) ) {
            $handler->entry_repo->update( $entry_id, array(
                'notification_error' => 'No valid recipients on retry',
            ) );
            return;
        }

        // Build subject.
        $subject = $handler->parse_template( $notification['subject'], $entry_data, $form_config );
        $subject = $handler->sanitize_email_header( $subject );

        // Build body.
        $body = $handler->build_email_body( $form_config, $entry_data, array() );

        // Build headers.
        $headers = $handler->build_headers( $form_config, $entry_data );

        // Send email.
        $sent = wp_mail( $to, $subject, $body, $headers );

        // Update entry status.
        $handler->entry_repo->update( $entry_id, array(
            'notification_sent'    => $sent ? 1 : 0,
            'notification_sent_at' => $sent ? current_time( 'mysql' ) : null,
            'notification_error'   => $sent ? null : "Retry attempt {$attempt} failed",
        ) );

        if ( $sent ) {
            FRE_Logger::info( "Email retry succeeded for entry {$entry_id} on attempt {$attempt}" );
            $handler->reset_failure_counter();
        } else {
            FRE_Logger::error( "Email retry failed for entry {$entry_id} on attempt {$attempt}" );
            // Schedule next retry.
            $handler->schedule_retry( $entry_id, $form_config, $entry_data, array(), $attempt );
        }
    }

    /**
     * Add entry to failed email queue for admin review (Fix #1).
     *
     * @param int   $entry_id    Entry ID.
     * @param array $form_config Form configuration.
     */
    private function add_to_failed_queue( $entry_id, array $form_config ) {
        $failed_queue = get_option( 'fre_failed_email_queue', array() );

        $failed_queue[] = array(
            'entry_id'  => $entry_id,
            'form_id'   => $form_config['id'] ?? '',
            'failed_at' => current_time( 'mysql' ),
        );

        // Limit queue size to 100 entries.
        if ( count( $failed_queue ) > 100 ) {
            $failed_queue = array_slice( $failed_queue, -100 );
        }

        // Use autoload=false to prevent loading on every request.
        // This option can grow large and is only needed in admin context.
        update_option( 'fre_failed_email_queue', $failed_queue, false );

        // Update entry with final failure status.
        $this->entry_repo->update( $entry_id, array(
            'notification_error' => 'Max retries exceeded - added to failed queue',
        ) );

        FRE_Logger::warning( "Entry {$entry_id} added to failed email queue after max retries" );

        /**
         * Fires when an email fails permanently after all retries.
         *
         * @param int   $entry_id    Entry ID.
         * @param array $form_config Form configuration.
         */
        do_action( 'fre_email_permanently_failed', $entry_id, $form_config );
    }

    /**
     * Get failed email queue for admin display (Fix #1).
     *
     * @return array
     */
    public static function get_failed_queue() {
        return get_option( 'fre_failed_email_queue', array() );
    }

    /**
     * Clear failed email queue (Fix #1).
     */
    public static function clear_failed_queue() {
        delete_option( 'fre_failed_email_queue' );
    }

    /**
     * Remove entry from failed queue (Fix #1).
     *
     * @param int $entry_id Entry ID.
     */
    public static function remove_from_failed_queue( $entry_id ) {
        $queue = get_option( 'fre_failed_email_queue', array() );
        $queue = array_filter( $queue, function( $item ) use ( $entry_id ) {
            return $item['entry_id'] != $entry_id;
        } );
        update_option( 'fre_failed_email_queue', array_values( $queue ) );
    }

    /**
     * Process email queue - check for any stuck entries (Fix #1).
     *
     * Called hourly by WP-Cron.
     */
    public static function process_queue() {
        global $wpdb;

        $handler = new self();
        $table   = $wpdb->prefix . 'fre_entries';

        // Find entries with failed notifications from the last 24 hours.
        $failed_entries = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, form_id FROM {$table}
             WHERE notification_sent = 0
             AND notification_error IS NOT NULL
             AND created_at > %s
             LIMIT 10",
            gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS )
        ) );

        foreach ( $failed_entries as $entry ) {
            // Check if retry is already scheduled.
            $scheduled = wp_next_scheduled( 'fre_retry_failed_email', array( (int) $entry->id, 1 ) );

            if ( ! $scheduled ) {
                // Schedule a new retry.
                $form_config = fre()->registry->get( $entry->form_id );
                if ( $form_config ) {
                    $handler->schedule_retry( $entry->id, $form_config, array(), array(), 0 );
                }
            }
        }
    }
}
