<?php
/**
 * Form Renderer for Form Runtime Engine.
 *
 * Generates HTML for forms based on configuration.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form renderer class.
 */
class FRE_Renderer {

    /**
     * Field type instances cache.
     *
     * @var array
     */
    private $field_instances = array();

    /**
     * Render a form.
     *
     * @param string $form_id Form identifier.
     * @param array  $args    Render arguments.
     * @return string Form HTML.
     */
    public function render( $form_id, array $args = array() ) {
        $form = fre()->registry->get( $form_id );

        if ( ! $form ) {
            return $this->render_error(
                sprintf(
                    /* translators: %s: form ID */
                    __( 'Form not found: %s', 'form-runtime-engine' ),
                    esc_html( $form_id )
                )
            );
        }

        // Add form ID to form config for field rendering.
        $form['id'] = $form_id;

        // Merge with defaults.
        $args = wp_parse_args( $args, array(
            'values'    => array(),
            'css_class' => '',
            'ajax'      => true,
        ) );

        // Enqueue assets.
        $this->enqueue_assets();

        // Build form HTML.
        return $this->build_form( $form, $args );
    }

    /**
     * Build form HTML.
     *
     * @param array $form Form configuration.
     * @param array $args Render arguments.
     * @return string
     */
    private function build_form( array $form, array $args ) {
        $form_id  = $form['id'];
        $settings = $form['settings'];

        // Build form classes.
        $classes = array( 'fre-form' );
        if ( ! empty( $settings['css_class'] ) ) {
            $classes[] = esc_attr( $settings['css_class'] );
        }
        if ( ! empty( $args['css_class'] ) ) {
            $classes[] = esc_attr( $args['css_class'] );
        }

        // Check for file fields to set enctype.
        $has_file_field = $this->has_file_field( $form );

        // Start form.
        $html = sprintf(
            '<form id="fre-form-%s" class="%s" method="post" data-form-id="%s"%s%s>',
            esc_attr( $form_id ),
            implode( ' ', $classes ),
            esc_attr( $form_id ),
            $has_file_field ? ' enctype="multipart/form-data"' : '',
            $args['ajax'] ? ' data-ajax="true"' : ''
        );

        // Nonce field.
        $html .= wp_nonce_field( 'fre_submit_' . $form_id, '_wpnonce', true, false );

        // Form ID hidden field.
        $html .= sprintf(
            '<input type="hidden" name="fre_form_id" value="%s" />',
            esc_attr( $form_id )
        );

        // Timestamp for timing check.
        if ( ! empty( $settings['spam_protection']['timing_check'] ) ) {
            $html .= sprintf(
                '<input type="hidden" name="_fre_timestamp" value="%s" />',
                esc_attr( time() )
            );
        }

        // Honeypot field.
        if ( ! empty( $settings['spam_protection']['honeypot'] ) ) {
            $html .= $this->render_honeypot( $form_id );
        }

        // Form title.
        if ( ! empty( $form['title'] ) ) {
            $html .= sprintf(
                '<h3 class="fre-form__title">%s</h3>',
                esc_html( $form['title'] )
            );
        }

        // Messages container.
        $html .= '<div class="fre-form__messages" role="alert" aria-live="polite"></div>';

        // Fields container.
        $html .= '<div class="fre-form__fields">';

        foreach ( $form['fields'] as $field ) {
            $value = isset( $args['values'][ $field['key'] ] )
                ? $args['values'][ $field['key'] ]
                : ( isset( $field['default'] ) ? $field['default'] : '' );

            $html .= $this->render_field( $field, $value, $form );
        }

        $html .= '</div>';

        // Submit button.
        $html .= $this->render_submit_button( $settings );

        // Close form.
        $html .= '</form>';

        /**
         * Filter the rendered form HTML.
         *
         * @param string $html    Form HTML.
         * @param string $form_id Form ID.
         * @param array  $form    Form configuration.
         * @param array  $args    Render arguments.
         */
        return apply_filters( 'fre_rendered_form', $html, $form_id, $form, $args );
    }

