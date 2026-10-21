<?php
/**
 * What a successful submit shows: settings.after_submit (1.12.3).
 *
 * Sparrowmere dry run (2026-10), on camera, on a page and in a popup: after
 * a successful submit the success message appeared above the whole form,
 * reset and empty (a multi-step form back on step 1), which read as "nothing
 * was sent" or as an invitation to send again. By default the confirmation
 * now replaces the form and takes focus; "reset" keeps the old behaviour for
 * forms meant to be refilled.
 *
 * The browser half (frontend.js / frontend.css) has no JS test harness in
 * this plugin, so its contract is pinned statically here and was checked in
 * a real browser by keyboard (single step, multi-step, popup with and
 * without close-on-submit).
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;
use PForms_JSON_Schema_Validator;

class AfterSubmitTest extends UnitTestCase {

	private function config( array $settings ) {
		return array(
			'fields'   => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
			'settings' => $settings,
		);
	}

	/** Render the <form> opening through the real renderer. */
	private function form_tag( array $settings ) {
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
		$renderer   = new \PForms_Renderer();
		$reflection = new \ReflectionMethod( $renderer, 'build_form' );
		if ( PHP_VERSION_ID < 80100 ) {
			$reflection->setAccessible( true );
		}
		$html = $reflection->invoke(
			$renderer,
			array(
				'id'       => 'ask',
				'title'    => 'Ask',
				'fields'   => array(),
				'settings' => $settings + array( 'spam_protection' => array() ),
			),
			array()
		);
		preg_match( '/<form[^>]*>/', $html, $m );
		return $m[0];
	}

	public function test_both_values_and_absence_are_valid() {
		foreach ( array( array(), array( 'after_submit' => 'confirmation' ), array( 'after_submit' => 'reset' ) ) as $settings ) {
			$result = PForms_JSON_Schema_Validator::validate( $this->config( $settings ) );
			$this->assertTrue( $result['valid'], json_encode( $result['errors'] ) );
			$this->assertSame( array(), preg_grep( '/after_submit/', $result['warnings'] ) );
		}
	}

	/**
	 * @dataProvider invalid_values
	 */
	public function test_anything_else_is_refused_at_save( $value ) {
		$result = PForms_JSON_Schema_Validator::validate( $this->config( array( 'after_submit' => $value ) ) );
		$this->assertFalse( $result['valid'] );
		$this->assertNotEmpty( preg_grep( '/settings\.after_submit .* is not valid\. Use one of: confirmation, reset/', $result['errors'] ) );
	}

	public function invalid_values() {
		return array( array( 'hide' ), array( 'Reset' ), array( '' ), array( true ), array( null ) );
	}

	public function test_the_default_needs_no_attribute() {
		$this->assertStringNotContainsString( 'data-after-submit', $this->form_tag( array() ) );
		$this->assertStringNotContainsString( 'data-after-submit', $this->form_tag( array( 'after_submit' => 'confirmation' ) ) );
		// A stored value that predates validation behaves as the default.
		$this->assertStringNotContainsString( 'data-after-submit', $this->form_tag( array( 'after_submit' => 'bogus' ) ) );
	}

	public function test_reset_is_told_to_the_script() {
		$this->assertStringContainsString( 'data-after-submit="reset"', $this->form_tag( array( 'after_submit' => 'reset' ) ) );
	}

	public function test_the_script_replaces_the_form_and_moves_focus() {
		$js = file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/frontend.js' );
		// Default unless the form says "reset".
		$this->assertStringContainsString( "this.form.dataset.afterSubmit !== 'reset'", $js );
		// The message still goes into the alert region first: Promptless WP's
		// popup close-on-submit watches for .fre-form__message--success.
		$success = substr( $js, strpos( $js, 'if (result.success) {' ), 3000 );
		$this->assertLessThan( strpos( $success, 'this.showConfirmation()' ), strpos( $success, "this.showMessage('success'" ) );
		// The confirmation is in place before a redirect navigates away.
		$this->assertLessThan( strpos( $success, 'window.location.href' ), strpos( $success, 'this.showConfirmation()' ) );
		$confirm = substr( $js, strpos( $js, 'showConfirmation() {' ), 1200 );
		$this->assertStringContainsString( "classList.add('fre-form--submitted')", $confirm );
		$this->assertStringContainsString( "setAttribute('tabindex', '-1')", $confirm );
		$this->assertStringContainsString( 'focus({ preventScroll: true })', $confirm );
		$this->assertStringContainsString( 'prefers-reduced-motion: reduce', $confirm );
	}

	public function test_the_stylesheet_hides_everything_but_title_and_message() {
		foreach ( array( 'frontend.css', 'frontend-rtl.css' ) as $file ) {
			$css = preg_replace( '/\s+/', ' ', file_get_contents( dirname( __DIR__, 2 ) . '/assets/css/' . $file ) );
			$this->assertStringContainsString( '.fre-form--submitted > :not(.fre-form__messages):not(.fre-form__title) { display: none; }', $css, $file );
			$this->assertStringContainsString( '.fre-form__messages:focus-visible { outline: 2px solid var(--fre-focus-color);', $css, $file );
		}
	}

	public function test_the_rulebook_and_preflight_document_it() {
		$root = dirname( __DIR__, 2 );
		$this->assertStringContainsString( '`after_submit`', file_get_contents( $root . '/docs/FRE_KNOWLEDGE_MAP.md' ) );
		$schema = json_decode( file_get_contents( $root . '/docs/form-schema.json' ), true );
		$this->assertSame( array( 'confirmation', 'reset' ), $schema['$defs']['settings']['properties']['after_submit']['enum'] ?? $schema['properties']['settings']['properties']['after_submit']['enum'] ?? null );
		$this->assertSame( PForms_JSON_Schema_Validator::AFTER_SUBMIT_VALUES, array( 'confirmation', 'reset' ) );
		$this->assertStringContainsString( 'settings.after_submit', file_get_contents( $root . '/includes/Connector/class-fre-connector-api.php' ) );
	}
}
