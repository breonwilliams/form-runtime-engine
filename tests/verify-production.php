<?php
/**
 * Production Verification Tests for Form Runtime Engine.
 *
 * This script bootstraps WordPress and runs verification tests against
 * the actual plugin installation. Run from command line:
 *
 *   php tests/verify-production.php
 *
 * @package FormRuntimeEngine\Tests
 */

// Prevent web access.
if ( php_sapi_name() !== 'cli' ) {
    die( 'This script must be run from the command line.' );
}

echo "\n";
echo "==============================================\n";
echo "  Form Runtime Engine - Production Verification\n";
echo "==============================================\n\n";

// Track results.
$passed = 0;
$failed = 0;
$warnings = 0;

function test_pass( $name ) {
    global $passed;
    $passed++;
    echo "  ✅ PASS: {$name}\n";
}

function test_fail( $name, $reason = '' ) {
    global $failed;
    $failed++;
    $msg = "  ❌ FAIL: {$name}";
    if ( $reason ) {
        $msg .= " - {$reason}";
    }
    echo "{$msg}\n";
}

function test_warn( $name, $reason = '' ) {
    global $warnings;
    $warnings++;
    $msg = "  ⚠️  WARN: {$name}";
    if ( $reason ) {
        $msg .= " - {$reason}";
    }
    echo "{$msg}\n";
}

function test_section( $name ) {
    echo "\n--- {$name} ---\n";
}

// Bootstrap WordPress.
echo "Loading WordPress...\n";

// For Local by Flywheel: Use socket for database connection.
$socket_path = '/Users/breonwilliams/Library/Application Support/Local/run/jkm3tqhoPn/mysql/mysqld.sock';
if ( file_exists( $socket_path ) ) {
    // Set default socket before WordPress loads.
    ini_set( 'mysqli.default_socket', $socket_path );
    ini_set( 'pdo_mysql.default_socket', $socket_path );
    putenv( 'MYSQL_UNIX_PORT=' . $socket_path );
}

$wp_load = dirname( dirname( dirname( dirname( dirname( __FILE__ ) ) ) ) ) . '/wp-load.php';

if ( ! file_exists( $wp_load ) ) {
    die( "Error: Could not find wp-load.php at {$wp_load}\n" );
}

require_once $wp_load;

echo "WordPress loaded: " . get_bloginfo( 'version' ) . "\n";
echo "Site URL: " . home_url() . "\n";

// ============================================
// Test 1: Plugin Activation
// ============================================
test_section( '1. Plugin Activation' );

if ( defined( 'PForms_VERSION' ) ) {
    test_pass( "Plugin is active (v" . PForms_VERSION . ")" );
} else {
    test_fail( "Plugin is not active" );
}

if ( function_exists( 'fre' ) ) {
    test_pass( "Main plugin function exists" );
} else {
    test_fail( "Main plugin function missing" );
}

if ( function_exists( 'pforms_register_form' ) ) {
    test_pass( "Form registration function exists" );
} else {
    test_fail( "Form registration function missing" );
}

// ============================================
// Test 2: Database Tables
// ============================================
test_section( '2. Database Tables' );

global $wpdb;

$required_tables = array(
    'fre_entries'      => 'Entries table',
    'fre_entry_meta'   => 'Entry meta table',
    'fre_entry_files'  => 'Entry files table',
    'fre_webhook_log'  => 'Webhook log table',
);

foreach ( $required_tables as $table => $name ) {
    $full_table = $wpdb->prefix . $table;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$full_table}'" );

    if ( $exists ) {
        test_pass( "{$name} exists ({$full_table})" );

        // Check engine is InnoDB.
        $engine = $wpdb->get_var( "SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '{$full_table}'" );
        if ( $engine === 'InnoDB' ) {
            test_pass( "{$name} uses InnoDB engine" );
        } else {
            test_warn( "{$name} uses {$engine} instead of InnoDB" );
        }
    } else {
        test_fail( "{$name} missing" );
    }
}

// Check Twilio tables (optional).
$twilio_tables = array(
    'fre_twilio_clients'  => 'Twilio clients table',
    'fre_twilio_messages' => 'Twilio messages table',
);

foreach ( $twilio_tables as $table => $name ) {
    $full_table = $wpdb->prefix . $table;
    $exists = $wpdb->get_var( "SHOW TABLES LIKE '{$full_table}'" );

    if ( $exists ) {
        test_pass( "{$name} exists (optional)" );
    } else {
        test_warn( "{$name} not created (Twilio feature may not be initialized)" );
    }
}

