<?php
/**
 * Stylesheet contract for focus, controls and the multi-step nav.
 *
 * Found in the Promptless Forms video dry run (2026-10) and fixed in 1.12.3:
 *
 *   - Field focus was a 1px border change plus a 3px ring of primary at 20%:
 *     1.29:1 against white, and on a field with an error, focus changed almost
 *     nothing (the border was already red). Now a 2px outline in
 *     --fre-focus-color, a colour held to 3:1, on every field including
 *     invalid ones.
 *   - --fre-focus-color / --fre-accent-color must not be the raw primary: a
 *     light brand colour fails 3:1 on white. They are the surface-corrected
 *     link colour, falling back to the text colour.
 *   - On the last step, Submit sat 18px below Previous: its wrapper's
 *     single-step top margin applied inside the nav row.
 *   - Radios and checkboxes were the browser's blue; the file button was the
 *     browser's default.
 *
 * There is no browser in this suite, so the rules are pinned where they are
 * written. The rendering was verified in a browser.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

/**
 * frontend.css contract.
 */
class FormControlStylesTest extends UnitTestCase {

	/**
	 * The stylesheet with comments removed.
	 *
	 * @return string
	 */
	private function css() {
		$css = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'assets/css/frontend.css' );
		return preg_replace( '#/\*.*?\*/#s', '', $css );
	}

	/**
	 * Declarations of the first rule whose selector list contains $selector.
	 *
	 * @param string $selector Selector, matched as one item of the list.
	 * @return string
	 */
	private function rule( $selector ) {
		$css = $this->css();
		if ( preg_match_all( '/([^{}]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $rule ) {
				$selectors = array_map( 'trim', explode( ',', $rule[1] ) );
				if ( in_array( $selector, $selectors, true ) ) {
					return $rule[2];
				}
			}
		}
		$this->fail( "No rule for {$selector}" );
	}

	/**
	 * Value of a custom property in the first rule that defines it for a selector.
	 *
	 * @param string $selector Selector.
	 * @param string $property Property name.
	 * @return string
	 */
	private function declaration( $selector, $property ) {
		$body = $this->rule( $selector );
		$this->assertMatchesRegularExpression( '/' . preg_quote( $property, '/' ) . '\s*:/', $body, "{$selector} sets {$property}" );
		preg_match( '/' . preg_quote( $property, '/' ) . '\s*:\s*([^;]+);/', $body, $m );
		return trim( $m[1] );
	}

	public function test_accent_is_contrast_corrected_not_the_raw_primary() {
		$light = $this->declaration( '.fre-form', '--fre-accent-color' );
		$dark  = $this->declaration( '.fre-form--dark', '--fre-accent-color' );

		$this->assertSame( 'var(--aisb-smart-light-surface-link, var(--fre-text-color))', $light );
		$this->assertSame( 'var(--aisb-smart-dark-surface-link, var(--fre-text-color))', $dark );
		$this->assertSame( 'var(--fre-accent-color)', $this->declaration( '.fre-form', '--fre-focus-color' ) );
	}

	/**
	 * @return array
	 */
	public function focusable_control_provider() {
		return array(
			'input'    => array( '.fre-field__input:focus-visible' ),
			'textarea' => array( '.fre-field__textarea:focus-visible' ),
			'select'   => array( '.fre-field__select:focus-visible' ),
			'checkbox' => array( '.fre-field__checkbox:focus-visible' ),
			'radio'    => array( '.fre-field__radio:focus-visible' ),
		);
	}

	/**
	 * @dataProvider focusable_control_provider
	 * @param string $selector Focus selector.
	 */
	public function test_every_control_has_a_2px_focus_outline_in_the_focus_colour( $selector ) {
		$body = $this->rule( $selector );

		$this->assertMatchesRegularExpression( '/outline:\s*2px solid var\(--fre-focus-color\)/', $body );
		$this->assertMatchesRegularExpression( '/outline-offset:\s*2px/', $body );
	}

	public function test_no_field_focus_rule_removes_the_outline() {
		$css = $this->css();
		$this->assertDoesNotMatchRegularExpression(
			'/\.fre-field__(input|textarea|select)[^{]*:focus[^{]*\{[^}]*outline:\s*none/',
			$css,
			'outline: none on a field focus rule would hide the focus indicator'
		);
	}

	public function test_an_invalid_field_keeps_its_error_border_and_gets_no_red_tint_ring() {
		$body = $this->rule( '.fre-field--has-error .fre-field__input:focus' );

		$this->assertStringContainsString( 'border-color: var(--fre-error-color)', $body );
		$this->assertStringNotContainsString( 'box-shadow', $body, 'focus on an invalid field is the outline, not a red tint' );
	}

	public function test_buttons_focus_in_the_focus_colour() {
		$this->assertStringContainsString( 'var(--fre-focus-color', $this->rule( '.fre-btn:focus-visible' ) );
		$this->assertStringContainsString( 'var(--fre-focus-color', $this->rule( '.fre-form__submit-button:focus-visible' ) );
	}

	public function test_step_nav_submit_has_no_top_margin() {
		$this->assertMatchesRegularExpression( '/margin-top:\s*0\s*;/', $this->rule( '.fre-step__nav .fre-form__submit' ) );
	}

	public function test_radios_and_checkboxes_use_the_accent() {
		$this->assertStringContainsString( 'accent-color: var(--fre-accent-color)', $this->rule( '.fre-field__radio' ) );
	}

	public function test_file_button_uses_the_secondary_button_tokens() {
		$body = $this->rule( '.fre-field__file::file-selector-button' );

		$this->assertStringContainsString( 'color: var(--fre-ghost-color)', $body );
		$this->assertStringContainsString( 'border: 2px solid var(--fre-ghost-color)', $body );
		$this->assertStringContainsString( 'font-family: var(--fre-button-font)', $body );
		$this->assertStringContainsString( 'border-radius: var(--fre-border-radius-button)', $body );
	}
}
