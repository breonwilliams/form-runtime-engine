<?php
/**
 * The notification email takes the site's brand colour, readably.
 *
 * Sparrowmere dry run (2026-10): the email's header band and links were
 * hard-coded WordPress blue (#0073aa) on a moss-and-paper site. They now take
 * Promptless WP's stored primary colour, darkened until white text on it (and
 * it as link text on white) reaches 4.5:1, and keep #0073aa without the
 * plugin.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;
use PForms_Design_System;

/**
 * @runTestsInSeparateProcesses
 * @preserveGlobalState disabled
 */
class EmailAccentTest extends UnitTestCase {

	private function settings( $settings ) {
		Functions\when( 'get_option' )->alias(
			function ( $name, $default = false ) use ( $settings ) {
				return 'aisb_global_settings' === $name ? $settings : $default;
			}
		);
	}

	public function test_without_promptless_wp_it_is_the_old_blue() {
		$this->settings( array( 'primary_color' => '#3d6b45' ) );
		$this->assertSame( '#0073aa', PForms_Design_System::email_accent_color() );
	}

	public function test_a_dark_enough_primary_is_used_as_is() {
		define( 'AISB_MODERN_VERSION', 'test' );
		$this->settings( array( 'primary_color' => '#3D6B45' ) ); // Sparrowmere moss, 6.2:1.
		$this->assertSame( '#3d6b45', PForms_Design_System::email_accent_color() );
	}

	public function test_a_light_primary_is_darkened_until_white_reaches_4_5() {
		define( 'AISB_MODERN_VERSION', 'test' );
		$this->settings( array( 'primary_color' => '#FFD83D' ) ); // 1.4:1 with white.
		$accent = PForms_Design_System::email_accent_color();
		$this->assertGreaterThanOrEqual( 4.5, PForms_Design_System::contrast_with_white( $accent ) );
		// Hue kept: still a yellow-family colour (red >= green > blue).
		$r = hexdec( substr( $accent, 1, 2 ) );
		$g = hexdec( substr( $accent, 3, 2 ) );
		$b = hexdec( substr( $accent, 5, 2 ) );
		$this->assertTrue( $r >= $g && $g > $b, $accent );
	}

	public function test_short_hex_and_bad_values() {
		define( 'AISB_MODERN_VERSION', 'test' );
		$this->settings( array( 'primary_color' => '#363' ) );
		$this->assertSame( '#336633', PForms_Design_System::email_accent_color() );
		$this->settings( array( 'primary_color' => 'var:brand' ) );
		$this->assertSame( '#0073aa', PForms_Design_System::email_accent_color() );
		$this->settings( 'not-an-array' );
		$this->assertSame( '#0073aa', PForms_Design_System::email_accent_color() );
	}

	public function test_the_template_uses_it_everywhere() {
		$tpl = file_get_contents( dirname( __DIR__, 2 ) . '/templates/email/notification.php' );
		$this->assertStringContainsString( 'PForms_Design_System::email_accent_color()', $tpl );
		// The only #0073aa left is the fallback for a template running without the class.
		$this->assertSame( 1, substr_count( $tpl, '#0073aa' ) - substr_count( $tpl, "4.5:1; #0073aa otherwise" ) );
		$this->assertStringNotContainsString( 'rgba(255,255,255,0.8)', $tpl );
	}
}
