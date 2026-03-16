<?php
/**
 * Autoloader for Form Runtime Engine classes.
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PSR-4 style autoloader for FRE classes.
 */
class FRE_Autoloader {

    /**
     * Class mappings for namespaced classes.
     *
     * @var array
     */
    private static $class_map = array(
        // Core classes.
        'FRE_Registry'            => 'Core/class-fre-registry.php',
        'FRE_Renderer'            => 'Core/class-fre-renderer.php',
        'FRE_Shortcode'           => 'Core/class-fre-shortcode.php',
        'FRE_Submission_Handler'  => 'Core/class-fre-submission-handler.php',
        'FRE_Validator'           => 'Core/class-fre-validator.php',
        'FRE_Sanitizer'           => 'Core/class-fre-sanitizer.php',

        // Database classes.
        'FRE_Migrator'            => 'Database/class-fre-migrator.php',
        'FRE_Entry'               => 'Database/class-fre-entry.php',
        'FRE_Entry_Query'         => 'Database/class-fre-entry-query.php',

        // Field classes.
        'FRE_Field_Type'          => 'Fields/interface-fre-field-type.php',
        'FRE_Field_Type_Abstract' => 'Fields/abstract-fre-field-type.php',
        'FRE_Field_Text'          => 'Fields/class-fre-field-text.php',
        'FRE_Field_Email'         => 'Fields/class-fre-field-email.php',
        'FRE_Field_Tel'           => 'Fields/class-fre-field-tel.php',
        'FRE_Field_Textarea'      => 'Fields/class-fre-field-textarea.php',
        'FRE_Field_Select'        => 'Fields/class-fre-field-select.php',
        'FRE_Field_Radio'         => 'Fields/class-fre-field-radio.php',
        'FRE_Field_Checkbox'      => 'Fields/class-fre-field-checkbox.php',
        'FRE_Field_File'          => 'Fields/class-fre-field-file.php',
        'FRE_Field_Hidden'        => 'Fields/class-fre-field-hidden.php',
        'FRE_Field_Message'       => 'Fields/class-fre-field-message.php',
        'FRE_Field_Section'       => 'Fields/class-fre-field-section.php',

        // Security classes.
        'FRE_Honeypot'            => 'Security/class-fre-honeypot.php',
        'FRE_Timing_Check'        => 'Security/class-fre-timing-check.php',
        'FRE_Rate_Limiter'        => 'Security/class-fre-rate-limiter.php',

        // Upload classes.
        'FRE_Upload_Handler'      => 'Uploads/class-fre-upload-handler.php',
        'FRE_Mime_Validator'      => 'Uploads/class-fre-mime-validator.php',

        // Notification classes.
        'FRE_Email_Notification'  => 'Notifications/class-fre-email-notification.php',

        // Admin classes.
        'FRE_Admin'               => 'Admin/class-fre-admin.php',
        'FRE_Entries_List_Table'  => 'Admin/class-fre-entries-list-table.php',
        'FRE_Entry_Detail'        => 'Admin/class-fre-entry-detail.php',
        'FRE_CSV_Exporter'        => 'Admin/class-fre-csv-exporter.php',
        'FRE_Forms_Manager'       => 'Admin/class-fre-forms-manager.php',
    );

    /**
     * Register the autoloader.
     */
    public static function register() {
        spl_autoload_register( array( __CLASS__, 'autoload' ) );
    }

    /**
     * Autoload a class.
     *
     * @param string $class_name Class name to load.
     */
    public static function autoload( $class_name ) {
        // Check if class is in our map.
        if ( isset( self::$class_map[ $class_name ] ) ) {
            $file = FRE_PLUGIN_DIR . 'includes/' . self::$class_map[ $class_name ];

            if ( file_exists( $file ) ) {
                require_once $file;
            }
        }
    }

    /**
     * Get field class name from field type.
     *
     * @param string $type Field type (e.g., 'text', 'email').
     * @return string|null Class name or null if not found.
     */
    public static function get_field_class( $type ) {
        $class_name = 'FRE_Field_' . ucfirst( $type );

        if ( isset( self::$class_map[ $class_name ] ) ) {
            return $class_name;
        }

        return null;
    }

    /**
     * Get all registered field types.
     *
     * @return array Array of field type slugs.
     */
    public static function get_field_types() {
        $types = array();

        foreach ( array_keys( self::$class_map ) as $class_name ) {
            if ( strpos( $class_name, 'FRE_Field_' ) === 0
                && $class_name !== 'FRE_Field_Type'
                && $class_name !== 'FRE_Field_Type_Abstract' ) {
                $types[] = strtolower( str_replace( 'FRE_Field_', '', $class_name ) );
            }
        }

        return $types;
    }
}

// Register the autoloader.
FRE_Autoloader::register();
