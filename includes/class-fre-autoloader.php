<?php
/**
 * Autoloader for Promptless Forms classes.
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
class PForms_Autoloader {

    /**
     * Class mappings for namespaced classes.
     *
     * @var array
     */
    private static $class_map = array(
        // Core classes.
        'PForms_Registry'            => 'Core/class-fre-registry.php',
        'PForms_Renderer'            => 'Core/class-fre-renderer.php',
        'PForms_Shortcode'           => 'Core/class-fre-shortcode.php',
        'PForms_Submission_Handler'  => 'Core/class-fre-submission-handler.php',
        'PForms_Validator'           => 'Core/class-fre-validator.php',
        'PForms_Sanitizer'           => 'Core/class-fre-sanitizer.php',
        'PForms_Logger'              => 'Core/class-fre-logger.php',
        'PForms_Capabilities'        => 'Core/class-fre-capabilities.php',
        'PForms_Upgrader'            => 'Core/class-fre-upgrader.php',
        'PForms_Forms_Repository'    => 'Core/class-fre-forms-repository.php',
        'PForms_Conditions'          => 'Core/class-fre-conditions.php',

        // Connector classes (Phase 2+ Cowork connector).
        'PForms_Connector_Settings'  => 'Connector/class-fre-connector-settings.php',
        'PForms_Connector_Auth'      => 'Connector/class-fre-connector-auth.php',
        'PForms_Connector_API'       => 'Connector/class-fre-connector-api.php',
        'PForms_Connector_Admin'     => 'Connector/class-fre-connector-admin.php',
        'PForms_Connector_Log'       => 'Connector/class-fre-connector-log.php',

        // Database classes.
        'PForms_Migrator'            => 'Database/class-fre-migrator.php',
        'PForms_Entry'               => 'Database/class-fre-entry.php',
        'PForms_Entry_Query'         => 'Database/class-fre-entry-query.php',
        'PForms_Webhook_Log'         => 'Database/class-fre-webhook-log.php',

        // Field classes.
        'PForms_Field_Type'          => 'Fields/interface-fre-field-type.php',
        'PForms_Field_Type_Abstract' => 'Fields/abstract-fre-field-type.php',
        'PForms_Field_Text'          => 'Fields/class-fre-field-text.php',
        'PForms_Field_Email'         => 'Fields/class-fre-field-email.php',
        'PForms_Field_Tel'           => 'Fields/class-fre-field-tel.php',
        'PForms_Field_Textarea'      => 'Fields/class-fre-field-textarea.php',
        'PForms_Field_Select'        => 'Fields/class-fre-field-select.php',
        'PForms_Field_Radio'         => 'Fields/class-fre-field-radio.php',
        'PForms_Field_Checkbox'      => 'Fields/class-fre-field-checkbox.php',
        'PForms_Field_File'          => 'Fields/class-fre-field-file.php',
        'PForms_Field_Hidden'        => 'Fields/class-fre-field-hidden.php',
        'PForms_Field_Message'       => 'Fields/class-fre-field-message.php',
        'PForms_Field_Section'       => 'Fields/class-fre-field-section.php',
        'PForms_Field_Date'          => 'Fields/class-fre-field-date.php',
        'PForms_Field_Address'       => 'Fields/class-fre-field-address.php',

        // Security classes.
        'PForms_Honeypot'               => 'Security/class-fre-honeypot.php',
        'PForms_Timing_Check'           => 'Security/class-fre-timing-check.php',
        'PForms_Rate_Limiter'           => 'Security/class-fre-rate-limiter.php',
        'PForms_CSS_Validator'          => 'Security/class-fre-css-validator.php',
        'PForms_JSON_Schema_Validator'  => 'Security/class-fre-json-schema-validator.php',
        'PForms_Webhook_Validator'      => 'Security/class-fre-webhook-validator.php',

        // Webhook classes.
        'PForms_Webhook_Dispatcher'     => 'Webhooks/class-fre-webhook-dispatcher.php',

        // Upload classes.
        'PForms_Upload_Handler'      => 'Uploads/class-fre-upload-handler.php',
        'PForms_Mime_Validator'      => 'Uploads/class-fre-mime-validator.php',

        // Notification classes.
        'PForms_Email_Notification'  => 'Notifications/class-fre-email-notification.php',

        // Admin classes.
        'PForms_Admin'               => 'Admin/class-fre-admin.php',
        'PForms_Entries_List_Table'  => 'Admin/class-fre-entries-list-table.php',
        'PForms_Entry_Detail'        => 'Admin/class-fre-entry-detail.php',
        'PForms_CSV_Exporter'        => 'Admin/class-fre-csv-exporter.php',
        'PForms_Forms_Manager'       => 'Admin/class-fre-forms-manager.php',

        // Integration classes.
        'PForms_Design_System'       => 'Integration/class-fre-design-system.php',

        // Updates classes.
        'PForms_GitHub_Updater'      => 'Updates/class-fre-github-updater.php',

        // Twilio classes.
        'PForms_Twilio_Migrator'     => 'Twilio/class-fre-twilio-migrator.php',
        'PForms_Twilio_Validator'    => 'Twilio/class-fre-twilio-validator.php',
        'PForms_Twilio_Client'       => 'Twilio/class-fre-twilio-client.php',
        'PForms_SMS_Sender'          => 'Twilio/class-fre-sms-sender.php',
        'PForms_Twilio_Handler'      => 'Twilio/class-fre-twilio-handler.php',
        'PForms_Twilio_Admin'        => 'Twilio/class-fre-twilio-admin.php',

        // Privacy compliance (GDPR / DSAR support via WordPress core hooks).
        'PForms_Privacy'             => 'Privacy/class-fre-privacy.php',
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
            $file = PForms_PLUGIN_DIR . 'includes/' . self::$class_map[ $class_name ];

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
        $class_name = 'PForms_Field_' . ucfirst( $type );

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
            if ( strpos( $class_name, 'PForms_Field_' ) === 0
                && $class_name !== 'PForms_Field_Type'
                && $class_name !== 'PForms_Field_Type_Abstract' ) {
                $types[] = strtolower( str_replace( 'PForms_Field_', '', $class_name ) );
            }
        }

        return $types;
    }
}

// Register the autoloader.
PForms_Autoloader::register();
