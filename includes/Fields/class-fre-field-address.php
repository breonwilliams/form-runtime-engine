<?php
/**
 * Address Field Type for Form Runtime Engine.
 *
 * Address autocomplete field using Google Places API.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Address autocomplete field type.
 */
class FRE_Field_Address extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'address';

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param string $value Current field value.
     * @param array  $form  Form configuration.
     * @return string
     */
    public function render( array $field, $value, array $form ) {
        $form_id    = isset( $form['id'] ) ? $form['id'] : '';
        $attributes = $this->get_common_attributes( $field, $form_id, $value );

        // Override type to 'text' since address is a text input with autocomplete.
        $attributes['type']  = 'text';
        $attributes['class'] = 'fre-field__input fre-field__input--address';

        // Disable browser autocomplete to prevent conflict with Google Places.
        $attributes['autocomplete'] = 'off';

        // Add data attributes for JavaScript initialization.
        $attributes['data-fre-address'] = 'true';

        // Country restrictions.
        if ( ! empty( $field['country_restriction'] ) ) {
            $countries = is_array( $field['country_restriction'] )
                ? $field['country_restriction']
                : array( $field['country_restriction'] );
            $attributes['data-country-restriction'] = wp_json_encode( array_map( 'strtolower', $countries ) );
        }

        // Check for API key.
        $api_key = get_option( 'fre_google_places_api_key', '' );

        $input = sprintf( '<input%s />', $this->build_attributes( $attributes ) );

        // Add hidden fields for parsed address components (optional).
        $input .= $this->render_component_fields( $field, $form_id );

        // Add warning if no API key is set (in admin preview or when displaying form).
        if ( empty( $api_key ) && current_user_can( 'manage_options' ) ) {
            $input .= sprintf(
                '<p class="fre-field__warning">%s <a href="%s">%s</a></p>',
                esc_html__( 'Address autocomplete requires a Google Places API key.', 'form-runtime-engine' ),
                esc_url( admin_url( 'admin.php?page=fre-settings' ) ),
                esc_html__( 'Configure API key', 'form-runtime-engine' )
            );
        }

        return $this->render_wrapper( $field, $form_id, $input );
    }

    /**
     * Render hidden fields for address components.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @return string HTML for hidden component fields.
     */
    private function render_component_fields( array $field, $form_id ) {
        $key = sanitize_key( $field['key'] );

        // These hidden fields will be populated by JavaScript when an address is selected.
        $components = array(
            'street_number',
            'route',
            'locality',
            'administrative_area_level_1',
            'postal_code',
            'country',
            'formatted_address',
            'lat',
            'lng',
        );

        $html = '';
        foreach ( $components as $component ) {
            $html .= sprintf(
                '<input type="hidden" name="fre_field_%s_%s" id="fre-%s-%s-%s" data-address-component="%s" />',
                esc_attr( $key ),
                esc_attr( $component ),
                esc_attr( $form_id ),
                esc_attr( $key ),
                esc_attr( $component ),
                esc_attr( $component )
            );
        }

        return $html;
    }

    /**
     * Validate the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error
     */
    public function validate( $value, array $field, array $form ) {
        // Run parent validation (required, length).
        $parent_result = parent::validate( $value, $field, $form );
        if ( is_wp_error( $parent_result ) ) {
            return $parent_result;
        }

        return true;
    }

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string Sanitized address string.
     */
    public function sanitize( $value, array $field ) {
        return sanitize_text_field( $value );
    }

    /**
     * Format value for display in admin.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        return esc_html( $value );
    }

    /**
     * Enqueue required scripts for address fields.
     *
     * Called by the renderer when an address field is present.
     */
    public static function enqueue_scripts() {
        $api_key = get_option( 'fre_google_places_api_key', '' );

        if ( empty( $api_key ) ) {
            return;
        }

        // Enqueue Google Places API.
        wp_enqueue_script(
            'google-places-api',
            add_query_arg(
                array(
                    'key'       => $api_key,
                    'libraries' => 'places',
                    'callback'  => 'freInitAddressFields',
                ),
                'https://maps.googleapis.com/maps/api/js'
            ),
            array( 'fre-frontend' ),
            null,
            true
        );

        // Mark script as async.
        add_filter( 'script_loader_tag', array( __CLASS__, 'add_async_attribute' ), 10, 2 );
    }

    /**
     * Add async attribute to Google Places script.
     *
     * @param string $tag    Script tag HTML.
     * @param string $handle Script handle.
     * @return string Modified script tag.
     */
    public static function add_async_attribute( $tag, $handle ) {
        if ( 'google-places-api' !== $handle ) {
            return $tag;
        }

        return str_replace( ' src', ' async defer src', $tag );
    }
}
