<?php
/**
 * JSON Schema Validator for Form Runtime Engine.
 *
 * Validates form configuration structure and field types.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * JSON schema validator class.
 */
class FRE_JSON_Schema_Validator {

    /**
     * Valid field types supported by the form engine.
     *
     * @var array
     */
    private static $valid_field_types = array(
        'text',
        'email',
        'tel',
        'textarea',
        'select',
        'radio',
        'checkbox',
        'file',
        'hidden',
        'message',
        'section',
    );

    /**
     * Known field properties (for warning about unknown properties).
     *
     * @var array
     */
    private static $known_field_properties = array(
        'key',
        'type',
        'label',
        'placeholder',
        'required',
        'css_class',
        'default',
        'description',
        'maxlength',
        'minlength',
        'readonly',
        'disabled',
        'autocomplete',
        'column',
        'section',
        'step',
        'conditions',
        'options',
        'inline',
        'multiple',
        'rows',
        'cols',
        'allowed_types',
        'max_size',
        'content',
        'style',
    );

    /**
     * Known settings properties.
     *
     * @var array
     */
    private static $known_settings_properties = array(
        'submit_button_text',
        'success_message',
        'redirect_url',
        'css_class',
        'store_entries',
        'notification',
        'spam_protection',
        'multistep',
    );

    /**
     * Validate form configuration.
     *
     * @param array $config The decoded form configuration array.
     * @return array {
     *     Validation result.
     *
     *     @type bool   $valid    Whether the configuration is valid.
     *     @type array  $errors   Array of error messages.
     *     @type array  $warnings Array of warning messages (non-fatal issues).
     * }
     */
    public static function validate( $config ) {
        $result = array(
            'valid'    => true,
            'errors'   => array(),
            'warnings' => array(),
        );

        // Must be an array.
        if ( ! is_array( $config ) ) {
            $result['valid']    = false;
            $result['errors'][] = __( 'Configuration must be a valid object.', 'form-runtime-engine' );
            return $result;
        }

        // Must have fields array.
        if ( ! isset( $config['fields'] ) || ! is_array( $config['fields'] ) ) {
            $result['valid']    = false;
            $result['errors'][] = __( 'Configuration must have a "fields" array.', 'form-runtime-engine' );
            return $result;
        }

        // Fields array cannot be empty.
        if ( empty( $config['fields'] ) ) {
            $result['valid']    = false;
            $result['errors'][] = __( 'The "fields" array cannot be empty.', 'form-runtime-engine' );
            return $result;
        }

        // Validate each field.
        $field_keys = array();

        foreach ( $config['fields'] as $index => $field ) {
            $field_result = self::validate_field( $field, $index );

            if ( ! $field_result['valid'] ) {
                $result['valid'] = false;
                $result['errors'] = array_merge( $result['errors'], $field_result['errors'] );
            }

            $result['warnings'] = array_merge( $result['warnings'], $field_result['warnings'] );

            // Track keys for duplicate check.
            if ( ! empty( $field['key'] ) ) {
                $field_keys[] = $field['key'];
            }
        }

        // Check for duplicate field keys.
        $duplicates = array_diff_assoc( $field_keys, array_unique( $field_keys ) );
        if ( ! empty( $duplicates ) ) {
            $result['valid']    = false;
            $result['errors'][] = sprintf(
                /* translators: %s: comma-separated list of duplicate keys */
                __( 'Duplicate field keys found: %s', 'form-runtime-engine' ),
                implode( ', ', array_unique( $duplicates ) )
            );
        }

        // Validate settings if present.
        if ( isset( $config['settings'] ) ) {
            $settings_result = self::validate_settings( $config['settings'] );
            $result['warnings'] = array_merge( $result['warnings'], $settings_result['warnings'] );
        }

        // Validate steps if present.
        if ( isset( $config['steps'] ) ) {
            $steps_result = self::validate_steps( $config['steps'], $config['fields'] );

            if ( ! $steps_result['valid'] ) {
                $result['valid'] = false;
                $result['errors'] = array_merge( $result['errors'], $steps_result['errors'] );
            }

            $result['warnings'] = array_merge( $result['warnings'], $steps_result['warnings'] );
        }

        return $result;
    }

