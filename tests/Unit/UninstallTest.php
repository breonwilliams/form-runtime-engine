<?php
/**
 * Deleting the plugin keeps the site's data unless the owner opted in.
 *
 * Up to 1.10.0 uninstall.php dropped the entry tables and the saved forms
 * on every deletion — no way to keep them — and still left the Twilio
 * tables behind. The stack's rule is "never delete user data without
 * explicit consent": housekeeping always goes, data only with
 * pforms_delete_data_on_uninstall.
 *
 * Runs the real uninstall.php against a recording $wpdb, one process per
 * test (the file defines functions and runs at include time).
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class UninstallTest extends UnitTestCase {

	/** @var object */
	private $db;

	/** @var int[] attachments deleted */
	private $deleted_attachments = [];

	/** @var bool|int the opt-in */
	private $opt_in = false;

	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'form-runtime-engine/form-runtime-engine.php' );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', dirname( \FRE_TEST_PLUGIN_DIR ) );
		}
		$this->db = new class {
			public $prefix  = 'wp_';
			public $options  = 'wp_options';
			public $usermeta = 'wp_usermeta';
			public $queries = [];
			public function query( $sql ) { $this->queries[] = preg_replace( '/\s+/', ' ', trim( $sql ) ); return 1; }
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
			public function get_var( $sql ) { return strpos( $sql, 'SHOW TABLES' ) !== false ? 'wp_fre_entry_files' : null; }
			public function get_col( $sql ) { return strpos( $sql, 'attachment_id' ) !== false ? [ '41', '42' ] : []; }
			public function delete( $table, $where ) { $this->queries[] = 'DELETE FROM ' . $table . ' ' . json_encode( $where ); return 1; }
		};
		$GLOBALS['wpdb'] = $this->db;

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return 'pforms_delete_data_on_uninstall' === $name ? $this->opt_in : $default;
		} );
		Functions\when( 'wp_delete_attachment' )->alias( function ( $id ) { $this->deleted_attachments[] = $id; return true; } );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( null ); // no roles to revoke from in a unit run
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->justReturn( \FRE_TEST_PLUGIN_DIR );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() . '/pforms-uninstall-test-none' ] );
		Functions\when( 'trailingslashit' )->alias( function ( $p ) { return rtrim( $p, '/' ) . '/'; } );

		include \FRE_TEST_PLUGIN_DIR . 'uninstall.php';
		return implode( "\n", $this->db->queries );
	}

	public function test_by_default_no_table_is_dropped_and_no_file_deleted() {
		$sql = $this->run_uninstall();
		$this->assertStringNotContainsString( 'DROP TABLE', $sql );
		$this->assertStringNotContainsString( "LIKE 'pforms\\_%'", $sql, 'forms, settings and API keys are kept' );
		$this->assertSame( [], $this->deleted_attachments );
		$this->assertStringContainsString( '_transient_pforms_', $sql, 'housekeeping still happens' );
	}

	public function test_with_the_opt_in_everything_goes() {
		$this->opt_in = 1;
		$sql          = $this->run_uninstall();
		foreach ( [ 'fre_entries', 'fre_entry_meta', 'fre_entry_files', 'fre_webhook_log', 'fre_twilio_clients', 'fre_twilio_messages' ] as $table ) {
			$this->assertStringContainsString( "DROP TABLE IF EXISTS `wp_{$table}`", $sql );
		}
		$this->assertStringContainsString( "LIKE 'pforms\\_%'", $sql );
		$this->assertSame( [ 41, 42 ], $this->deleted_attachments, 'files visitors uploaded are removed too' );
	}

	public function test_the_settings_screen_offers_the_opt_in() {
		$src = file_get_contents( \FRE_TEST_PLUGIN_DIR . 'includes/Admin/class-fre-admin.php' );
		$this->assertStringContainsString( "'pforms_delete_data_on_uninstall'", $src );
		$this->assertStringContainsString( "'default'           => false", substr( $src, strpos( $src, "'pforms_delete_data_on_uninstall'" ), 400 ) );
	}
}
