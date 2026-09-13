<?php
/**
 * Frontend stylesheets are enqueued at render, never on every page.
 *
 * Measured 2026-09-13 on the demo site: the neo-brutalist stylesheet was
 * enqueued on `wp_enqueue_scripts` whenever AISB's setting was on, and
 * because it depends on `pforms-frontend` that pulled the 58 KB main
 * stylesheet onto every page of the site — 0% of it used on seven of the
 * eight pages measured. The contract this pins:
 *
 *   1. `wp_enqueue_scripts` handlers only REGISTER frontend styles;
 *   2. the renderer ENQUEUES `pforms-frontend` and, when registered,
 *      `pforms-neo-brutalist`, from its render path.
 *
 * Pure file inspection; no WordPress.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

class AssetsAtRenderTest extends UnitTestCase {

	public function test_design_system_registers_but_never_enqueues_on_wp_enqueue_scripts() {
		$src = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Integration/class-fre-design-system.php' );
		$this->assertStringContainsString( "add_action( 'wp_enqueue_scripts', array( \$this, 'maybe_register_neo_brutalist' )", $src );
		$this->assertStringContainsString( "wp_register_style(\n            'pforms-neo-brutalist'", $src );
		$this->assertStringNotContainsString( 'wp_enqueue_style(', $src, 'the design-system class must only register; the renderer enqueues' );
	}

	public function test_main_plugin_registers_frontend_style_without_enqueueing_it() {
		$src = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'form-runtime-engine.php' );
		$this->assertMatchesRegularExpression( "/wp_register_style\(\s*'pforms-frontend'/", $src );
		$this->assertDoesNotMatchRegularExpression( "/wp_enqueue_style\(\s*'pforms-frontend'/", $src );
	}

	public function test_renderer_enqueues_frontend_and_neo_brutalist_at_render() {
		$src = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-renderer.php' );
		$this->assertMatchesRegularExpression( "/wp_enqueue_style\(\s*'pforms-frontend'\s*\)/", $src );
		$this->assertMatchesRegularExpression( "/wp_style_is\(\s*'pforms-neo-brutalist',\s*'registered'\s*\)/", $src );
		$this->assertMatchesRegularExpression( "/wp_enqueue_style\(\s*'pforms-neo-brutalist'\s*\)/", $src );
	}
}
