<?php
/**
 * Email Notification Handler for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
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
     * Constructor.
     */
    public function __construct() {
        $this->entry_repo = new FRE_Entry();
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
            error_log( "FRE: Email notification failed for entry {$entry_id}" );
            $this->increment_failure_counter();
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
     * Sanitize email header value.
     *
     * @param string $value Header value.
     * @return string Sanitized value.
     */
    private function sanitize_email_header( $value ) {
        // Strip newlines to prevent header injection.
        $value = preg_replace( '/[\r\n]/', '', $value );

        // Remove header injection attempts.
        $value = preg_replace( '/^(to|cc|bcc|from|reply-to):/i', '', $value );

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
}
