<?php
/**
 * Email notification template.
 *
 * This template can be overridden by copying it to your theme:
 * yourtheme/fre/email/notification.php
 *
 * @package FormRuntimeEngine
 *
 * @var array $form_config    Form configuration.
 * @var array $entry_data     Submitted field values.
 * @var array $fields         Field configurations.
 * @var array $uploaded_files Uploaded files data.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

$site_name  = get_bloginfo( 'name' );
$form_title = ! empty( $form_config['title'] ) ? $form_config['title'] : __( 'New Form Submission', 'form-runtime-engine' );
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo esc_html( $form_title ); ?></title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; line-height: 1.6; color: #333333; max-width: 600px; margin: 0 auto; padding: 20px; background-color: #f5f5f5;">
    <div style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">

        <!-- Header -->
        <div style="background-color: #0073aa; padding: 20px 30px;">
            <h1 style="margin: 0; color: #ffffff; font-size: 24px; font-weight: 600;">
                <?php echo esc_html( $form_title ); ?>
            </h1>
            <p style="margin: 8px 0 0; color: rgba(255,255,255,0.8); font-size: 14px;">
                <?php
                printf(
                    /* translators: %s: date and time */
                    esc_html__( 'Received on %s', 'form-runtime-engine' ),
                    esc_html( current_time( 'F j, Y \a\t g:i a' ) )
                );
                ?>
            </p>
        </div>

        <!-- Content -->
        <div style="padding: 30px;">
            <table style="width: 100%; border-collapse: collapse;">
                <?php foreach ( $fields as $field ) : ?>
                    <?php
                    // Skip non-storing fields.
                    if ( $field['type'] === 'message' ) {
                        continue;
                    }

                    $raw_value = isset( $entry_data[ $field['key'] ] ) ? $entry_data[ $field['key'] ] : '';

                    // Skip empty optional fields (test against raw value before label resolution).
                    $is_empty = is_array( $raw_value )
                        ? empty( array_filter( $raw_value, function( $v ) { return $v !== '' && $v !== null; } ) )
                        : ( $raw_value === '' || $raw_value === null );

                    if ( $is_empty && empty( $field['required'] ) ) {
                        continue;
                    }

                    // Resolve raw value to human-readable display text.
                    // resolve_display_value handles select/radio/checkbox-with-options
                    // (value → label) and single checkbox ("1" → "Yes" / "" → "No").
                    $display_value = FRE_Field_Type_Abstract::resolve_display_value( $raw_value, $field );
                    $label         = ! empty( $field['label'] ) ? $field['label'] : ucfirst( str_replace( '_', ' ', $field['key'] ) );
                    ?>
                    <tr>
                        <td style="padding: 12px 0; border-bottom: 1px solid #eeeeee; vertical-align: top; width: 35%; font-weight: 600; color: #555555;">
                            <?php echo esc_html( $label ); ?>
                        </td>
                        <td style="padding: 12px 0 12px 15px; border-bottom: 1px solid #eeeeee; vertical-align: top; color: #333333;">
                            <?php
                            if ( $field['type'] === 'email' && $display_value !== '' ) {
                                echo '<a href="mailto:' . esc_attr( $display_value ) . '" style="color: #0073aa; text-decoration: none;">' . esc_html( $display_value ) . '</a>';
                            } elseif ( $field['type'] === 'tel' && $display_value !== '' ) {
                                $tel_digits = preg_replace( '/[^0-9+]/', '', $display_value );
                                echo '<a href="tel:' . esc_attr( $tel_digits ) . '" style="color: #0073aa; text-decoration: none;">' . esc_html( $display_value ) . '</a>';
                            } elseif ( $field['type'] === 'textarea' ) {
                                echo nl2br( esc_html( $display_value ) );
                            } else {
                                echo esc_html( $display_value !== '' ? $display_value : '-' );
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
                            <td style="padding: 12px 0; border-bottom: 1px solid #eeeeee; vertical-align: top; width: 35%; font-weight: 600; color: #555555;">
                                <?php echo esc_html( $field_label ); ?>
                            </td>
                            <td style="padding: 12px 0 12px 15px; border-bottom: 1px solid #eeeeee; vertical-align: top;">
                                <?php foreach ( $file_list as $file ) : ?>
                                    <div style="margin-bottom: 8px;">
                                        <a href="<?php echo esc_url( $file['file_url'] ); ?>" style="color: #0073aa; text-decoration: none; font-weight: 500;">
                                            <?php echo esc_html( $file['file_name'] ); ?>
                                        </a>
                                        <span style="color: #999999; font-size: 12px; margin-left: 8px;">
                                            (<?php echo esc_html( size_format( $file['file_size'] ) ); ?>)
                                        </span>
                                    </div>
                                <?php endforeach; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </table>
        </div>

        <!-- Footer -->
        <div style="background-color: #f8f9fa; padding: 20px 30px; border-top: 1px solid #eeeeee;">
            <p style="margin: 0; color: #999999; font-size: 12px;">
                <?php
                printf(
                    /* translators: %s: site name */
                    esc_html__( 'This email was sent from %s', 'form-runtime-engine' ),
                    esc_html( $site_name )
                );
                ?>
            </p>
            <p style="margin: 8px 0 0; color: #999999; font-size: 12px;">
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=fre-entries' ) ); ?>" style="color: #0073aa; text-decoration: none;">
                    <?php esc_html_e( 'View all entries', 'form-runtime-engine' ); ?>
                </a>
            </p>
        </div>
    </div>
</body>
</html>
