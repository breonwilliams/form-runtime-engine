<?php
/**
 * Plugin Name: Form Runtime Engine
 * Plugin URI: https://developer.developer.developer
 * Description: A lightweight WordPress form runtime engine that processes form submissions via configuration.
 * Version: 1.1.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Developer
 * Author URI: https://developer.developer.developer
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: form-runtime-engine
 * Domain Path: /languages
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version.
define( 'FRE_VERSION', '1.1.0' );

// Plugin directory path.
define( 'FRE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'FRE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'FRE_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Database version.
define( 'FRE_DB_VERSION', '1.2.0' );

// Upload directory name.
define( 'FRE_UPLOAD_DIR', 'fre-uploads' );

/**
 * Autoloader for plugin classes.
 */
require_once FRE_PLUGIN_DIR . 'includes/class-fre-autoloader.php';

/**
 * Main plugin class.
 */
final class Form_Runtime_Engine {

    /**
     * Single instance of the plugin.
     *
     * @var Form_Runtime_Engine
     */
    private static $instance = null;

    /**
     * Form registry instance.
     *
     * @var FRE_Registry
     */
    public $registry;

    /**
     * Submission handler instance.
     *
     * @var FRE_Submission_Handler
     */
    public $submission_handler;

    /**
     * Get single instance of the plugin.
     *
     * @return Form_Runtime_Engine
     */
    public static function instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->init_hooks();
    }

    /**
     * Prevent cloning.
     */
    private function __clone() {}

    /**
     * Prevent unserializing.
     */
    public function __wakeup() {
        throw new Exception( 'Cannot unserialize singleton' );
    }

    /**
     * Initialize hooks.
     */
    private function init_hooks() {
        // Register activation hook.
        register_activation_hook( __FILE__, array( $this, 'activate' ) );

        // Register deactivation hook.
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) );

        // Initialize plugin on plugins_loaded.
        add_action( 'plugins_loaded', array( $this, 'init' ) );

    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Run database migrations.
        $migrator = new FRE_Migrator();
        $migrator->run_migrations();

        // Create upload directory with protection.
        $this->create_upload_directory();

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation.
     */
    public function deactivate() {
        // Unschedule webhook cron events (recurring and single).
        wp_clear_scheduled_hook( 'fre_process_webhook_queue' );
        wp_clear_scheduled_hook( 'fre_prune_webhook_log' );
        wp_clear_scheduled_hook( 'fre_retry_webhook' );

        // Flush rewrite rules.
        flush_rewrite_rules();
    }

    /**
     * Initialize the plugin.
     */
    public function init() {
        // Check database health.
        $this->check_database_health();

        // Initialize components.
        $this->registry           = new FRE_Registry();
        $this->submission_handler = new FRE_Submission_Handler();

        // Fix #1: Initialize email retry queue hooks.
        FRE_Email_Notification::init_hooks();

        // Initialize webhook dispatcher.
        FRE_Webhook_Dispatcher::init();

        // Initialize design system integration.
        new FRE_Design_System();

        // Initialize shortcode.
        new FRE_Shortcode();

        // Initialize admin.
        if ( is_admin() ) {
            $this->init_admin();

            // Initialize GitHub updater for update checks.
            new FRE_GitHub_Updater();
        }

        // Register AJAX handlers.
        $this->register_ajax_handlers();

        // Enqueue scripts and styles.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Register database-stored forms.
        FRE_Forms_Manager::register_db_forms();

        /**
         * Fires after the Form Runtime Engine is fully initialized.
         *
         * @param Form_Runtime_Engine $this The plugin instance.
         */
        do_action( 'fre_init', $this );
    }

    /**
     * Initialize admin components.
     */
    private function init_admin() {
        new FRE_Admin();
    }

    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // Form submission handler.
        add_action( 'wp_ajax_fre_submit_form', array( $this->submission_handler, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_fre_submit_form', array( $this->submission_handler, 'handle_submission' ) );

        // Nonce refresh handler.
        add_action( 'wp_ajax_fre_refresh_nonce', array( $this->submission_handler, 'ajax_refresh_nonce' ) );
        add_action( 'wp_ajax_nopriv_fre_refresh_nonce', array( $this->submission_handler, 'ajax_refresh_nonce' ) );
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_frontend_assets() {
        wp_register_style(
            'fre-frontend',
            FRE_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            FRE_VERSION
        );

        wp_register_script(
            'fre-frontend',
            FRE_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            FRE_VERSION,
            true
        );

        wp_localize_script(
            'fre-frontend',
            'freAjax',
            array(
                'url'   => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'fre_ajax_nonce' ),
            )
        );
    }

    /**
     * Create upload directory with security protections.
     */
    private function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $fre_dir    = trailingslashit( $upload_dir['basedir'] ) . FRE_UPLOAD_DIR;

        // Create directory if it doesn't exist.
        if ( ! file_exists( $fre_dir ) ) {
            wp_mkdir_p( $fre_dir );
        }

        // Create .htaccess to prevent PHP execution.
        $htaccess_file = trailingslashit( $fre_dir ) . '.htaccess';
        if ( ! file_exists( $htaccess_file ) ) {
            $htaccess_content = "# Disable PHP execution\n";
            $htaccess_content .= "<FilesMatch \"\\.(?:php|phtml|php[0-9]|phar)$\">\n";
            $htaccess_content .= "    Order Allow,Deny\n";
            $htaccess_content .= "    Deny from all\n";
            $htaccess_content .= "</FilesMatch>\n\n";
            $htaccess_content .= "# Disable script execution\n";
            $htaccess_content .= "Options -ExecCGI\n";
            $htaccess_content .= "AddHandler cgi-script .php .phtml .php3 .php4 .php5 .php7 .phar\n";

            file_put_contents( $htaccess_file, $htaccess_content );
        }

        // Create index.php to prevent directory listing.
        $index_file = trailingslashit( $fre_dir ) . 'index.php';
        if ( ! file_exists( $index_file ) ) {
            file_put_contents( $index_file, '<?php // Silence is golden.' );
        }
    }

    /**
     * Check database health and show admin notice if needed.
     */
    private function check_database_health() {
        // Only check in admin.
        if ( ! is_admin() ) {
            return;
        }

        $migrator = new FRE_Migrator();
        $health   = $migrator->check_database_health();

        if ( $health !== true ) {
            add_action( 'admin_notices', function() use ( $health ) {
                echo '<div class="notice notice-error">';
                echo '<p><strong>' . esc_html__( 'Form Runtime Engine:', 'form-runtime-engine' ) . '</strong> ';
                echo esc_html__( 'Database tables are missing: ', 'form-runtime-engine' );
                echo esc_html( implode( ', ', $health ) );
                echo '</p></div>';
            } );
        }

        // Check for migration errors.
        $migration_error = get_option( 'fre_migration_error' );
        if ( $migration_error ) {
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error">';
                echo '<p><strong>' . esc_html__( 'Form Runtime Engine:', 'form-runtime-engine' ) . '</strong> ';
                echo esc_html__( 'Database migration failed. Please check error logs or contact support.', 'form-runtime-engine' );
                echo '</p></div>';
            } );
        }

        // Check for InnoDB support (required for transactions).
        $innodb_check = $migrator->check_innodb_support();
        if ( $innodb_check !== true ) {
            add_action( 'admin_notices', function() use ( $innodb_check ) {
                echo '<div class="notice notice-warning">';
                echo '<p><strong>' . esc_html__( 'Form Runtime Engine:', 'form-runtime-engine' ) . '</strong> ';
                echo esc_html__( 'Tables not using InnoDB engine: ', 'form-runtime-engine' );
                echo esc_html( implode( ', ', $innodb_check ) );
                echo '<br>';
                echo esc_html__( 'InnoDB is required for transaction support. Form submissions may not work correctly.', 'form-runtime-engine' );
                echo '</p></div>';
            } );
        }
    }

    /**
     * Get upload directory path.
     *
     * @return string
     */
    public function get_upload_dir() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['basedir'] ) . FRE_UPLOAD_DIR;
    }

    /**
     * Get upload directory URL.
     *
     * @return string
     */
    public function get_upload_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['baseurl'] ) . FRE_UPLOAD_DIR;
    }
}