// ============================================
// Test 3: Core Classes
// ============================================
test_section( '3. Core Classes' );

$required_classes = array(
    'PForms_Registry'           => 'Form registry',
    'PForms_Renderer'           => 'Form renderer',
    'PForms_Submission_Handler' => 'Submission handler',
    'PForms_Validator'          => 'Field validator',
    'PForms_Sanitizer'          => 'Input sanitizer',
    'PForms_Entry'              => 'Entry database handler',
    'PForms_Upload_Handler'     => 'File upload handler',
    'PForms_Mime_Validator'     => 'MIME type validator',
    'PForms_Rate_Limiter'       => 'Rate limiter',
    'PForms_Honeypot'           => 'Honeypot spam protection',
    'PForms_Email_Notification' => 'Email notifications',
    'PForms_Webhook_Dispatcher' => 'Webhook dispatcher',
    'PForms_Webhook_Validator'  => 'Webhook URL validator',
);

foreach ( $required_classes as $class => $name ) {
    if ( class_exists( $class ) ) {
        test_pass( "{$name} ({$class})" );
    } else {
        test_fail( "{$name} class missing ({$class})" );
    }
}

// ============================================
// Test 4: Form Registration
// ============================================
test_section( '4. Form Registration' );

// Register a test form.
$test_config = array(
    'title'  => 'Verification Test Form',
    'fields' => array(
        array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
        array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
        array( 'key' => 'message', 'type' => 'textarea', 'label' => 'Message' ),
    ),
    'settings' => array(
        'submit_button_text' => 'Submit',
        'success_message'    => 'Thank you!',
    ),
);

pforms_register_form( 'verification-test', $test_config );

$registered = pforms_get_form( 'verification-test' );

if ( $registered ) {
    test_pass( "Form registration works" );

    if ( $registered['title'] === 'Verification Test Form' ) {
        test_pass( "Form config preserved correctly" );
    } else {
        test_fail( "Form config corrupted" );
    }

    if ( count( $registered['fields'] ) === 3 ) {
        test_pass( "Form fields registered (3 fields)" );
    } else {
        test_fail( "Form fields count mismatch" );
    }
} else {
    test_fail( "Form registration failed" );
}

// ============================================
// Test 5: Form Rendering
// ============================================
test_section( '5. Form Rendering' );

$html = pforms_render_form( 'verification-test' );

if ( ! empty( $html ) ) {
    test_pass( "Form renders HTML" );

    if ( strpos( $html, 'fre-form' ) !== false ) {
        test_pass( "Form has correct CSS class" );
    } else {
        test_fail( "Form missing CSS class" );
    }

    if ( strpos( $html, 'name="fre_field_name"' ) !== false ) {
        test_pass( "Form contains name field" );
    } else {
        test_fail( "Form missing name field" );
    }

    if ( strpos( $html, '_wpnonce' ) !== false ) {
        test_pass( "Form includes nonce field" );
    } else {
        test_fail( "Form missing nonce field" );
    }

    // Check for timing protection (uses token-based approach for security).
    if ( strpos( $html, '_fre_timing_token' ) !== false ) {
        test_pass( "Form includes timing check field" );
    } else {
        test_fail( "Form missing timing check field" );
    }
} else {
    test_fail( "Form rendering returned empty" );
}

// ============================================
// Test 6: Validation
// ============================================
test_section( '6. Field Validation' );

$validator = new PForms_Validator();

// Test required field validation.
$invalid_data = array(
    'fre_field_name'    => '',
    'fre_field_email'   => 'test@example.com',
    'fre_field_message' => 'Test',
);

$result = $validator->validate( $registered, $invalid_data );

if ( is_wp_error( $result ) ) {
    test_pass( "Required field validation works" );

    $error_data = $result->get_error_data();
    if ( isset( $error_data['field_errors']['name'] ) ) {
        test_pass( "Identifies missing required field" );
    } else {
        test_fail( "Doesn't identify missing field" );
    }
} else {
    test_fail( "Required field validation broken" );
}

// Test email validation.
$invalid_email = array(
    'fre_field_name'    => 'Test User',
    'fre_field_email'   => 'not-an-email',
    'fre_field_message' => 'Test',
);

$result = $validator->validate( $registered, $invalid_email );

