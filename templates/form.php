<?php
/**
 * Form template.
 *
 * This template can be overridden by copying it to your theme:
 * yourtheme/fre/form.php
 *
 * @package FormRuntimeEngine
 *
 * @var array  $form     Form configuration.
 * @var array  $args     Render arguments.
 * @var string $form_id  Form ID.
 * @var array  $settings Form settings.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>

<form
    id="fre-form-<?php echo esc_attr( $form_id ); ?>"
    class="fre-form <?php echo esc_attr( $settings['css_class'] ?? '' ); ?> <?php echo esc_attr( $args['css_class'] ?? '' ); ?>"
    method="post"
    data-form-id="<?php echo esc_attr( $form_id ); ?>"
    <?php echo $has_file_field ? 'enctype="multipart/form-data"' : ''; ?>
    <?php echo $args['ajax'] ? 'data-ajax="true"' : ''; ?>
>
    <?php wp_nonce_field( 'fre_submit_' . $form_id, '_wpnonce' ); ?>

    <input type="hidden" name="fre_form_id" value="<?php echo esc_attr( $form_id ); ?>" />

    <?php if ( ! empty( $settings['spam_protection']['timing_check'] ) ) : ?>
        <input type="hidden" name="_fre_timestamp" value="<?php echo esc_attr( time() ); ?>" />
    <?php endif; ?>

    <?php if ( ! empty( $settings['spam_protection']['honeypot'] ) ) : ?>
        <?php echo $honeypot_html; ?>
    <?php endif; ?>

    <?php if ( ! empty( $form['title'] ) ) : ?>
        <h3 class="fre-form__title"><?php echo esc_html( $form['title'] ); ?></h3>
    <?php endif; ?>

    <div class="fre-form__messages" role="alert" aria-live="polite"></div>

    <div class="fre-form__fields">
        <?php foreach ( $form['fields'] as $field ) : ?>
            <?php
            $value = isset( $args['values'][ $field['key'] ] )
                ? $args['values'][ $field['key'] ]
                : ( isset( $field['default'] ) ? $field['default'] : '' );

            echo $renderer->render_field( $field, $value, $form );
            ?>
        <?php endforeach; ?>
    </div>

    <div class="fre-form__submit">
        <button type="submit" class="fre-form__submit-button">
            <span class="fre-form__submit-text">
                <?php echo esc_html( $settings['submit_button_text'] ?? __( 'Submit', 'form-runtime-engine' ) ); ?>
            </span>
            <span class="fre-form__submit-loading" aria-hidden="true" style="display:none;">
                <span class="fre-spinner"></span>
                <?php esc_html_e( 'Submitting...', 'form-runtime-engine' ); ?>
            </span>
        </button>
    </div>
</form>
