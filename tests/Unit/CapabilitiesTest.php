<?php
/**
 * Unit tests for PForms_Capabilities.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the capability helper class.
 *
 * Focus: verify that the capability migration (manage_options → pforms_manage_forms)
 * works end-to-end — grants on install/upgrade are idempotent, revoke on uninstall
 * touches every role, and the filter hook actually extends the default grant list.
 */
class CapabilitiesTest extends UnitTestCase {

    protected function set_up() {
        parent::set_up();
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-capabilities.php';
    }

    /**
     * The cap constant must match what callers grep for across the codebase.
     *
     * If this changes, the call-site swap is incomplete or we've broken
     * backwards compatibility. Pin the string.
     */
    public function test_capability_constant_is_stable() {
        $this->assertSame( 'pforms_manage_forms', \PForms_Capabilities::MANAGE_FORMS );
    }

    /**
     * Administrators should receive MANAGE_FORMS when grant runs.
     *
     * Uses a tiny fake WP_Role that records add_cap calls.
     */
    public function test_grant_adds_capability_to_administrator() {
        $admin_role = new FakeWpRole( 'administrator', array() );

        Functions\when( 'get_role' )->alias( function ( $slug ) use ( $admin_role ) {
            return 'administrator' === $slug ? $admin_role : null;
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        \PForms_Capabilities::grant_default_capabilities();

        $this->assertTrue( $admin_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
        $this->assertSame( 1, $admin_role->add_cap_calls, 'add_cap was called exactly once.' );
    }

    /**
     * Repeated grants must not create duplicate cap entries or churn the DB.
     *
     * This is what makes the upgrader safe to run on every plugins_loaded.
     */
    public function test_grant_is_idempotent() {
        $admin_role = new FakeWpRole( 'administrator', array() );

        Functions\when( 'get_role' )->alias( function ( $slug ) use ( $admin_role ) {
            return 'administrator' === $slug ? $admin_role : null;
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        \PForms_Capabilities::grant_default_capabilities();
        \PForms_Capabilities::grant_default_capabilities();
        \PForms_Capabilities::grant_default_capabilities();

        $this->assertTrue( $admin_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
        $this->assertSame(
            1,
            $admin_role->add_cap_calls,
            'add_cap was only called once across three grant calls.'
        );
    }

    /**
     * Administrators who had manage_options before the refactor continue to
     * have form-management access after upgrade — because the upgrader grants
     * them the new capability automatically.
     *
     * This is the key capability-migration test the assessment called out.
     */
    public function test_migration_preserves_admin_access_after_cap_swap() {
        // Simulate the state of an admin on v1.2.5 before the refactor:
        // they have manage_options via WordPress core, no pforms_manage_forms.
        $admin_role = new FakeWpRole(
            'administrator',
            array( 'manage_options' => true )
        );

        Functions\when( 'get_role' )->alias( function ( $slug ) use ( $admin_role ) {
            return 'administrator' === $slug ? $admin_role : null;
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            return $value;
        } );

        // Plugin upgrade fires and grants the new cap.
        \PForms_Capabilities::grant_default_capabilities();

        // Admin now has both — manage_options is still there (WP core owns it;
        // we never touch it), and pforms_manage_forms is newly granted.
        $this->assertTrue(
            $admin_role->has_cap( 'manage_options' ),
            'The core capability we replaced is untouched.'
        );
        $this->assertTrue(
            $admin_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ),
            'The new capability is granted so every current_user_can(pforms_manage_forms) check passes.'
        );
    }

    /**
     * Site owners can extend the default-granted roles via the filter so
     * delegation works without requiring the plugin to ship a UI for it.
     */
    public function test_default_roles_filter_extends_grant_list() {
        $admin_role  = new FakeWpRole( 'administrator', array() );
        $editor_role = new FakeWpRole( 'editor', array() );

        Functions\when( 'get_role' )->alias( function ( $slug ) use ( $admin_role, $editor_role ) {
            if ( 'administrator' === $slug ) {
                return $admin_role;
            }
            if ( 'editor' === $slug ) {
                return $editor_role;
            }
            return null;
        } );

        // Filter opts the `editor` role in.
        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            if ( 'pforms_default_manage_forms_roles' === $tag ) {
                return array( 'administrator', 'editor' );
            }
            return $value;
        } );

        \PForms_Capabilities::grant_default_capabilities();

        $this->assertTrue( $admin_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
        $this->assertTrue( $editor_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
    }

    /**
     * Unknown role slugs in the filter list are silently skipped, so a typo
     * in the filter can never crash the upgrader.
     */
    public function test_grant_skips_unknown_roles() {
        Functions\when( 'get_role' )->alias( function () {
            return null;
        } );

        Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
            if ( 'pforms_default_manage_forms_roles' === $tag ) {
                return array( 'this_role_does_not_exist' );
            }
            return $value;
        } );

        // Must not throw.
        \PForms_Capabilities::grant_default_capabilities();

        $this->addToAssertionCount( 1 );
    }

    /**
     * Uninstall must remove the capability from every role — not only the
     * default-granted ones. Admins may have delegated it to custom roles via
     * the filter or a role editor plugin.
     */
    public function test_revoke_iterates_every_role() {
        $admin_role  = new FakeWpRole( 'administrator', array( \PForms_Capabilities::MANAGE_FORMS => true ) );
        $editor_role = new FakeWpRole( 'editor', array( \PForms_Capabilities::MANAGE_FORMS => true ) );
        $custom_role = new FakeWpRole( 'custom_role', array( \PForms_Capabilities::MANAGE_FORMS => true ) );

        $roles_map = array(
            'administrator' => $admin_role,
            'editor'        => $editor_role,
            'custom_role'   => $custom_role,
        );

        Functions\when( 'get_role' )->alias( function ( $slug ) use ( $roles_map ) {
            return $roles_map[ $slug ] ?? null;
        } );

        // The revoke path calls wp_roles() and verifies the result is an
        // instance of WP_Roles before iterating $role_objects. Define the
        // class with the property declared so PHP 8.2+ dynamic-property
        // warnings don't trip the test harness.
        if ( ! class_exists( 'WP_Roles' ) ) {
            eval( 'class WP_Roles { public $role_objects = array(); }' );
        }

        $wp_roles               = new \WP_Roles();
        $wp_roles->role_objects = $roles_map;

        Functions\when( 'wp_roles' )->justReturn( $wp_roles );

        \PForms_Capabilities::revoke_all_capabilities();

        $this->assertFalse( $admin_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
        $this->assertFalse( $editor_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
        $this->assertFalse( $custom_role->has_cap( \PForms_Capabilities::MANAGE_FORMS ) );
    }
}

/**
 * Minimal WP_Role stand-in that tracks cap state and call counts.
 *
 * Lives in this test file because it's only useful to CapabilitiesTest.
 */
class FakeWpRole {
    public $name;
    public $caps;
    public $add_cap_calls    = 0;
    public $remove_cap_calls = 0;

    public function __construct( $name, array $caps ) {
        $this->name = $name;
        $this->caps = $caps;
    }

    public function has_cap( $cap ) {
        return ! empty( $this->caps[ $cap ] );
    }

    public function add_cap( $cap ) {
        ++$this->add_cap_calls;
        $this->caps[ $cap ] = true;
    }

    public function remove_cap( $cap ) {
        ++$this->remove_cap_calls;
        unset( $this->caps[ $cap ] );
    }
}