if ( is_wp_error( $result ) ) {
    $error_data = $result->get_error_data();
    if ( isset( $error_data['field_errors']['email'] ) ) {
        test_pass( "Email validation works" );
    } else {
        test_fail( "Email validation not detecting invalid email" );
    }
} else {
    test_fail( "Email validation broken" );
}

// Test valid data passes.
$valid_data = array(
    'fre_field_name'    => 'Test User',
    'fre_field_email'   => 'test@example.com',
    'fre_field_message' => 'Hello world',
);

$result = $validator->validate( $registered, $valid_data );

if ( $result === true ) {
    test_pass( "Valid data passes validation" );
} else {
    test_fail( "Valid data fails validation" );
}

// ============================================
// Test 7: Sanitization
// ============================================
test_section( '7. Input Sanitization' );

$sanitizer = new PForms_Sanitizer();

$dirty_data = array(
    'fre_field_name'    => '  <script>alert("xss")</script>John Doe  ',
    'fre_field_email'   => 'TEST@EXAMPLE.COM',
    'fre_field_message' => '<p>Hello</p><script>evil()</script>',
);

$clean = $sanitizer->sanitize( $registered, $dirty_data );

if ( strpos( $clean['name'], '<script>' ) === false ) {
    test_pass( "Script tags removed from text" );
} else {
    test_fail( "Script tags not sanitized" );
}

if ( trim( $clean['name'] ) === $clean['name'] ) {
    test_pass( "Whitespace trimmed" );
} else {
    test_fail( "Whitespace not trimmed" );
}

// ============================================
// Test 8: Rate Limiter
// ============================================
test_section( '8. Rate Limiter' );

$rate_limiter = new PForms_Rate_Limiter();

// Reset any existing rate limit for test.
$rate_limiter->reset( 'rate-limit-test' );

$settings = array( 'max' => 3, 'window' => 60 );

// First 3 should pass.
$all_passed = true;
for ( $i = 0; $i < 3; $i++ ) {
    $result = $rate_limiter->validate( 'rate-limit-test', $settings );
    if ( $result !== true ) {
        $all_passed = false;
        break;
    }
}

if ( $all_passed ) {
    test_pass( "Rate limiter allows submissions under limit" );
} else {
    test_fail( "Rate limiter blocking too early" );
}

// 4th should fail.
$result = $rate_limiter->validate( 'rate-limit-test', $settings );
if ( is_wp_error( $result ) ) {
    test_pass( "Rate limiter blocks after limit reached" );
} else {
    test_fail( "Rate limiter not enforcing limit" );
}

// Clean up.
$rate_limiter->reset( 'rate-limit-test' );

// ============================================
// Test 9: Webhook Validator (SSRF Protection)
// ============================================
test_section( '9. Webhook SSRF Protection' );

// Test blocked URLs.
$blocked_urls = array(
    'http://localhost/webhook'        => 'localhost',
    'http://127.0.0.1/webhook'        => 'loopback IPv4',
    'http://192.168.1.1/webhook'      => 'private IP',
    'http://10.0.0.1/webhook'         => 'private IP (10.x)',
    'http://[::1]/webhook'            => 'IPv6 loopback',
);

foreach ( $blocked_urls as $url => $type ) {
    $result = PForms_Webhook_Validator::validate( $url );
    if ( is_wp_error( $result ) ) {
        test_pass( "Blocks {$type}" );
    } else {
        test_fail( "Doesn't block {$type}" );
    }
}

// Test allowed URL.
$result = PForms_Webhook_Validator::validate( 'https://hooks.zapier.com/hooks/catch/123' );
if ( $result === true ) {
    test_pass( "Allows valid external HTTPS URL" );
} else {
    test_fail( "Blocks valid external URL" );
}

// ============================================
// Test 10: File Upload Validation
// ============================================
test_section( '10. File Upload Security' );

$upload_handler = new PForms_Upload_Handler();

// Test blocked extensions.
$blocked_extensions = array( 'php', 'phtml', 'php5', 'exe', 'sh', 'htaccess' );

foreach ( $blocked_extensions as $ext ) {
    // Use reflection to test the private method.
    $reflection = new ReflectionClass( $upload_handler );
    $method = $reflection->getMethod( 'validate_and_sanitize_filename' );
    $method->setAccessible( true );

    $result = $method->invoke( $upload_handler, "test.{$ext}" );

    if ( is_wp_error( $result ) ) {
        test_pass( "Blocks .{$ext} extension" );
    } else {
        test_fail( "Doesn't block .{$ext} extension" );
    }
}

