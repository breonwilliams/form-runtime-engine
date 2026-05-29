<?php
/**
 * Plugin Name: Promptless Forms
 * Plugin URI: https://promptlesswp.com
 * Description: Lightweight forms with webhooks, multi-step support, and conditional logic. Inherits brand styling when Promptless WP is active.
 * Version: 1.8.0
 * Requires at least: 5.0
 * Requires PHP: 7.4
 * Author: Promptless WP
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: promptless-forms
 *
 * @package FormRuntimeEngine
 */

// Prevent direct access.
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Plugin version.
define( 'PForms_VERSION', '1.8.0' );

// Plugin directory path.
define( 'PForms_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );

// Plugin directory URL.
define( 'PForms_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Plugin basename.
define( 'PForms_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

// Database version.
define( 'PForms_DB_VERSION', '1.2.0' );

// Upload directory name.
define( 'PForms_UPLOAD_DIR', 'fre-uploads' );

/**
 * Autoloader for plugin classes.
 */
require_once PForms_PLUGIN_DIR . 'includes/class-fre-autoloader.php';

/**
 * Main plugin class.
 */
final class Promptless_Forms {

    /**
     * Single instance of the plugin.
     *
     * @var Promptless_Forms
     */
    private static $instance = null;

    /**
     * Form registry instance.
     *
     * @var PForms_Registry
     */
    public $registry;

    /**
     * Submission handler instance.
     *
     * @var PForms_Submission_Handler
     */
    public $submission_handler;

    /**
     * Get single instance of the plugin.
     *
     * @return Promptless_Forms
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

        // Run plugin-version upgrade check on every load. Cheap no-op when
        // versions match. Must fire before init() so capabilities and other
        // upgrade state are in place by the time components boot. Uses
        // priority 5 on plugins_loaded so it runs before our own init()
        // handler (priority 10).
        add_action( 'plugins_loaded', array( __CLASS__, 'run_version_upgrade_check' ), 5 );

        // Initialize plugin on plugins_loaded.
        add_action( 'plugins_loaded', array( $this, 'init' ) );

    }

    /**
     * Run the plugin version upgrade check.
     *
     * Separated from init() so it can fire at an earlier priority and so
     * tests can invoke it independently.
     */
    public static function run_version_upgrade_check() {
        if ( class_exists( 'PForms_Upgrader' ) ) {
            PForms_Upgrader::maybe_upgrade();
        }
    }

    /**
     * Plugin activation.
     */
    public function activate() {
        // Stamp version and grant default capabilities. Done first so that
        // any subsequent failures in migrations still leave the caps in place.
        if ( class_exists( 'PForms_Upgrader' ) ) {
            PForms_Upgrader::on_activation();
        }

        // Run database migrations.
        $migrator = new PForms_Migrator();
        $migrator->run_migrations();

        // Run Twilio database migrations.
        $twilio_migrator = new PForms_Twilio_Migrator();
        $twilio_migrator->run_migrations();

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
        wp_clear_scheduled_hook( 'pforms_process_webhook_queue' );
        wp_clear_scheduled_hook( 'pforms_prune_webhook_log' );
        wp_clear_scheduled_hook( 'pforms_retry_webhook' );

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
        $this->registry           = new PForms_Registry();
        $this->submission_handler = new PForms_Submission_Handler();

        // Fix #1: Initialize email retry queue hooks.
        PForms_Email_Notification::init_hooks();

        // Initialize webhook dispatcher.
        PForms_Webhook_Dispatcher::init();

        // Initialize design system integration.
        new PForms_Design_System();

        // Initialize shortcode.
        new PForms_Shortcode();

        // Initialize GDPR / privacy compliance hooks. Registers WordPress
        // personal-data exporter and eraser callbacks so site administrators
        // can fulfill data-subject access (DSAR) and right-to-erasure
        // requests against form-entry data via the WP core Tools menu.
        // Not admin-gated because WP's privacy data jobs may run via cron.
        ( new PForms_Privacy() )->init();

        // Initialize admin.
        if ( is_admin() ) {
            $this->init_admin();

            // Initialize GitHub auto-updater. The updater is intentionally
            // shipped only in the GitHub distribution build — the WordPress.org
            // build excludes includes/Updates/ entirely (WP.org guideline #8
            // prohibits plugins from overriding the core update mechanism).
            // The class_exists() gate lets the same bootstrap code run cleanly
            // in both distributions without a fatal in the WP.org build.
            if ( class_exists( 'PForms_GitHub_Updater' ) ) {
                new PForms_GitHub_Updater();
            }

            // Claude Cowork connector admin page (registers submenu + AJAX).
            ( new PForms_Connector_Admin() )->init();
        }

        // Claude Cowork connector REST API (registers routes on rest_api_init).
        // Loaded unconditionally — permission callbacks gate everything.
        ( new PForms_Connector_API() )->init();

        // Register AJAX handlers.
        $this->register_ajax_handlers();

        // Enqueue scripts and styles.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_assets' ) );

        // Register database-stored forms.
        PForms_Forms_Manager::register_db_forms();

        // Initialize Twilio module (only if credentials are configured).
        $this->init_twilio();

        /**
         * Fires after the Promptless Forms is fully initialized.
         *
         * @param Promptless_Forms $this The plugin instance.
         */
        do_action( 'pforms_init', $this );
    }

    /**
     * Initialize admin components.
     */
    private function init_admin() {
        new PForms_Admin();
    }

    /**
     * Initialize the Twilio integration module.
     *
     * Runs migrations if needed, registers virtual forms for active clients,
     * initializes the REST API handler, and loads the admin UI.
     */
    private function init_twilio() {
        // Always check for pending Twilio migrations (handles plugin updates).
        $twilio_migrator = new PForms_Twilio_Migrator();
        if ( $twilio_migrator->has_pending_migrations() ) {
            $twilio_migrator->run_migrations();
        }

        // Register virtual forms for all active Twilio clients.
        // This must happen after PForms_Forms_Manager::register_db_forms()
        // so virtual forms are available to the webhook dispatcher.
        PForms_Twilio_Admin::register_client_forms();

        // Initialize the REST API handler (processes incoming calls/SMS from Twilio).
        // Routes are always registered so Twilio can reach them; signature
        // validation inside each handler rejects requests if credentials
        // are missing or invalid.
        $twilio_handler = new PForms_Twilio_Handler();
        $twilio_handler->init();

        // Initialize admin UI.
        if ( is_admin() ) {
            new PForms_Twilio_Admin();
        }
    }

    /**
     * Register AJAX handlers.
     */
    private function register_ajax_handlers() {
        // Form submission handler.
        add_action( 'wp_ajax_pforms_submit_form', array( $this->submission_handler, 'handle_submission' ) );
        add_action( 'wp_ajax_nopriv_pforms_submit_form', array( $this->submission_handler, 'handle_submission' ) );

        // Nonce refresh handler.
        add_action( 'wp_ajax_pforms_refresh_nonce', array( $this->submission_handler, 'ajax_refresh_nonce' ) );
        add_action( 'wp_ajax_nopriv_pforms_refresh_nonce', array( $this->submission_handler, 'ajax_refresh_nonce' ) );
    }

    /**
     * Enqueue frontend scripts and styles.
     */
    public function enqueue_frontend_assets() {
        wp_register_style(
            'pforms-frontend',
            PForms_PLUGIN_URL . 'assets/css/frontend.css',
            array(),
            PForms_VERSION
        );

        wp_register_script(
            'pforms-frontend',
            PForms_PLUGIN_URL . 'assets/js/frontend.js',
            array(),
            PForms_VERSION,
            true
        );

        wp_localize_script(
            'pforms-frontend',
            'pformsAjax',
            array(
                'url'   => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( 'pforms_ajax_nonce' ),
            )
        );
    }

    /**
     * Create upload directory with security protections.
     */
    private function create_upload_directory() {
        $upload_dir = wp_upload_dir();
        $pforms_dir    = trailingslashit( $upload_dir['basedir'] ) . PForms_UPLOAD_DIR;

        // Create directory if it doesn't exist.
        if ( ! file_exists( $pforms_dir ) ) {
            wp_mkdir_p( $pforms_dir );
        }

        // Create .htaccess to prevent PHP execution.
        $htaccess_file = trailingslashit( $pforms_dir ) . '.htaccess';
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
        $index_file = trailingslashit( $pforms_dir ) . 'index.php';
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

        $migrator = new PForms_Migrator();
        $health   = $migrator->check_database_health();

        if ( $health !== true ) {
            // is-dismissible: condition is re-evaluated on every admin page load.
            // If tables remain missing, the notice will reappear on the next load
            // until the condition resolves (tables created via re-activation).
            add_action( 'admin_notices', function() use ( $health ) {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>' . esc_html__( 'Promptless Forms:', 'promptless-forms' ) . '</strong> ';
                echo esc_html__( 'Database tables are missing: ', 'promptless-forms' );
                echo esc_html( implode( ', ', $health ) );
                echo '</p></div>';
            } );
        }

        // Check for migration errors.
        $migration_error = get_option( 'pforms_migration_error' );
        if ( $migration_error ) {
            // is-dismissible: pforms_migration_error option is cleared when a
            // subsequent migration succeeds, at which point this notice stops
            // firing entirely. Until then it reappears on every admin page load.
            add_action( 'admin_notices', function() {
                echo '<div class="notice notice-error is-dismissible">';
                echo '<p><strong>' . esc_html__( 'Promptless Forms:', 'promptless-forms' ) . '</strong> ';
                echo esc_html__( 'Database migration failed. Please check error logs or contact support.', 'promptless-forms' );
                echo '</p></div>';
            } );
        }

        // Check for InnoDB support (required for transactions).
        $innodb_check = $migrator->check_innodb_support();
        if ( $innodb_check !== true ) {
            // is-dismissible: same self-healing pattern. If tables are migrated
            // to InnoDB the notice stops appearing; otherwise it reappears on
            // every admin page load until the user addresses the engine mismatch.
            add_action( 'admin_notices', function() use ( $innodb_check ) {
                echo '<div class="notice notice-warning is-dismissible">';
                echo '<p><strong>' . esc_html__( 'Promptless Forms:', 'promptless-forms' ) . '</strong> ';
                echo esc_html__( 'Tables not using InnoDB engine: ', 'promptless-forms' );
                echo esc_html( implode( ', ', $innodb_check ) );
                echo '<br>';
                echo esc_html__( 'InnoDB is required for transaction support. Form submissions may not work correctly.', 'promptless-forms' );
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
        return trailingslashit( $upload_dir['basedir'] ) . PForms_UPLOAD_DIR;
    }

    /**
     * Get upload directory URL.
     *
     * @return string
     */
    public function get_upload_url() {
        $upload_dir = wp_upload_dir();
        return trailingslashit( $upload_dir['baseurl'] ) . PForms_UPLOAD_DIR;
    }
}

// Backward-compatibility alias. The main class was renamed from
// `Form_Runtime_Engine` to `Promptless_Forms` in 1.6.5 to comply with
// WordPress.org plugin guideline 11 (avoid common-word prefixes like
// "form"). Third-party code, theme functions.php hooks, or other plugins
// that still reference `Form_Runtime_Engine::instance()` continue to
// work via this alias.
class_alias( 'Promptless_Forms', 'Form_Runtime_Engine' );

/**
 * Get the main plugin instance.
 *
 * @return Promptless_Forms
 */
function pforms() {
    return Promptless_Forms::instance();
}

/**
 * Register a form configuration.
 *
 * @param string $form_id Unique form identifier.
 * @param array  $config  Form configuration array.
 * @return bool True on success, false on failure.
 */
function pforms_register_form( $form_id, array $config ) {
    return pforms()->registry->register( $form_id, $config );
}

/**
 * Get a form configuration.
 *
 * @param string $form_id Form identifier.
 * @return array|null Form configuration or null if not found.
 */
function pforms_get_form( $form_id ) {
    return pforms()->registry->get( $form_id );
}

/**
 * Render a form.
 *
 * @param string $form_id Form identifier.
 * @param array  $args    Optional render arguments.
 * @return string Form HTML.
 */
function pforms_render_form( $form_id, array $args = array() ) {
    $renderer = new PForms_Renderer();
    return $renderer->render( $form_id, $args );
}

/**
 * Get all database-stored forms.
 *
 * @return array Array of form data keyed by form ID.
 */
function pforms_get_db_forms() {
    return PForms_Forms_Manager::get_forms();
}

/**
 * Get a single database-stored form.
 *
 * @param string $form_id Form identifier.
 * @return array|null Form data or null if not found.
 */
function pforms_get_db_form( $form_id ) {
    return PForms_Forms_Manager::get_form( $form_id );
}

/**
 * Save a form to the database.
 *
 * @param string $form_id     Form identifier.
 * @param string $title       Form title.
 * @param string $json_config JSON configuration string.
 * @return array|WP_Error Form data on success, WP_Error on failure.
 */
function pforms_save_db_form( $form_id, $title, $json_config ) {
    return PForms_Forms_Manager::save_form( $form_id, $title, $json_config );
}

/**
 * Delete a form from the database.
 *
 * @param string $form_id Form identifier.
 * @return bool True on success, false if form not found.
 */
function pforms_delete_db_form( $form_id ) {
    return PForms_Forms_Manager::delete_form( $form_id );
}

// Initialize the plugin.
pforms();
