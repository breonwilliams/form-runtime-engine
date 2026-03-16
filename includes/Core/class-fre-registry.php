<?php
/**
 * Form Registry for Form Runtime Engine.
 *
 * Stores and retrieves form configurations.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Form registry class.
 */
class FRE_Registry {

    /**
     * Registered forms.
     *
     * @var array
     */
    private $forms = array();

    /**
     * Cache group for form definitions.
     *
     * @var string
     */
    private $cache_group = 'fre_forms';

    /**
     * Default form settings.
     *
     * @var array
     */
    private $default_settings = array(
        'submit_button_text' => 'Submit',
        'success_message'    => 'Thank you for your submission.',
        'redirect_url'       => null,
        'notification'       => array(
            'enabled'   => true,
            'to'        => '{admin_email}',
            'subject'   => 'New Form Submission',
            'from_name' => '{site_name}',
            'from_email'=> '{admin_email}',
            'reply_to'  => '',
        ),
        'spam_protection'    => array(
            'honeypot'            => true,
            'timing_check'        => true,
            'min_submission_time' => 3,
            'rate_limit'          => array(
                'max'    => 5,
                'window' => 3600,
            ),
        ),
        'css_class'          => '',
        'store_entries'      => true,
    );

    /**
     * Default field settings.
     *
     * @var array
     */
    private $default_field = array(
        'key'         => '',
        'type'        => 'text',
        'label'       => '',
        'placeholder' => '',
        'required'    => false,
        'css_class'   => '',
        'default'     => '',
        'description' => '',
    );

    /**
     * Constructor.
     */
    public function __construct() {
        // Load forms from transient cache if available.
        $cached = wp_cache_get( 'all_forms', $this->cache_group );
        if ( $cached !== false ) {
            $this->forms = $cached;
        }
    }

    /**
     * Register a form.
     *
     * @param string $form_id Unique form identifier.
     * @param array  $config  Form configuration.
     * @return bool True on success, false on failure.
     */
    public function register( $form_id, array $config ) {
        // Sanitize form ID.
        $form_id = sanitize_key( $form_id );

        if ( empty( $form_id ) ) {
            return false;
        }

        // Validate configuration.
        $validated = $this->validate_config( $config );

        if ( is_wp_error( $validated ) ) {
            error_log( 'FRE: Form registration failed - ' . $validated->get_error_message() );
            return false;
        }

        // Merge with defaults.
        $config = $this->merge_defaults( $config );

        // Store form.
        $this->forms[ $form_id ] = $config;

        // Update cache.
        wp_cache_set( 'all_forms', $this->forms, $this->cache_group );
        wp_cache_set( $form_id, $config, $this->cache_group );

        /**
         * Fires after a form is registered.
         *
         * @param string $form_id Form ID.
         * @param array  $config  Form configuration.
         */
        do_action( 'fre_form_registered', $form_id, $config );

        return true;
    }

    /**
     * Get a form configuration.
     *
     * @param string $form_id Form identifier.
     * @return array|null Form configuration or null if not found.
     */
    public function get( $form_id ) {
        $form_id = sanitize_key( $form_id );

        // Check cache first.
        $cached = wp_cache_get( $form_id, $this->cache_group );
        if ( $cached !== false ) {
            return $cached;
        }

        // Check local array.
        if ( isset( $this->forms[ $form_id ] ) ) {
            wp_cache_set( $form_id, $this->forms[ $form_id ], $this->cache_group );
            return $this->forms[ $form_id ];
        }

        return null;
    }

    /**
     * Get all registered forms.
     *
     * @return array Array of form configurations keyed by form ID.
     */
    public function get_all() {
        return $this->forms;
    }

    /**
     * Check if a form exists.
     *
     * @param string $form_id Form identifier.
     * @return bool True if form exists.
     */
    public function exists( $form_id ) {
        $form_id = sanitize_key( $form_id );
        return isset( $this->forms[ $form_id ] );
    }

    /**
     * Unregister a form.
     *
     * @param string $form_id Form identifier.
     * @return bool True on success.
     */
    public function unregister( $form_id ) {
        $form_id = sanitize_key( $form_id );

        if ( isset( $this->forms[ $form_id ] ) ) {
            unset( $this->forms[ $form_id ] );
            wp_cache_delete( $form_id, $this->cache_group );
            wp_cache_set( 'all_forms', $this->forms, $this->cache_group );
            return true;
        }

        return false;
    }

