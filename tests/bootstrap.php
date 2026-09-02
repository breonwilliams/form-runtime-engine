<?php
/**
 * PHPUnit Bootstrap File for Form Runtime Engine Tests.
 *
 * This file sets up the testing environment. It supports two modes:
 * 1. Unit tests: Uses Brain\Monkey to mock WordPress functions
 * 2. Integration tests: Uses the WordPress test framework
 *
 * @package FormRuntimeEngine\Tests
 */

// Define test mode (only if not already defined).
if ( ! defined( 'FRE_TESTING' ) ) {
    define( 'FRE_TESTING', true );
}

// Get plugin root directory.
if ( ! defined( 'FRE_TEST_PLUGIN_DIR' ) ) {
    define( 'FRE_TEST_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
}

// Load Composer autoloader.
$composer_autoload = FRE_TEST_PLUGIN_DIR . 'vendor/autoload.php';
if ( ! file_exists( $composer_autoload ) ) {
    echo "Error: Run 'composer install' before running tests.\n";
    exit( 1 );
}
require_once $composer_autoload;

// Determine test mode based on testsuite.
$test_suite = getenv( 'TEST_SUITE' ) ?: 'unit';

if ( $test_suite === 'integration' ) {
    // Integration tests require WordPress test framework.
    bootstrap_integration_tests();
} else {
    // Unit tests use Brain\Monkey for mocking.
    bootstrap_unit_tests();
}

/**
 * Bootstrap unit tests with Brain\Monkey.
 */
function bootstrap_unit_tests() {
    // Load Yoast PHPUnit Polyfills.
    require_once FRE_TEST_PLUGIN_DIR . 'vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

    // Define WordPress constants used by the plugin.
    if ( ! defined( 'ABSPATH' ) ) {
        define( 'ABSPATH', '/tmp/wordpress/' );
    }
    if ( ! defined( 'PForms_VERSION' ) ) {
        define( 'PForms_VERSION', '1.0.0' );
    }
    if ( ! defined( 'PForms_PLUGIN_DIR' ) ) {
        define( 'PForms_PLUGIN_DIR', FRE_TEST_PLUGIN_DIR );
    }
    if ( ! defined( 'PForms_PLUGIN_URL' ) ) {
        define( 'PForms_PLUGIN_URL', 'http://example.com/wp-content/plugins/form-runtime-engine/' );
    }
    if ( ! defined( 'PForms_DB_VERSION' ) ) {
        define( 'PForms_DB_VERSION', '1.1.0' );
    }
    if ( ! defined( 'PForms_UPLOAD_DIR' ) ) {
        define( 'PForms_UPLOAD_DIR', 'fre-uploads' );
    }

    // Load the autoloader.
    require_once FRE_TEST_PLUGIN_DIR . 'includes/class-fre-autoloader.php';
}

/**
 * Bootstrap integration tests with WordPress test framework.
 */
function bootstrap_integration_tests() {
    // Try to find WordPress test library.
    $wp_tests_dir = getenv( 'WP_TESTS_DIR' );

    if ( ! $wp_tests_dir ) {
        // Try common locations.
        $possible_paths = array(
            '/tmp/wordpress-tests-lib',
            dirname( dirname( dirname( dirname( dirname( FRE_TEST_PLUGIN_DIR ) ) ) ) ) . '/tests/phpunit',
            getenv( 'HOME' ) . '/.wp-tests/wordpress-tests-lib',
        );

        foreach ( $possible_paths as $path ) {
            if ( file_exists( $path . '/includes/functions.php' ) ) {
                $wp_tests_dir = $path;
                break;
            }
        }
    }

    if ( ! $wp_tests_dir ) {
        echo "Error: WordPress test framework not found.\n";
        echo "Set WP_TESTS_DIR environment variable or install WordPress test library.\n";
        echo "\nFor unit tests (which don't require WordPress), run:\n";
        echo "  TEST_SUITE=unit ./vendor/bin/phpunit --testsuite Unit\n";
        exit( 1 );
    }

    // Give access to tests_add_filter() function.
    require_once $wp_tests_dir . '/includes/functions.php';

    /**
     * Manually load the plugin being tested.
     */
    function _manually_load_plugin() {
        require FRE_TEST_PLUGIN_DIR . 'form-runtime-engine.php';
    }
    tests_add_filter( 'muplugins_loaded', '_manually_load_plugin' );

    // Start up the WP testing environment.
    require $wp_tests_dir . '/includes/bootstrap.php';
}