    /**
     * Render a single field.
     *
     * @param array  $field Field configuration.
     * @param mixed  $value Current value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render_field( array $field, $value, array $form ) {
        $type     = isset( $field['type'] ) ? $field['type'] : 'text';
        $instance = $this->get_field_instance( $type );

        if ( ! $instance ) {
            return $this->render_error(
                sprintf(
                    /* translators: %s: field type */
                    __( 'Unknown field type: %s', 'form-runtime-engine' ),
                    esc_html( $type )
                )
            );
        }

        /**
         * Filter the field value before rendering.
         *
         * @param mixed  $value   Field value.
         * @param array  $field   Field configuration.
         * @param array  $form    Form configuration.
         * @param string $form_id Form ID.
         */
        $value = apply_filters( 'fre_render_field_value', $value, $field, $form, $form['id'] );

        return $instance->render( $field, $value, $form );
    }

    /**
     * Get field type instance.
     *
     * @param string $type Field type slug.
     * @return FRE_Field_Type|null
     */
    private function get_field_instance( $type ) {
        if ( isset( $this->field_instances[ $type ] ) ) {
            return $this->field_instances[ $type ];
        }

        $class_name = FRE_Autoloader::get_field_class( $type );

        if ( ! $class_name || ! class_exists( $class_name ) ) {
            return null;
        }

        $this->field_instances[ $type ] = new $class_name();

        return $this->field_instances[ $type ];
    }

    /**
     * Render honeypot field.
     *
     * @param string $form_id Form ID.
     * @return string
     */
    private function render_honeypot( $form_id ) {
        // Use a realistic but obscure field name.
        $field_name = '_fre_website_url_' . substr( md5( $form_id ), 0, 8 );

        // Hidden via CSS, not hidden input type (bots might skip hidden inputs).
        return sprintf(
            '<div class="fre-form__hp" aria-hidden="true" style="position:absolute;left:-9999px;height:0;overflow:hidden;">
                <label for="%1$s">Website (leave blank)</label>
                <input type="text" name="%1$s" id="%1$s" value="" tabindex="-1" autocomplete="off" />
            </div>',
            esc_attr( $field_name )
        );
    }

    /**
     * Render submit button.
     *
     * @param array $settings Form settings.
     * @return string
     */
    private function render_submit_button( array $settings ) {
        $text = ! empty( $settings['submit_button_text'] )
            ? $settings['submit_button_text']
            : __( 'Submit', 'form-runtime-engine' );

        $html = '<div class="fre-form__submit">';
        $html .= sprintf(
            '<button type="submit" class="fre-form__submit-button">
                <span class="fre-form__submit-text">%s</span>
                <span class="fre-form__submit-loading" aria-hidden="true" style="display:none;">
                    <span class="fre-spinner"></span>
                    %s
                </span>
            </button>',
            esc_html( $text ),
            esc_html__( 'Submitting...', 'form-runtime-engine' )
        );
        $html .= '</div>';

        return $html;
    }

    /**
     * Check if form has file fields.
     *
     * @param array $form Form configuration.
     * @return bool
     */
    private function has_file_field( array $form ) {
        foreach ( $form['fields'] as $field ) {
            if ( isset( $field['type'] ) && $field['type'] === 'file' ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Render an error message.
     *
     * @param string $message Error message.
     * @return string
     */
    private function render_error( $message ) {
        if ( current_user_can( 'manage_options' ) ) {
            return sprintf(
                '<div class="fre-form-error">%s</div>',
                esc_html( $message )
            );
        }

        return '';
    }

    /**
     * Enqueue form assets.
     */
    private function enqueue_assets() {
        wp_enqueue_style( 'fre-frontend' );
        wp_enqueue_script( 'fre-frontend' );
    }

    /**
     * Get all registered field type instances.
     *
     * @return array
     */
    public function get_all_field_instances() {
        $types = FRE_Autoloader::get_field_types();

        foreach ( $types as $type ) {
            $this->get_field_instance( $type );
        }

        return $this->field_instances;
    }
}