    /**
     * Validate form configuration.
     *
     * @param array $config Form configuration.
     * @return bool|WP_Error True if valid, WP_Error otherwise.
     */
    private function validate_config( array $config ) {
        // Must have fields array.
        if ( empty( $config['fields'] ) || ! is_array( $config['fields'] ) ) {
            return new WP_Error(
                'invalid_fields',
                __( 'Form must have a fields array.', 'form-runtime-engine' )
            );
        }

        // Validate each field.
        $keys = array();
        foreach ( $config['fields'] as $index => $field ) {
            // Field must have a key.
            if ( empty( $field['key'] ) ) {
                return new WP_Error(
                    'missing_field_key',
                    sprintf(
                        __( 'Field at index %d must have a key.', 'form-runtime-engine' ),
                        $index
                    )
                );
            }

            // Keys must be unique.
            if ( in_array( $field['key'], $keys, true ) ) {
                return new WP_Error(
                    'duplicate_field_key',
                    sprintf(
                        __( 'Duplicate field key: %s', 'form-runtime-engine' ),
                        $field['key']
                    )
                );
            }
            $keys[] = $field['key'];

            // Field must have a valid type.
            $type = isset( $field['type'] ) ? $field['type'] : 'text';
            if ( ! $this->is_valid_field_type( $type ) ) {
                return new WP_Error(
                    'invalid_field_type',
                    sprintf(
                        __( 'Invalid field type: %s', 'form-runtime-engine' ),
                        $type
                    )
                );
            }
        }

        return true;
    }

    /**
     * Check if a field type is valid.
     *
     * @param string $type Field type.
     * @return bool True if valid.
     */
    private function is_valid_field_type( $type ) {
        $valid_types = array(
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
         * Filter the list of valid field types.
         *
         * @param array $valid_types Array of valid field type slugs.
         */
        $valid_types = apply_filters( 'fre_field_types', $valid_types );

        return in_array( $type, $valid_types, true );
    }

    /**
     * Merge configuration with defaults.
     *
     * @param array $config Form configuration.
     * @return array Merged configuration.
     */
    private function merge_defaults( array $config ) {
        // Merge settings.
        $config['settings'] = isset( $config['settings'] )
            ? wp_parse_args( $config['settings'], $this->default_settings )
            : $this->default_settings;

        // Merge nested settings.
        if ( isset( $config['settings']['notification'] ) ) {
            $config['settings']['notification'] = wp_parse_args(
                $config['settings']['notification'],
                $this->default_settings['notification']
            );
        }

        if ( isset( $config['settings']['spam_protection'] ) ) {
            $config['settings']['spam_protection'] = wp_parse_args(
                $config['settings']['spam_protection'],
                $this->default_settings['spam_protection']
            );

            if ( isset( $config['settings']['spam_protection']['rate_limit'] ) ) {
                $config['settings']['spam_protection']['rate_limit'] = wp_parse_args(
                    $config['settings']['spam_protection']['rate_limit'],
                    $this->default_settings['spam_protection']['rate_limit']
                );
            }
        }

        // Merge each field with defaults.
        foreach ( $config['fields'] as $index => $field ) {
            $config['fields'][ $index ] = wp_parse_args( $field, $this->default_field );
        }

        // Ensure title exists.
        if ( empty( $config['title'] ) ) {
            $config['title'] = '';
        }

        // Add version for form update detection.
        if ( empty( $config['version'] ) ) {
            $config['version'] = '1.0.0';
        }

        return $config;
    }

    /**
     * Get a specific field from a form.
     *
     * @param string $form_id  Form identifier.
     * @param string $field_key Field key.
     * @return array|null Field configuration or null if not found.
     */
    public function get_field( $form_id, $field_key ) {
        $form = $this->get( $form_id );

        if ( ! $form ) {
            return null;
        }

        foreach ( $form['fields'] as $field ) {
            if ( $field['key'] === $field_key ) {
                return $field;
            }
        }

        return null;
    }

    /**
     * Get all fields for a form.
     *
     * @param string $form_id Form identifier.
     * @return array Array of field configurations.
     */
    public function get_fields( $form_id ) {
        $form = $this->get( $form_id );

        if ( ! $form ) {
            return array();
        }

        return $form['fields'];
    }

    /**
     * Get form settings.
     *
     * @param string $form_id Form identifier.
     * @return array Form settings or default settings if form not found.
     */
    public function get_settings( $form_id ) {
        $form = $this->get( $form_id );

        if ( ! $form ) {
            return $this->default_settings;
        }

        return $form['settings'];
    }

    /**
     * Clear all cached forms.
     */
    public function clear_cache() {
        foreach ( array_keys( $this->forms ) as $form_id ) {
            wp_cache_delete( $form_id, $this->cache_group );
        }
        wp_cache_delete( 'all_forms', $this->cache_group );
    }
}