// Test double extension.
$result = $method->invoke( $upload_handler, 'test.php.jpg' );
if ( is_wp_error( $result ) ) {
    test_pass( "Blocks double extension (test.php.jpg)" );
} else {
    test_fail( "Doesn't block double extension" );
}

// ============================================
// Test 11: REST API Endpoints
// ============================================
test_section( '11. REST API Endpoints' );

// Check if REST routes are registered.
$server = rest_get_server();
$routes = $server->get_routes();

$expected_routes = array(
    '/fre/v1/submit'  => 'Form submission endpoint',
);

foreach ( $expected_routes as $route => $name ) {
    if ( isset( $routes[ $route ] ) ) {
        test_pass( "{$name} registered" );
    } else {
        test_warn( "{$name} not found (may use admin-ajax.php instead)" );
    }
}

// Check Twilio endpoints.
$twilio_routes = array(
    '/fre-twilio/v1/incoming-call' => 'Twilio incoming call',
    '/fre-twilio/v1/call-status'   => 'Twilio call status',
    '/fre-twilio/v1/incoming-sms'  => 'Twilio incoming SMS',
);

foreach ( $twilio_routes as $route => $name ) {
    if ( isset( $routes[ $route ] ) ) {
        test_pass( "{$name} endpoint registered" );
    } else {
        test_warn( "{$name} not registered (Twilio may not be initialized)" );
    }
}

// ============================================
// Test 12: Entry Storage
// ============================================
test_section( '12. Entry Storage (Database)' );

$entry_repo = new PForms_Entry();

// Create a test entry.
$test_data = array(
    'name'    => 'Verification Test',
    'email'   => 'verify@test.com',
    'message' => 'This is a verification test entry.',
);

try {
    $entry_id = $entry_repo->create( 'verification-test', $test_data );

    if ( is_numeric( $entry_id ) && $entry_id > 0 ) {
        test_pass( "Entry created (ID: {$entry_id})" );

        // Retrieve entry.
        $entry = $entry_repo->get( $entry_id );

        if ( $entry ) {
            test_pass( "Entry retrieved successfully" );

            if ( $entry['fields']['name'] === 'Verification Test' ) {
                test_pass( "Entry data stored correctly" );
            } else {
                test_fail( "Entry data corrupted" );
            }
        } else {
            test_fail( "Entry retrieval failed" );
        }

        // Test duplicate detection.
        // Note: is_duplicate() both CHECKS and STORES the duplicate marker.
        // The submission handler calls is_duplicate() BEFORE create().
        // First call stores marker and returns false (not a duplicate).
        // Second call finds marker and returns true (is a duplicate).
        $dup_test_data = array(
            'name'    => 'Dup Test ' . time(),
            'email'   => 'duptest@example.com',
            'message' => 'Testing duplicate detection.',
        );

        // First call - should return false (no prior marker) but stores the marker.
        $first_check = $entry_repo->is_duplicate( 'verification-test', $dup_test_data );

        // Second call with same data - should return true (marker exists within 60s window).
        $second_check = $entry_repo->is_duplicate( 'verification-test', $dup_test_data );

        if ( $first_check === false && $second_check === true ) {
            test_pass( "Duplicate detection works (first=false, second=true)" );
        } else {
            test_fail( "Duplicate detection not working (first={$first_check}, second={$second_check})" );
        }

        // Clean up - delete test entry.
        $deleted = $entry_repo->delete( $entry_id );
        if ( $deleted ) {
            test_pass( "Entry deletion works" );
        } else {
            test_fail( "Entry deletion failed" );
        }
    } else {
        test_fail( "Entry creation failed" );
    }
} catch ( Exception $e ) {
    test_fail( "Entry storage error: " . $e->getMessage() );
}

// ============================================
// Summary
// ============================================
echo "\n";
echo "==============================================\n";
echo "  VERIFICATION SUMMARY\n";
echo "==============================================\n";
echo "\n";
echo "  ✅ Passed:   {$passed}\n";
echo "  ❌ Failed:   {$failed}\n";
echo "  ⚠️  Warnings: {$warnings}\n";
echo "\n";

if ( $failed === 0 ) {
    echo "  🎉 All critical tests passed!\n";
    echo "  The plugin is production-ready.\n";
    $exit_code = 0;
} else {
    echo "  ⚠️  Some tests failed. Review the output above.\n";
    $exit_code = 1;
}

echo "\n";
exit( $exit_code );
