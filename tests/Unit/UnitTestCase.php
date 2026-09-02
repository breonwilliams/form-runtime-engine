<?php
/**
 * Base Unit Test Case.
 *
 * Uses Brain\Monkey for mocking WordPress functions.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base unit test case with Brain\Monkey setup.
 */
abstract class UnitTestCase extends TestCase {

    /**
     * Set up Brain\Monkey before each test.
     */
    protected function set_up() {
        parent::set_up();
        Monkey\setUp();

        // Set up common WordPress function mocks.
        $this->setup_common_mocks();
    }

    /**
     * Tear down Brain\Monkey after each test.
     */
    protected function tear_down() {
        Monkey\tearDown();
        parent::tear_down();
    }

    /**
     * Setup common WordPress function mocks.
     */
    protected function setup_common_mocks() {
        // Sanitization functions.
        Functions\when( 'sanitize_key' )->alias( function( $key ) {
            return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
        });

        Functions\when( 'sanitize_text_field' )->alias( function( $str ) {
            return trim( strip_tags( $str ) );
        });

        // sanitize_textarea_field — like sanitize_text_field but preserves
        // newlines. WP's implementation does more (collapses runs of
        // whitespace within lines, strips control chars, normalizes
        // unicode), but for unit-test scope strip_tags + trim is sufficient.
        Functions\when( 'sanitize_textarea_field' )->alias( function( $str ) {
            return trim( strip_tags( (string) $str ) );
        });

        Functions\when( 'sanitize_email' )->alias( function( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : '';
        });

        // is_email is WP's email validator — returns the email if valid,
        // false otherwise. PHP's FILTER_VALIDATE_EMAIL is close enough
        // for unit tests; integration tests use the real function.
        Functions\when( 'is_email' )->alias( function( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
        });

        // sanitize_file_name — WP's filename sanitizer. The real
        // implementation strips control chars, special chars, and
        // platform-reserved tokens. For unit-test scope, a basic
        // alnum + dot-dash-underscore filter is sufficient.
        Functions\when( 'sanitize_file_name' )->alias( function( $filename ) {
            // Strip control characters and the WP-blocked set.
            $filename = preg_replace( '/[\x00-\x1f\x7f]/', '', (string) $filename );
            $filename = preg_replace( '/[?\[\]\/\\\\=<>:;,\'"&$#*()|~`!{}%+]/', '', $filename );
            return $filename;
        });

        // wp_strip_all_tags — WP's nuclear tag stripper. Strips ALL
        // HTML tags AND removes script/style content entirely. For
        // unit-test scope, strip_tags + a script/style content
        // removal handles the common cases.
        Functions\when( 'wp_strip_all_tags' )->alias( function( $string, $remove_breaks = false ) {
            $string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
            $string = strip_tags( $string );
            if ( $remove_breaks ) {
                $string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
            }
            return trim( $string );
        });

        Functions\when( 'wp_unslash' )->alias( function( $value ) {
            return is_array( $value ) ? array_map( 'stripslashes_deep', $value ) : stripslashes( $value );
        });

        Functions\when( 'esc_html' )->alias( function( $str ) {
            return htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' );
        });

        Functions\when( 'esc_attr' )->alias( function( $str ) {
            return htmlspecialchars( $str, ENT_QUOTES, 'UTF-8' );
        });

        Functions\when( 'esc_url' )->alias( function( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        });

        Functions\when( 'esc_url_raw' )->alias( function( $url ) {
            return filter_var( $url, FILTER_VALIDATE_URL ) ? $url : '';
        });

        // Translation functions (return string as-is).
        Functions\when( '__' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );
        Functions\when( 'esc_attr__' )->returnArg( 1 );

        Functions\when( '_e' )->alias( function( $text ) {
            echo $text;
        });

        Functions\when( 'esc_html_e' )->alias( function( $text ) {
            echo htmlspecialchars( $text, ENT_QUOTES, 'UTF-8' );
        });

        // WordPress utility functions.
        Functions\when( 'wp_parse_args' )->alias( function( $args, $defaults = array() ) {
            if ( is_object( $args ) ) {
                $args = get_object_vars( $args );
            }
            return array_merge( $defaults, $args );
        });

        // wp_parse_url is a thin WP wrapper around PHP's parse_url that
        // handles a couple of WP-specific edge cases (protocol-relative
        // URLs, some pre-PHP-5.4.7 normalization). For unit-test inputs
        // the underlying parse_url produces equivalent output, and any
        // class that needs the WP-specific behavior should be tested at
        // the integration level against real WP.
        Functions\when( 'wp_parse_url' )->alias( function( $url, $component = -1 ) {
            return $component === -1 ? parse_url( $url ) : parse_url( $url, $component );
        });

        // Date functions.
        Functions\when( 'date_i18n' )->alias( function( $format, $timestamp = false ) {
            if ( $timestamp === false ) {
                $timestamp = time();
            }
            return date( $format, $timestamp );
        });

        // WordPress options.
        Functions\when( 'get_option' )->alias( function( $option, $default = false ) {
            $options = array(
                'date_format'       => 'F j, Y',
                'time_format'       => 'g:i a',
                'timezone_string'   => 'America/New_York',
                'admin_email'       => 'admin@example.com',
                'blogname'          => 'Test Site',
                'siteurl'           => 'http://example.com',
                'home'              => 'http://example.com',
            );
            return isset( $options[ $option ] ) ? $options[ $option ] : $default;
        });

        Functions\when( 'maybe_serialize' )->alias( function( $data ) {
            if ( is_array( $data ) || is_object( $data ) ) {
                return serialize( $data );
            }
            return $data;
        });

        Functions\when( 'maybe_unserialize' )->alias( function( $data ) {
            if ( is_serialized( $data ) ) {
                return @unserialize( $data );
            }
            return $data;
        });

        // WP_Error mock.
        if ( ! class_exists( 'WP_Error' ) ) {
            require_once __DIR__ . '/Mocks/WP_Error.php';
        }

        // $wpdb global — minimal stand-in. Several FRE classes (notably
        // PForms_Entry::__construct) read $wpdb->prefix at construction time
        // to compose table names, even when no SQL is going to run.
        // Provide just enough shape so construction doesn't crash;
        // integration tests use the real $wpdb against a real DB.
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            $wpdb = (object) array(
                'prefix'  => 'wptests_',
                'options' => 'wptests_options',
            );
        }

