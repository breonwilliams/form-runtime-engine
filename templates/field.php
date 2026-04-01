<?php
/**
 * Field template.
 *
 * This template can be overridden by copying it to your theme:
 * yourtheme/fre/field.php
 *
 * @package FormRuntimeEngine
 *
 * @var array  $field   Field configuration.
 * @var string $value   Current field value.
 * @var array  $form    Form configuration.
 * @var string $form_id Form ID.
 * @var string $type    Field type.
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Build wrapper classes.
$classes = array(
    'fre-field',
    'fre-field--' . esc_attr( $type ),
);

if ( ! empty( $field['required'] ) ) {
    $classes[] = 'fre-field--required';
}

if ( ! empty( $field['css_class'] ) ) {
    $classes[] = esc_attr( $field['css_class'] );
}
?>

<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>" data-field-key="<?php echo esc_attr( $field['key'] ); ?>">
    <?php if ( ! empty( $field['label'] ) ) : ?>
        <label class="fre-field__label" for="<?php echo esc_attr( $input_id ); ?>">
            <?php echo esc_html( $field['label'] ); ?>
            <?php if ( ! empty( $field['required'] ) ) : ?>
                <span class="fre-required" aria-hidden="true">*</span>
            <?php endif; ?>
        </label>
    <?php endif; ?>

    <?php
    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $input_html is pre-escaped by field renderer.
    echo $input_html;
    ?>

    <?php if ( ! empty( $field['description'] ) ) : ?>
        <p class="fre-field__description"><?php echo esc_html( $field['description'] ); ?></p>
    <?php endif; ?>

    <div class="fre-field__error" role="alert" aria-live="polite"></div>
</div>
