<?php
/**
 * Field Type Interface for Form Runtime Engine.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Interface for all field types.
 */
interface FRE_Field_Type {

    /**
     * Get the field type slug.
     *
     * @return string Field type slug (e.g., 'text', 'email').
     */
    public function get_type();

    /**
     * Render the field HTML.
     *
     * @param array  $field Field configuration.
     * @param string $value Current field value.
     * @param array  $form  Form configuration.
     * @return string Field HTML.
     */
    public function render( array $field, $value, array $form );

    /**
     * Validate the field value.
     *
     * @param mixed $value Field value to validate.
     * @param array $field Field configuration.
     * @param array $form  Form configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    public function validate( $value, array $field, array $form );

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value to sanitize.
     * @param array $field Field configuration.
     * @return mixed Sanitized value.
     */
    public function sanitize( $value, array $field );

    /**
     * Get the field's input name attribute.
     *
     * @param array $field Field configuration.
     * @return string Input name.
     */
    public function get_name( array $field );

    /**
     * Get the field's input ID attribute.
     *
     * @param array  $field   Field configuration.
     * @param string $form_id Form ID.
     * @return string Input ID.
     */
    public function get_id( array $field, $form_id );

    /**
     * Check if this field type stores a value.
     *
     * @return bool True if field stores a value.
     */
    public function stores_value();

    /**
     * Check if this field type supports file uploads.
     *
     * @return bool True if field handles file uploads.
     */
    public function is_file_field();

    /**
     * Format the value for display in admin.
     *
     * @param mixed $value Raw field value.
     * @param array $field Field configuration.
     * @return string Formatted value for display.
     */
    public function format_value( $value, array $field );

    /**
     * Format the value for CSV export.
     *
     * @param mixed $value Raw field value.
     * @param array $field Field configuration.
     * @return string Value for CSV.
     */
    public function format_csv_value( $value, array $field );
}
