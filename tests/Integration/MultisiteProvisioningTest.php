<?php
/**
 * Multisite provisioning integration tests.
 *
 * Verifies Promptless Forms provisions its per-site database tables correctly
 * across a multisite network — on new-subsite creation (the wp_initialize_site
 * hook) and via the load-time self-heal — and that it does NOT provision sites
 * where it is not network-active. Skipped on single-site installs.
 *
 * Run with:
 *   TEST_SUITE=integration WP_TESTS_MULTISITE=1 vendor/bin/phpunit --testsuite Integration
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * @group ms-required
 */
class MultisiteProvisioningTest extends IntegrationTestCase {

    /**
     * Skip the whole class unless the test run is multisite.
     */
    public function set_up() {
        parent::set_up();

        if ( ! is_multisite() ) {
            $this->markTestSkipped( 'Multisite-only test; run with WP_TESTS_MULTISITE=1.' );
        }
    }

    /**
     * A subsite created while the plugin is network-active is fully provisioned
     * by the wp_initialize_site hook (Promptless_Forms::on_new_site()).
     */
    public function test_new_subsite_provisioned_when_network_active() {
        // Mark the plugin network-active so on_new_site() acts on creation.
        update_site_option(
            'active_sitewide_plugins',
            array( PForms_PLUGIN_BASENAME => time() )
        );

        // Creating a blog fires wp_initialize_site -> on_new_site().
        $blog_id = self::factory()->blog->create();

        switch_to_blog( $blog_id );
        $health = ( new \PForms_Migrator() )->check_database_health();
        restore_current_blog();

        $this->assertTrue(
            $health,
            'New subsite should have all Promptless Forms tables. Missing: '
            . ( is_array( $health ) ? implode( ', ', $health ) : '(none reported)' )
        );
    }

    /**
     * A subsite missing its tables (e.g. created before multisite support
     * shipped) is repaired by the version-gated load-time self-heal.
     */
    public function test_self_heal_recreates_missing_tables() {
        $blog_id = self::factory()->blog->create();

        switch_to_blog( $blog_id );

        // Simulate a site that never provisioned: drop tables (this also
        // clears pforms_db_version) and confirm the precondition.
        $migrator = new \PForms_Migrator();
        $migrator->drop_tables();
        $this->assertNotTrue(
            $migrator->check_database_health(),
            'Precondition: the subsite should be missing tables before self-heal.'
        );

        // Invoke the private, version-gated self-heal directly (it operates on
        // the current/switched blog).
        $plugin = pforms();
        $method = new \ReflectionMethod( $plugin, 'maybe_provision_current_site' );
        $method->setAccessible( true );
        $method->invoke( $plugin );

        $health = ( new \PForms_Migrator() )->check_database_health();
        restore_current_blog();

        $this->assertTrue(
            $health,
            'Self-heal should recreate the missing tables on the current site.'
        );
    }

    /**
     * on_new_site() is a no-op when the plugin is not network-active — a site
     * where an administrator has not enabled the plugin must not be provisioned
     * behind their back.
     */
    public function test_new_subsite_not_provisioned_when_not_network_active() {
        delete_site_option( 'active_sitewide_plugins' );

        $blog_id = self::factory()->blog->create();

        switch_to_blog( $blog_id );
        $health = ( new \PForms_Migrator() )->check_database_health();
        restore_current_blog();

        $this->assertNotTrue(
            $health,
            'A subsite should not be auto-provisioned when the plugin is not network-active.'
        );
    }
}
