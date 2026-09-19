<?php
/**
 * The admin script and stylesheet load on this plugin's screens.
 *
 * From 1.8.0 until 2026-09-19 they loaded on none: the gate matched the page
 * name against 'fre-' / 'pforms_', and the 1.8.0 rename made every slug
 * 'pforms-…' (toplevel_page_pforms-entries, form-entries_page_pforms-forms).
 * Save Form, Delete, Copy, Test Connection, Preview Payload, Regenerate and
 * the entry actions all did nothing. The gate now compares against the hook
 * suffixes WordPress returned when the screens were registered.
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

class AdminAssetsTest extends UnitTestCase {

	/** @var string[] enqueued handles */
	private $enqueued = array();

	protected function set_up() {
		parent::set_up();
		if ( ! class_exists( 'PForms_Capabilities' ) ) {
			require_once \FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-capabilities.php';
		}
		require_once \FRE_TEST_PLUGIN_DIR . 'includes/Admin/class-fre-admin.php';

		Functions\when( 'add_action' )->justReturn( true );
		Functions\when( 'add_menu_page' )->alias( function ( $page_title, $menu_title, $cap, $slug ) {
			return 'toplevel_page_' . $slug;
		} );
		Functions\when( 'add_submenu_page' )->alias( function ( $parent, $page_title, $menu_title, $cap, $slug ) {
			return ( $parent ? 'form-entries_page_' : 'admin_page_' ) . $slug;
		} );
		Functions\when( 'wp_enqueue_style' )->alias( function ( $h ) { $this->enqueued[] = $h; } );
		Functions\when( 'wp_enqueue_script' )->alias( function ( $h ) { $this->enqueued[] = $h; } );
		foreach ( array( 'wp_style_add_data', 'wp_localize_script', 'admin_url', 'wp_create_nonce', 'wp_enqueue_media', 'wp_set_script_translations' ) as $fn ) {
			Functions\when( $fn )->justReturn( '' );
		}
	}

	private function admin() {
		$admin = new \PForms_Admin();
		$admin->add_menu_pages();
		return $admin;
	}

	public function test_assets_load_on_every_forms_screen() {
		$admin = $this->admin();
		foreach ( array( 'toplevel_page_pforms-entries', 'admin_page_pforms-entry', 'form-entries_page_pforms-export', 'form-entries_page_pforms-forms', 'form-entries_page_pforms-settings' ) as $hook ) {
			$this->enqueued = array();
			$admin->enqueue_admin_assets( $hook );
			$this->assertContains( 'pforms-admin', $this->enqueued, "admin assets missing on {$hook}" );
		}
	}

	public function test_assets_stay_off_other_screens() {
		$admin = $this->admin();
		foreach ( array( 'index.php', 'edit.php', 'toplevel_page_some-fre-thing', 'settings_page_pforms_other' ) as $hook ) {
			$this->enqueued = array();
			$admin->enqueue_admin_assets( $hook );
			$this->assertSame( array(), $this->enqueued, "admin assets leaked onto {$hook}" );
		}
	}

	public function test_the_entries_search_form_targets_the_entries_page() {
		$src = file_get_contents( \FRE_TEST_PLUGIN_DIR . 'includes/Admin/class-fre-admin.php' );
		$this->assertStringNotContainsString( 'value="fre-entries"', $src );
		$this->assertStringContainsString( 'name="page" value="pforms-entries"', $src );
	}
}
