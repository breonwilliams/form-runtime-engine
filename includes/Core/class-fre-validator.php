<?php
/**
 * Validator for Form Runtime Engine.
 *
 * Handles all form field validation.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form validation handler.
 */
class FRE_Validator {

    /**
     * Field type instances cache.
     *
     * @var array
     */
    private $field_instances = array();

    /**
     * Validation errors.
     *
     * @var array
     */
    private $errors = array();

    /**
     * Validate all form fields.
     *
     * @param array $form_config Form configuration.
     * @param array $data        Submitted data.
     * @return bool|WP_Error True if valid, WP_Error with all errors otherwise.
     */
    public function validate( array $form_config, array $data ) {
        $this->errors = array();

        foreach ( $form_config['fields'] as $field ) {
            $field_type = $this->get_field_instance( $field['type'] );

            if ( ! $field_type ) {
                continue;
            }

            // Skip non-storing fields.
            if ( ! $field_type->stores_value() ) {
                continue;
            }

            // Skip file fields (handled separately).
            if ( $field_type->is_file_field() ) {
                continue;
            }

            // Server-side condition evaluation is authoritative.
            // Fields with conditions that evaluate to false (hidden) skip validation.
            // Note: Client _fre_hidden_fields hint removed - server-side is authoritative.
            if ( ! empty( $field['conditions'] ) && ! $this->evaluate_conditions( $field['conditions'], $form_config, $data ) ) {
                continue;
            }

            // Get submitted value.
            $name  = $field_type->get_name( $field );
            $value = isset( $data[ $name ] ) ? $data[ $name ] : '';

            // Validate field.
            $result = $field_type->validate( $value, $field, $form_config );

            if ( is_wp_error( $result ) ) {
                $this->errors[ $field['key'] ] = $result->get_error_message();
            }
        }

        /**
         * Filter validation errors.
         *
         * @param array $errors      Validation errors keyed by field key.
         * @param array $form_config Form configuration.
         * @param array $data        Submitted data.
         */
        $this->errors = apply_filters( 'fre_validation_errors', $this->errors, $form_config, $data );

        if ( ! empty( $this->errors ) ) {
            $error = new WP_Error( 'validation_failed', __( 'Please correct the errors below.', 'form-runtime-engine' ) );
            $error->add_data( array( 'field_errors' => $this->errors ) );
            return $error;
        }

        return true;
    }

    /**
     * Validate a single field.
     *
     * @param array $field       Field configuration.
     * @param mixed $value       Field value.
     * @param array $form_config Form configuration.
     * @return bool|WP_Error
     */
    public function validate_field( array $field, $value, array $form_config ) {
        $field_type = $this->get_field_instance( $field['type'] );

        if ( ! $field_type ) {
            return new WP_Error(
                'unknown_field_type',
                sprintf(
                    /* translators: %s: field type */
                    __( 'Unknown field type: %s', 'form-runtime-engine' ),
                    $field['type']
                )
            );
        }

        return $field_type->validate( $value, $field, $form_config );
    }

    /**
     * Get validation errors.
     *
     * @return array
     */
    public function get_errors() {
        return $this->errors;
    }

    /**
     * Check if there are validation errors.
     *
     * @return bool
     */
    public function has_errors() {
        return ! empty( $this->errors );
    }

    /**
     * Get error for specific field.
     *
     * @param string $field_key Field key.
     * @return string|null Error message or null if no error.
     */
    public function get_field_error( $field_key ) {
        return isset( $this->errors[ $field_key ] ) ? $this->errors[ $field_key ] : null;
    }

    /**
     * Add a validation error.
     *
     * @param string $field_key Field key.
     * @param string $message   Error message.
     */
    public function add_error( $field_key, $message ) {
        $this->errors[ $field_key ] = $message;
    }

    /**
     * Clear all errors.
     */
    public function clear_errors() {
        $this->errors = array();
    }

