<?php
/**
 * Unit tests for PForms_Upgrader.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the plugin-version upgrade handler.
 *
 * Focus: verify the version-transition matrix — fresh install, same version,
 * downgrade, and upgrade all behave correctly, and that the common upgrade
 * steps (capability grant) fire at the right times.
 */
class UpgraderTest extends UnitTestCase {

    /**
     * In-memory option store shared between test expectations.
     *
     * @var array
     */
    private $option_store = array();

    /**
     * Logged warning messages, so we can assert the downgrade path emits them.
     *
     * @var array
     */
    private $logged_warnings = array();

    /**
     * Call counter for PForms_Capabilities::grant_default_capabilities via an action spy.
     *
     * @var int
     */
    private $grant_calls = 0;

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-capabilities.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-upgrader.php';

        $this->option_store    = array();
        $this->logged_warnings = array();
        $this->grant_calls     = 0;

        Functions\when( 'get_option' )->alias( function ( $option, $default = false ) {
            return $this->option_store[ $option ] ?? $default;
        } );

        Functions\when( 'update_option' )->alias( function ( $option, $value ) {
            $this->option_store[ $option ] = $value;
            return true;
        } );

        // Capabilities grant records a call so we can assert it fired — we don't
        // need to exercise the real grant logic here, that's CapabilitiesTest's job.
        Functions\when( 'get_role' )->alias( function () {
            ++$this->grant_calls;
            return null;
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        Functions\when( 'do_action' )->justReturn();
    }

    /**
     * Fresh install (no stored version) stamps the current version and runs
     * the common upgrade steps.
     */
    public function test_fresh_install_stamps_version_and_grants_caps() {
        \PForms_Upgrader::maybe_upgrade();

        $this->assertSame( PForms_VERSION, $this->option_store['pforms_plugin_version'] ?? null );
        $this->assertGreaterThanOrEqual(
            1,
            $this->grant_calls,
            'Grant was invoked at least once on fresh install.'
        );
    }

    /**
     * When the stored version already matches PForms_VERSION, the upgrader is a
     * no-op — nothing written, no grant call. This is the hot path: it runs
     * on every plugins_loaded.
     */
    public function test_same_version_is_noop() {
        $this->option_store['pforms_plugin_version'] = PForms_VERSION;

        \PForms_Upgrader::maybe_upgrade();

        $this->assertSame( 0, $this->grant_calls, 'No grant on hot path.' );
    }

    /**
     * Upgrade path: stored < current. Runs common upgrade steps and stamps
     * the new version.
     */
    public function test_upgrade_runs_common_steps_and_stamps_version() {
        $this->option_store['pforms_plugin_version'] = '0.9.0';

        \PForms_Upgrader::maybe_upgrade();

        $this->assertSame( PForms_VERSION, $this->option_store['pforms_plugin_version'] );
        $this->assertGreaterThanOrEqual( 1, $this->grant_calls, 'Grant fired on upgrade.' );
    }

    /**
     * Downgrade path (stored > current) does nothing — no grant, no version
     * rewrite. We preserve the higher stored version so re-upgrading later
     * doesn't re-trigger routines.
     */
    public function test_downgrade_is_noop_and_preserves_stored_version() {
        $this->option_store['pforms_plugin_version'] = '99.0.0';

        // Stub the logger to capture warnings without requiring the real class.
        if ( ! class_exists( 'PForms_Logger' ) ) {
            eval( 'class PForms_Logger { public static function warning($msg) {} }' );
        }

        \PForms_Upgrader::maybe_upgrade();

        $this->assertSame(
            '99.0.0',
            $this->option_store['pforms_plugin_version'],
            'Downgrade does not rewrite the higher stored version.'
        );
        $this->assertSame( 0, $this->grant_calls, 'No grant on downgrade.' );
    }

    /**
     * Activation hook entry point stamps version and grants caps for fresh
     * installs without needing a plugins_loaded cycle.
     *
     * This is the belt-and-braces path: activation fires on first install and
     * (sometimes) on WP plugin updates, guaranteeing caps are present before
     * the admin user's next page load.
     */
    public function test_on_activation_fresh_install() {
        \PForms_Upgrader::on_activation();

        $this->assertSame( PForms_VERSION, $this->option_store['pforms_plugin_version'] ?? null );
        $this->assertGreaterThanOrEqual( 1, $this->grant_calls );
    }

    /**
     * Reactivation on an existing install still ensures caps are present —
     * guards against caps being accidentally removed by a prior uninstall
     * or manual intervention.
     */
    public function test_on_activation_existing_install_still_grants_caps() {
        $this->option_store['pforms_plugin_version'] = PForms_VERSION;

        \PForms_Upgrader::on_activation();

        $this->assertGreaterThanOrEqual(
            1,
            $this->grant_calls,
            'Re-activation re-grants caps even when version matches.'
        );
    }

    /**
     * The public getter returns the stored version unchanged — useful for
     * diagnostics and test introspection.
     */
    public function test_get_stored_version_returns_option_value() {
        $this->option_store['pforms_plugin_version'] = '1.2.3';

        $this->assertSame( '1.2.3', \PForms_Upgrader::get_stored_version() );
    }
}
