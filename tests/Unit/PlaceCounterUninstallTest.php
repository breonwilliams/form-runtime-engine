<?php
/**
 * Uninstall and the place counts.
 *
 * A form's place count is one wp_options row named by
 * PForms_Place_Counter::option_name(). It is form data: deleting the plugin
 * with "Remove all data" ticked removes it; the default uninstall keeps it
 * with the forms it belongs to (a reinstall must not reopen a full form).
 *
 * Runs the real uninstall.php against a recording $wpdb (same harness as
 * UninstallTest), then matches the counter's row name against every
 * `option_name LIKE '...'` uninstall actually ran, with SQL LIKE semantics.
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class PlaceCounterUninstallTest extends UnitTestCase {

	/** @var bool|int the opt-in */
	private $opt_in = false;

	/**
	 * @return string[] Executed SQL.
	 */
	private function run_uninstall() {
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'form-runtime-engine/form-runtime-engine.php' );
		}
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			define( 'WP_PLUGIN_DIR', dirname( \FRE_TEST_PLUGIN_DIR ) );
		}
		$db = new class {
			public $prefix   = 'wp_';
			public $options  = 'wp_options';
			public $usermeta = 'wp_usermeta';
			public $queries  = [];
			public function query( $sql ) { $this->queries[] = preg_replace( '/\s+/', ' ', trim( $sql ) ); return 1; }
			public function prepare( $sql, ...$args ) { return vsprintf( str_replace( '%s', "'%s'", $sql ), $args ); }
			public function get_var( $sql ) { return null; }
			public function get_col( $sql ) { return []; }
			public function delete( $table, $where ) { $this->queries[] = 'DELETE FROM ' . $table . ' ' . json_encode( $where ); return 1; }
		};
		$GLOBALS['wpdb'] = $db;

		Functions\when( 'get_option' )->alias( function ( $name, $default = false ) {
			return 'pforms_delete_data_on_uninstall' === $name ? $this->opt_in : $default;
		} );
		Functions\when( 'wp_delete_attachment' )->justReturn( true );
		Functions\when( 'is_multisite' )->justReturn( false );
		Functions\when( 'wp_cache_flush' )->justReturn( true );
		Functions\when( 'wp_roles' )->justReturn( null );
		Functions\when( 'delete_option' )->justReturn( true );
		Functions\when( 'plugin_dir_path' )->justReturn( \FRE_TEST_PLUGIN_DIR );
		Functions\when( 'wp_upload_dir' )->justReturn( [ 'basedir' => sys_get_temp_dir() . '/pforms-uninstall-test-none' ] );
		Functions\when( 'trailingslashit' )->alias( function ( $p ) { return rtrim( $p, '/' ) . '/'; } );

		include \FRE_TEST_PLUGIN_DIR . 'uninstall.php';
		return $db->queries;
	}

	/**
	 * Whether any executed DELETE on wp_options removes the named row.
	 *
	 * @param string[] $queries Executed SQL.
	 * @param string   $name    option_name.
	 * @return bool
	 */
	private function deletes_option( array $queries, $name ) {
		foreach ( $queries as $sql ) {
			if ( 0 !== stripos( $sql, 'DELETE FROM wp_options' ) ) {
				continue;
			}
			preg_match_all( "/option_name\s+LIKE\s+'((?:[^'\\\\]|\\\\.)*)'/i", $sql, $likes );
			foreach ( $likes[1] as $pattern ) {
				if ( preg_match( $this->like_to_regex( $pattern ), $name ) ) {
					return true;
				}
			}
			preg_match_all( "/option_name\s*=\s*'([^']*)'/i", $sql, $equals );
			if ( in_array( $name, $equals[1], true ) ) {
				return true;
			}
		}
		return false;
	}

	private function like_to_regex( $pattern ) {
		$regex = '';
		$len   = strlen( $pattern );
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $pattern[ $i ];
			if ( '\\' === $c && $i + 1 < $len ) {
				$regex .= preg_quote( $pattern[ ++$i ], '/' );
			} elseif ( '%' === $c ) {
				$regex .= '.*';
			} elseif ( '_' === $c ) {
				$regex .= '.';
			} else {
				$regex .= preg_quote( $c, '/' );
			}
		}
		return '/^' . $regex . '$/s';
	}

	public function test_like_translation_is_sound() {
		$this->assertTrue( (bool) preg_match( $this->like_to_regex( 'pforms\\_%' ), 'pforms_places_camp' ) );
		$this->assertFalse( (bool) preg_match( $this->like_to_regex( 'pforms\\_%' ), 'pformsXplaces' ) );
		$this->assertFalse( (bool) preg_match( $this->like_to_regex( '_transient_pforms_%' ), 'pforms_places_camp' ) );
	}

	public function test_remove_all_data_removes_every_place_count() {
		$this->opt_in = 1;
		$queries      = $this->run_uninstall();

		foreach ( [ 'camp', 'summer-camp_2027', str_repeat( 'x', 200 ) ] as $form_id ) {
			$name = \PForms_Place_Counter::option_name( $form_id );
			$this->assertLessThanOrEqual( 191, strlen( $name ), 'Fits wp_options.option_name.' );
			$this->assertTrue( $this->deletes_option( $queries, $name ), "Opt-in uninstall removes {$name}." );
		}
	}

	public function test_default_uninstall_keeps_place_counts_with_the_forms() {
		$queries = $this->run_uninstall();

		$this->assertFalse(
			$this->deletes_option( $queries, \PForms_Place_Counter::option_name( 'camp' ) ),
			'Without the opt-in the count stays, like the saved forms, so a reinstall does not reopen a full form.'
		);
		$this->assertFalse( $this->deletes_option( $queries, 'pforms_forms' ), 'Sanity: forms are kept too.' );
	}
}