    /**
     * Validate input length limits.
     *
     * Prevents memory exhaustion from oversized inputs.
     *
     * @param array $data Submitted data.
     * @param int   $max_length Maximum length per field (default: 100000 chars).
     * @return bool|WP_Error
     */
    public function validate_input_lengths( array $data, $max_length = 100000 ) {
        foreach ( $data as $key => $value ) {
            if ( is_string( $value ) && mb_strlen( $value ) > $max_length ) {
                return new WP_Error(
                    'input_too_long',
                    __( 'Input data exceeds maximum allowed length.', 'form-runtime-engine' )
                );
            }

            if ( is_array( $value ) ) {
                foreach ( $value as $item ) {
                    if ( is_string( $item ) && mb_strlen( $item ) > $max_length ) {
                        return new WP_Error(
                            'input_too_long',
                            __( 'Input data exceeds maximum allowed length.', 'form-runtime-engine' )
                        );
                    }
                }
            }
        }

        return true;
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
     * Evaluate conditions for a field (server-side verification).
     *
     * @param array $conditions   Conditions configuration.
     * @param array $form_config  Form configuration.
     * @param array $data         Submitted data.
     * @return bool True if conditions are met (field should be shown/validated).
     */
    private function evaluate_conditions( array $conditions, array $form_config, array $data ) {
        if ( empty( $conditions['rules'] ) || ! is_array( $conditions['rules'] ) ) {
            return true;
        }

        $logic = isset( $conditions['logic'] ) ? $conditions['logic'] : 'and';
        $results = array();

        foreach ( $conditions['rules'] as $rule ) {
            $results[] = $this->evaluate_rule( $rule, $form_config, $data );
        }

        if ( $logic === 'or' ) {
            return in_array( true, $results, true );
        }

        // Default: 'and' logic.
        return ! in_array( false, $results, true );
    }

    /**
     * Evaluate a single condition rule.
     *
     * @param array $rule        Rule configuration.
     * @param array $form_config Form configuration.
     * @param array $data        Submitted data.
     * @return bool True if rule is met.
     */
    private function evaluate_rule( array $rule, array $form_config, array $data ) {
        if ( empty( $rule['field'] ) || ! isset( $rule['operator'] ) ) {
            return true;
        }

        $field_key = $rule['field'];
        $operator  = $rule['operator'];
        $value     = isset( $rule['value'] ) ? $rule['value'] : '';

        // Get the field value from submitted data.
        $field_value = $this->get_submitted_field_value( $field_key, $form_config, $data );

        switch ( $operator ) {
            case 'equals':
            case '==':
            case '=':
                return $field_value === $value;

            case 'not_equals':
            case '!=':
            case '<>':
                return $field_value !== $value;

            case 'contains':
                return strpos( strtolower( (string) $field_value ), strtolower( (string) $value ) ) !== false;

            case 'not_contains':
                return strpos( strtolower( (string) $field_value ), strtolower( (string) $value ) ) === false;

            case 'is_empty':
            case 'empty':
                return $field_value === '' || $field_value === null ||
                       ( is_array( $field_value ) && empty( $field_value ) );

            case 'is_not_empty':
            case 'not_empty':
                return $field_value !== '' && $field_value !== null &&
                       ! ( is_array( $field_value ) && empty( $field_value ) );

            case 'is_checked':
            case 'checked':
                return $field_value === true || $field_value === '1' || $field_value === 'on';

            case 'is_not_checked':
            case 'not_checked':
                return $field_value === false || $field_value === '' || $field_value === '0';

            case 'greater_than':
            case '>':
                return floatval( $field_value ) > floatval( $value );

            case 'less_than':
            case '<':
                return floatval( $field_value ) < floatval( $value );

            case 'greater_than_or_equals':
            case '>=':
                return floatval( $field_value ) >= floatval( $value );

            case 'less_than_or_equals':
            case '<=':
                return floatval( $field_value ) <= floatval( $value );

            case 'in':
                return is_array( $value ) && in_array( $field_value, $value, true );

            case 'not_in':
                return ! is_array( $value ) || ! in_array( $field_value, $value, true );

            default:
                return true;
        }
    }

    /**
     * Get a field's submitted value.
     *
     * @param string $field_key   Field key.
     * @param array  $form_config Form configuration.
     * @param array  $data        Submitted data.
     * @return mixed Field value.
     */
    private function get_submitted_field_value( $field_key, array $form_config, array $data ) {
        // Find the field configuration.
        $field_config = null;
        foreach ( $form_config['fields'] as $field ) {
            if ( $field['key'] === $field_key ) {
                $field_config = $field;
                break;
            }
        }

        if ( ! $field_config ) {
            return '';
        }

        $field_type = $this->get_field_instance( $field_config['type'] );
        if ( ! $field_type ) {
            return '';
        }

        $name = $field_type->get_name( $field_config );

        // Check for checkbox value (boolean).
        if ( $field_config['type'] === 'checkbox' && empty( $field_config['options'] ) ) {
            return isset( $data[ $name ] );
        }

        return isset( $data[ $name ] ) ? $data[ $name ] : '';
    }
}