    /**
     * Validate a single field configuration.
     *
     * @param mixed $field The field configuration.
     * @param int   $index The field index.
     * @return array Validation result with 'valid', 'errors', and 'warnings'.
     */
    private static function validate_field( $field, $index ) {
        $result = array(
            'valid'    => true,
            'errors'   => array(),
            'warnings' => array(),
        );

        // Field must be an array/object.
        if ( ! is_array( $field ) ) {
            $result['valid']    = false;
            $result['errors'][] = sprintf(
                /* translators: %d: field index */
                __( 'Field at index %d must be an object.', 'form-runtime-engine' ),
                $index
            );
            return $result;
        }

        // Must have key property.
        if ( empty( $field['key'] ) ) {
            $result['valid']    = false;
            $result['errors'][] = sprintf(
                /* translators: %d: field index */
                __( 'Field at index %d is missing required "key" property.', 'form-runtime-engine' ),
                $index
            );
        }

        // Must have type property.
        if ( empty( $field['type'] ) ) {
            $result['valid']    = false;
            $result['errors'][] = sprintf(
                /* translators: %s: field key or index */
                __( 'Field "%s" is missing required "type" property.', 'form-runtime-engine' ),
                isset( $field['key'] ) ? $field['key'] : 'index ' . $index
            );
        } else {
            // Type must be valid.
            $type = strtolower( $field['type'] );

            /**
             * Filter the valid field types.
             *
             * @param array $valid_types Array of valid field type slugs.
             */
            $valid_types = apply_filters( 'fre_field_types', self::$valid_field_types );

            if ( ! in_array( $type, $valid_types, true ) ) {
                $result['valid']    = false;
                $result['errors'][] = sprintf(
                    /* translators: 1: field type, 2: field key or index, 3: valid types list */
                    __( 'Invalid field type "%1$s" for field "%2$s". Valid types: %3$s', 'form-runtime-engine' ),
                    $field['type'],
                    isset( $field['key'] ) ? $field['key'] : 'index ' . $index,
                    implode( ', ', $valid_types )
                );
            }
        }

        // Validate field key format.
        if ( ! empty( $field['key'] ) && ! preg_match( '/^[a-zA-Z][a-zA-Z0-9_-]*$/', $field['key'] ) ) {
            $result['warnings'][] = sprintf(
                /* translators: %s: field key */
                __( 'Field key "%s" should start with a letter and contain only letters, numbers, underscores, and dashes.', 'form-runtime-engine' ),
                $field['key']
            );
        }

        // Check for unknown properties (warning only).
        foreach ( array_keys( $field ) as $prop ) {
            if ( ! in_array( $prop, self::$known_field_properties, true ) ) {
                $result['warnings'][] = sprintf(
                    /* translators: 1: unknown property name, 2: field key or index */
                    __( 'Unknown field property "%1$s" in field "%2$s".', 'form-runtime-engine' ),
                    $prop,
                    isset( $field['key'] ) ? $field['key'] : 'index ' . $index
                );
            }
        }

        // Validate options for select/radio/checkbox.
        if ( in_array( $field['type'] ?? '', array( 'select', 'radio' ), true ) ) {
            if ( empty( $field['options'] ) || ! is_array( $field['options'] ) ) {
                $result['valid']    = false;
                $result['errors'][] = sprintf(
                    /* translators: %s: field key or index */
                    __( 'Field "%s" requires an "options" array.', 'form-runtime-engine' ),
                    isset( $field['key'] ) ? $field['key'] : 'index ' . $index
                );
            }
        }

        // Validate conditions structure if present.
        if ( isset( $field['conditions'] ) ) {
            $conditions_result = self::validate_conditions( $field['conditions'], $field['key'] ?? 'index ' . $index );
            $result['warnings'] = array_merge( $result['warnings'], $conditions_result['warnings'] );
        }

        return $result;
    }

