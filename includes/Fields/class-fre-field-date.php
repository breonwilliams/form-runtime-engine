<?php
/**
 * Date Field Type for Form Runtime Engine.
 *
 * Native HTML5 date input with min/max validation.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Date input field type.
 */
class FRE_Field_Date extends FRE_Field_Type_Abstract {

    /**
     * Field type slug.
     *
     * @var string
     */
    protected $type = 'date';

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

        $attributes['class'] = 'fre-field__input fre-field__input--date';

        // Min date attribute.
        if ( ! empty( $field['min'] ) ) {
            $attributes['min'] = $this->sanitize_date( $field['min'] );
        }

        // Max date attribute.
        if ( ! empty( $field['max'] ) ) {
            $attributes['max'] = $this->sanitize_date( $field['max'] );
        }

        $input = sprintf( '<input%s />', $this->build_attributes( $attributes ) );

        return $this->render_wrapper( $field, $form_id, $input );
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
        // Run parent validation (required).
        $parent_result = parent::validate( $value, $field, $form );
        if ( is_wp_error( $parent_result ) ) {
            return $parent_result;
        }

        // Skip further validation if empty (and not required).
        if ( $this->is_empty( $value ) ) {
            return true;
        }

        // Validate date format (YYYY-MM-DD).
        if ( ! $this->is_valid_date_format( $value ) ) {
            return new WP_Error(
                'invalid_date_format',
                sprintf(
                    /* translators: %s: field label */
                    __( '%s must be a valid date.', 'form-runtime-engine' ),
                    $this->get_label( $field )
                )
            );
        }

        // Validate min date.
        if ( ! empty( $field['min'] ) ) {
            $min_date = $this->sanitize_date( $field['min'] );
            if ( $min_date && $value < $min_date ) {
                return new WP_Error(
                    'date_too_early',
                    sprintf(
                        /* translators: 1: field label, 2: minimum date */
                        __( '%1$s must be on or after %2$s.', 'form-runtime-engine' ),
                        $this->get_label( $field ),
                        $this->format_date_for_display( $min_date )
                    )
                );
            }
        }

        // Validate max date.
        if ( ! empty( $field['max'] ) ) {
            $max_date = $this->sanitize_date( $field['max'] );
            if ( $max_date && $value > $max_date ) {
                return new WP_Error(
                    'date_too_late',
                    sprintf(
                        /* translators: 1: field label, 2: maximum date */
                        __( '%1$s must be on or before %2$s.', 'form-runtime-engine' ),
                        $this->get_label( $field ),
                        $this->format_date_for_display( $max_date )
                    )
                );
            }
        }

        return true;
    }

    /**
     * Sanitize the field value.
     *
     * @param mixed $value Field value.
     * @param array $field Field configuration.
     * @return string Sanitized date string (YYYY-MM-DD) or empty string.
     */
    public function sanitize( $value, array $field ) {
        $value = sanitize_text_field( $value );

        // Return empty string if not a valid date format.
        if ( ! $this->is_valid_date_format( $value ) ) {
            return '';
        }

        return $value;
    }

    /**
     * Format value for display in admin.
     *
     * @param mixed $value Raw value.
     * @param array $field Field configuration.
     * @return string
     */
    public function format_value( $value, array $field ) {
        if ( empty( $value ) ) {
            return '';
        }

        return esc_html( $this->format_date_for_display( $value ) );
    }

    /**
     * Check if a date string is valid YYYY-MM-DD format.
     *
     * @param string $date Date string.
     * @return bool
     */
    private function is_valid_date_format( $date ) {
        if ( ! is_string( $date ) ) {
            return false;
        }

        // Check format.
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
            return false;
        }

        // Check if it's a real date.
        $parts = explode( '-', $date );
        return checkdate( (int) $parts[1], (int) $parts[2], (int) $parts[0] );
    }

    /**
     * Sanitize a date string to YYYY-MM-DD format.
     *
     * @param string $date Date string.
     * @return string|null Sanitized date or null if invalid.
     */
    private function sanitize_date( $date ) {
        $date = sanitize_text_field( $date );

        if ( $this->is_valid_date_format( $date ) ) {
            return $date;
        }

        // Try to parse various date formats.
        $timestamp = strtotime( $date );
        if ( $timestamp !== false ) {
            return gmdate( 'Y-m-d', $timestamp );
        }

        return null;
    }

    /**
     * Format a date for display using WordPress date format.
     *
     * @param string $date Date in YYYY-MM-DD format.
     * @return string Formatted date.
     */
    private function format_date_for_display( $date ) {
        $timestamp = strtotime( $date );
        if ( $timestamp === false ) {
            return $date;
        }

        return date_i18n( get_option( 'date_format' ), $timestamp );
    }
}