/**
 * Get the main plugin instance.
 *
 * @return Form_Runtime_Engine
 */
function fre() {
    return Form_Runtime_Engine::instance();
}

/**
 * Register a form configuration.
 *
 * @param string $form_id Unique form identifier.
 * @param array  $config  Form configuration array.
 * @return bool True on success, false on failure.
 */
function fre_register_form( $form_id, array $config ) {
    return fre()->registry->register( $form_id, $config );
}

/**
 * Get a form configuration.
 *
 * @param string $form_id Form identifier.
 * @return array|null Form configuration or null if not found.
 */
function fre_get_form( $form_id ) {
    return fre()->registry->get( $form_id );
}

/**
 * Render a form.
 *
 * @param string $form_id Form identifier.
 * @param array  $args    Optional render arguments.
 * @return string Form HTML.
 */
function fre_render_form( $form_id, array $args = array() ) {
    $renderer = new FRE_Renderer();
    return $renderer->render( $form_id, $args );
}

/**
 * Get all database-stored forms.
 *
 * @return array Array of form data keyed by form ID.
 */
function fre_get_db_forms() {
    return FRE_Forms_Manager::get_forms();
}

/**
 * Get a single database-stored form.
 *
 * @param string $form_id Form identifier.
 * @return array|null Form data or null if not found.
 */
function fre_get_db_form( $form_id ) {
    return FRE_Forms_Manager::get_form( $form_id );
}

/**
 * Save a form to the database.
 *
 * @param string $form_id     Form identifier.
 * @param string $title       Form title.
 * @param string $json_config JSON configuration string.
 * @return array|WP_Error Form data on success, WP_Error on failure.
 */
function fre_save_db_form( $form_id, $title, $json_config ) {
    return FRE_Forms_Manager::save_form( $form_id, $title, $json_config );
}

/**
 * Delete a form from the database.
 *
 * @param string $form_id Form identifier.
 * @return bool True on success, false if form not found.
 */
function fre_delete_db_form( $form_id ) {
    return FRE_Forms_Manager::delete_form( $form_id );
}

// Initialize the plugin.
fre();