    /**
     * Validate conditions configuration.
     *
     * @param mixed  $conditions The conditions configuration.
     * @param string $field_id   The field key for error messages.
     * @return array Validation result.
     */
    private static function validate_conditions( $conditions, $field_id ) {
        $result = array(
            'warnings' => array(),
        );

        if ( ! is_array( $conditions ) ) {
            $result['warnings'][] = sprintf(
                /* translators: %s: field key */
                __( 'Field "%s" has invalid conditions format.', 'form-runtime-engine' ),
                $field_id
            );
            return $result;
        }

        if ( isset( $conditions['rules'] ) && is_array( $conditions['rules'] ) ) {
            foreach ( $conditions['rules'] as $rule_index => $rule ) {
                if ( ! is_array( $rule ) ) {
                    $result['warnings'][] = sprintf(
                        /* translators: 1: field key, 2: rule index */
                        __( 'Field "%1$s" has invalid condition rule at index %2$d.', 'form-runtime-engine' ),
                        $field_id,
                        $rule_index
                    );
                    continue;
                }

                if ( empty( $rule['field'] ) ) {
                    $result['warnings'][] = sprintf(
                        /* translators: 1: field key, 2: rule index */
                        __( 'Field "%1$s" condition rule at index %2$d is missing "field" property.', 'form-runtime-engine' ),
                        $field_id,
                        $rule_index
                    );
                }

                if ( empty( $rule['operator'] ) ) {
                    $result['warnings'][] = sprintf(
                        /* translators: 1: field key, 2: rule index */
                        __( 'Field "%1$s" condition rule at index %2$d is missing "operator" property.', 'form-runtime-engine' ),
                        $field_id,
                        $rule_index
                    );
                }
            }
        }

        return $result;
    }

    /**
     * Validate settings configuration.
     *
     * @param mixed $settings The settings configuration.
     * @return array Validation result.
     */
    private static function validate_settings( $settings ) {
        $result = array(
            'warnings' => array(),
        );

        if ( ! is_array( $settings ) ) {
            $result['warnings'][] = __( 'Settings should be an object.', 'form-runtime-engine' );
            return $result;
        }

        // Check for unknown settings.
        foreach ( array_keys( $settings ) as $prop ) {
            if ( ! in_array( $prop, self::$known_settings_properties, true ) ) {
                $result['warnings'][] = sprintf(
                    /* translators: %s: unknown property name */
                    __( 'Unknown settings property "%s".', 'form-runtime-engine' ),
                    $prop
                );
            }
        }

        return $result;
    }

    /**
     * Validate steps configuration.
     *
     * @param mixed $steps  The steps configuration.
     * @param array $fields The fields array.
     * @return array Validation result.
     */
    private static function validate_steps( $steps, $fields ) {
        $result = array(
            'valid'    => true,
            'errors'   => array(),
            'warnings' => array(),
        );

        if ( ! is_array( $steps ) ) {
            $result['valid']    = false;
            $result['errors'][] = __( 'Steps must be an array.', 'form-runtime-engine' );
            return $result;
        }

        $step_keys = array();

        foreach ( $steps as $index => $step ) {
            if ( ! is_array( $step ) ) {
                $result['valid']    = false;
                $result['errors'][] = sprintf(
                    /* translators: %d: step index */
                    __( 'Step at index %d must be an object.', 'form-runtime-engine' ),
                    $index
                );
                continue;
            }

            if ( empty( $step['key'] ) ) {
                $result['valid']    = false;
                $result['errors'][] = sprintf(
                    /* translators: %d: step index */
                    __( 'Step at index %d is missing required "key" property.', 'form-runtime-engine' ),
                    $index
                );
            } else {
                $step_keys[] = $step['key'];
            }
        }

        // Check for duplicate step keys.
        $step_duplicates = array_diff_assoc( $step_keys, array_unique( $step_keys ) );
        if ( ! empty( $step_duplicates ) ) {
            $result['valid']    = false;
            $result['errors'][] = sprintf(
                /* translators: %s: comma-separated list of duplicate step keys */
                __( 'Duplicate step keys found: %s', 'form-runtime-engine' ),
                implode( ', ', array_unique( $step_duplicates ) )
            );
        }

        // Warn about fields with invalid step references.
        foreach ( $fields as $field ) {
            if ( ! empty( $field['step'] ) && ! in_array( $field['step'], $step_keys, true ) ) {
                $result['warnings'][] = sprintf(
                    /* translators: 1: field key, 2: step key */
                    __( 'Field "%1$s" references unknown step "%2$s".', 'form-runtime-engine' ),
                    $field['key'] ?? 'unknown',
                    $field['step']
                );
            }
        }

        return $result;
    }

    /**
     * Get the list of valid field types.
     *
     * @return array Valid field type slugs.
     */
    public static function get_valid_field_types() {
        return apply_filters( 'fre_field_types', self::$valid_field_types );
    }
}