        // Filters - just return first argument.
        Functions\when( 'apply_filters' )->alias( function( $tag, $value ) {
            return $value;
        });

        // Actions - do nothing.
        Functions\when( 'do_action' )->justReturn();
        Functions\when( 'add_action' )->justReturn();
        Functions\when( 'add_filter' )->justReturn();
    }

    /**
     * Load a form fixture.
     *
     * @param string $fixture_name Fixture name without extension.
     * @return array Form configuration.
     */
    protected function load_form_fixture( $fixture_name ) {
        $path = FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/forms/' . $fixture_name . '.php';

        if ( ! file_exists( $path ) ) {
            throw new \RuntimeException( "Fixture not found: {$path}" );
        }

        return include $path;
    }

    /**
     * Load submission data fixture.
     *
     * @param string $fixture_key Fixture key.
     * @return array Submission data.
     */
    protected function load_submission_fixture( $fixture_key ) {
        $path = FRE_TEST_PLUGIN_DIR . 'tests/Fixtures/submissions/valid-submission.php';

        if ( ! file_exists( $path ) ) {
            throw new \RuntimeException( "Fixture not found: {$path}" );
        }

        $fixtures = include $path;

        if ( ! isset( $fixtures[ $fixture_key ] ) ) {
            throw new \RuntimeException( "Fixture key not found: {$fixture_key}" );
        }

        return $fixtures[ $fixture_key ];
    }
}

/**
 * Helper function to check if data is serialized.
 *
 * @param mixed $data Data to check.
 * @return bool
 */
function is_serialized( $data ) {
    if ( ! is_string( $data ) ) {
        return false;
    }
    $data = trim( $data );
    if ( 'N;' === $data ) {
        return true;
    }
    if ( strlen( $data ) < 4 ) {
        return false;
    }
    if ( ':' !== $data[1] ) {
        return false;
    }
    return @unserialize( $data ) !== false;
}
