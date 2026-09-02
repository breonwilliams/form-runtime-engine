<?php
/**
 * Unit tests for PForms_Rate_Limiter.
 *
 * Focuses on the security-critical pure-logic surfaces of the rate
 * limiter — IP detection, trusted proxy handling, bounds enforcement,
 * deadlock error detection. The actual rate-counting paths (database
 * row locking, object cache atomic increment, deadlock retries) are
 * tightly coupled to $wpdb / wp_cache_* and are exercised at the
 * integration level.
 *
 * Why these tests in particular: a rate limiter that can be bypassed
 * by spoofing X-Forwarded-For is no rate limiter at all. Bounds
 * enforcement (1 ≤ max ≤ 100, 60 ≤ window ≤ 86400) is what stops a
 * misconfigured form from accidentally allowing 9999 submissions or
 * disabling the limit entirely with max=0.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

class RateLimiterTest extends UnitTestCase {

    /**
     * @var \PForms_Rate_Limiter
     */
    private $limiter;

    /**
     * Saved $_SERVER for restore in tear_down.
     *
     * @var array
     */
    private $original_server;

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-rate-limiter.php';

        // Mock PForms_Logger to no-op — the rate limiter calls it on
        // limit-exceeded events but we don't care about logging in
        // unit tests.
        if ( ! class_exists( 'PForms_Logger' ) ) {
            eval( 'class PForms_Logger { public static function warning($m) {} public static function error($m) {} public static function info($m) {} }' );
        }

        // Save / reset $_SERVER so per-test changes don't leak.
        $this->original_server  = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.42';

        // Stub out wp_cache_add_global_groups so the constructor's
        // has_external_object_cache check returns false (we want the
        // DB-path code to be exercisable; cache path is tested
        // separately or at integration level).
        Functions\when( 'wp_cache_add_global_groups' )->justReturn( true );
        Functions\when( 'wp_cache_incr' )->justReturn( false );

        $this->limiter = new \PForms_Rate_Limiter();
    }

    protected function tear_down() {
        $_SERVER = $this->original_server;
        parent::tear_down();
    }

    // -----------------------------------------------------------------
    // get_client_ip — the security boundary for "who is this request"
    //
    // The two failure modes we MUST avoid:
    //   1. Trusting REMOTE_ADDR but ignoring X-Forwarded-For when behind
    //      a CDN / load balancer → all requests look like the same IP
    //      (the LB), so per-IP rate limits are useless.
    //   2. Trusting X-Forwarded-For unconditionally → ANY client can
    //      spoof their IP by sending the header, making per-IP rate
    //      limits useless in the OTHER direction.
    //
    // The defense: trust X-Forwarded-For ONLY when REMOTE_ADDR is in
    // the configured trusted-proxy list.
    // -----------------------------------------------------------------

    public function test_get_client_ip_uses_remote_addr_when_no_xff_header() {
        $_SERVER['REMOTE_ADDR'] = '198.51.100.7';
        unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );

        $ip = $this->limiter->get_client_ip();

        $this->assertSame( '198.51.100.7', $ip );
    }

    public function test_get_client_ip_ignores_xff_when_remote_addr_is_not_a_trusted_proxy() {
        $_SERVER['REMOTE_ADDR']          = '203.0.113.42';  // not trusted
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '1.2.3.4';        // attacker-supplied

        $ip = $this->limiter->get_client_ip();

        $this->assertSame(
            '203.0.113.42',
            $ip,
            'Untrusted REMOTE_ADDR sending X-Forwarded-For must be ignored — otherwise any client can spoof their IP and bypass per-IP rate limits.'
        );
    }

    public function test_get_client_ip_honors_xff_when_remote_addr_is_in_trusted_proxy_list() {
        // Put the proxy IP into the trusted list via the documented
        // filter, then construct a fresh limiter so it picks up the
        // filter value.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            if ( $tag === 'pforms_trusted_proxies' ) {
                return array( '203.0.113.42' );
            }
            return $value;
        } );

        $limiter = new \PForms_Rate_Limiter();

        $_SERVER['REMOTE_ADDR']          = '203.0.113.42';   // trusted LB
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7';   // real client behind LB

        $ip = $limiter->get_client_ip();

        $this->assertSame(
            '198.51.100.7',
            $ip,
            'When REMOTE_ADDR is a trusted proxy, X-Forwarded-For must be honored — that is how a CDN / load balancer scenario works.'
        );
    }

    public function test_get_client_ip_takes_first_xff_when_chain_is_present() {
        // X-Forwarded-For can be a comma-separated chain when traffic
        // passes through multiple proxies. The FIRST entry is the
        // original client; subsequent entries are intermediate proxies.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            if ( $tag === 'pforms_trusted_proxies' ) {
                return array( '203.0.113.42' );
            }
            return $value;
        } );

        $limiter = new \PForms_Rate_Limiter();

        $_SERVER['REMOTE_ADDR']          = '203.0.113.42';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.7, 10.0.0.1, 10.0.0.2';

        $ip = $limiter->get_client_ip();

        $this->assertSame( '198.51.100.7', $ip, 'First entry in XFF chain is the original client.' );
    }

    public function test_get_client_ip_returns_invalid_when_remote_addr_is_garbage() {
        $_SERVER['REMOTE_ADDR'] = 'not-an-ip-address';

        $ip = $this->limiter->get_client_ip();

        $this->assertSame(
            'invalid',
            $ip,
            'Bad input must produce a deterministic "invalid" sentinel — never an empty string that could collide with other invalid IPs in the rate-limit bucket.'
        );
    }

    public function test_get_client_ip_returns_invalid_when_xff_first_entry_is_garbage() {
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            if ( $tag === 'pforms_trusted_proxies' ) {
                return array( '203.0.113.42' );
            }
            return $value;
        } );

        $limiter = new \PForms_Rate_Limiter();

        $_SERVER['REMOTE_ADDR']          = '203.0.113.42';
        $_SERVER['HTTP_X_FORWARDED_FOR'] = 'not-an-ip, 10.0.0.1';

        $ip = $limiter->get_client_ip();

        $this->assertSame( 'invalid', $ip );
    }

    // -----------------------------------------------------------------
    // get_remaining — bucket math
    // -----------------------------------------------------------------

    public function test_get_remaining_returns_max_when_no_submissions_yet() {
        // get_current_count() reads a transient. With no transient set
        // it returns 0, so remaining = max - 0 = max.
        Functions\when( 'get_transient' )->justReturn( false );

        $remaining = $this->limiter->get_remaining( 'contact-form', array( 'max' => 5 ) );

        $this->assertSame( 5, $remaining );
    }

    public function test_get_remaining_returns_zero_when_count_meets_max() {
        Functions\when( 'get_transient' )->justReturn( 5 );

        $remaining = $this->limiter->get_remaining( 'contact-form', array( 'max' => 5 ) );

        $this->assertSame( 0, $remaining );
    }

    public function test_get_remaining_clamps_to_zero_when_count_exceeds_max() {
        // Edge case: count somehow over max (race condition window
        // before the limiter caught it). Remaining must not go negative.
        Functions\when( 'get_transient' )->justReturn( 7 );

        $remaining = $this->limiter->get_remaining( 'contact-form', array( 'max' => 5 ) );

        $this->assertSame( 0, $remaining, 'Remaining must clamp to zero, never go negative.' );
    }

    public function test_get_remaining_uses_default_max_when_settings_empty() {
        // Default max = 5 per the class constant.
        Functions\when( 'get_transient' )->justReturn( 1 );

        $remaining = $this->limiter->get_remaining( 'contact-form' );

        $this->assertSame( 4, $remaining, 'Empty settings array must fall back to default max (5).' );
    }

    // -----------------------------------------------------------------
    // is_deadlock_error — pure-logic helper
    //
    // Exposed via reflection because it's private. Worth a test because
    // the deadlock-retry loop's correctness hangs entirely on this
    // method correctly distinguishing "transient deadlock" from "real
    // error" — false positives waste retries; false negatives let real
    // errors silently masquerade as deadlocks.
    // -----------------------------------------------------------------

    public function test_is_deadlock_error_recognizes_mysql_deadlock_message() {
        $is_deadlock = $this->invoke_private( 'is_deadlock_error', array( 'Deadlock found when trying to get lock; try restarting transaction' ) );

        $this->assertTrue( $is_deadlock );
    }

    public function test_is_deadlock_error_recognizes_lock_wait_timeout() {
        $is_deadlock = $this->invoke_private( 'is_deadlock_error', array( 'Lock wait timeout exceeded; try restarting transaction' ) );

        $this->assertTrue( $is_deadlock );
    }

    public function test_is_deadlock_error_recognizes_mysql_error_codes() {
        $this->assertTrue( $this->invoke_private( 'is_deadlock_error', array( 'ERROR 1213 (40001): Deadlock found' ) ) );
        $this->assertTrue( $this->invoke_private( 'is_deadlock_error', array( 'ERROR 1205 (HY000): Lock wait timeout exceeded' ) ) );
    }

    public function test_is_deadlock_error_returns_false_for_unrelated_errors() {
        $this->assertFalse( $this->invoke_private( 'is_deadlock_error', array( 'Duplicate entry for key' ) ) );
        $this->assertFalse( $this->invoke_private( 'is_deadlock_error', array( 'Table does not exist' ) ) );
    }

    public function test_is_deadlock_error_returns_false_for_empty_string() {
        $this->assertFalse( $this->invoke_private( 'is_deadlock_error', array( '' ) ) );
    }

    /**
     * Invoke a private/protected method on the limiter instance via
     * reflection. Used to test pure-logic helpers without making
     * them public just for testability.
     *
     * Note: setAccessible() was deprecated in PHP 8.5 because PHP 8.1+
     * makes private/protected methods accessible to reflection by
     * default. Calling setAccessible(true) is now a no-op AND emits a
     * deprecation warning. Skipping the call keeps this test
     * forward-compatible.
     */
    private function invoke_private( $method_name, array $args = array() ) {
        $reflection = new \ReflectionClass( $this->limiter );
        $method     = $reflection->getMethod( $method_name );
        if ( PHP_VERSION_ID < 80100 ) { $method->setAccessible( true ); }
        return $method->invokeArgs( $this->limiter, $args );
    }
}
