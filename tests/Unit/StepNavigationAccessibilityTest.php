<?php
/**
 * Multi-step and input-purpose markup that assistive technology relies on.
 *
 * Found by checking the Sparrowmere video's accessibility claims against the
 * 1.12.1 code (2026-10), fixed in 1.12.2:
 *
 *   - The progress indicator marked the active step with a CLASS only, so a
 *     screen reader could not tell which step was current. The active step
 *     now carries aria-current="step" (frontend.js moves it on Next/Back).
 *   - After Next/Previous, focus stayed on a button that had just been
 *     hidden. frontend.js now moves focus to the new step; this pins what it
 *     lands on: the step's title, or a step group labelled "Step N of M".
 *   - Email and tel fields had no autocomplete token unless the author set
 *     one (WCAG 1.3.5). The type now supplies it; nothing is guessed from a
 *     field's name.
 *
 * The behaviour itself (focus, first-invalid, novalidate) lives in
 * assets/js/frontend.js, which this plugin has no JavaScript test runner for;
 * it was verified in a browser. This file pins the markup it depends on.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

/**
 * Step, progress and autocomplete markup contract.
 */
class StepNavigationAccessibilityTest extends UnitTestCase {

	/**
	 * Call a private renderer method.
	 *
	 * @param string $method Method name.
	 * @param array  $args   Arguments.
	 * @return string
	 */
	private function call_renderer( $method, array $args ) {
		$renderer   = new \PForms_Renderer();
		$reflection = new \ReflectionMethod( $renderer, $method );
		if ( PHP_VERSION_ID < 80100 ) {
			// Required before PHP 8.1 (CI runs 7.4); a deprecated no-op after.
			$reflection->setAccessible( true );
		}
		return $reflection->invokeArgs( $renderer, $args );
	}

	/**
	 * Two steps with no fields: the step markup is what is under test.
	 *
	 * @param bool $show_titles Whether step titles are shown.
	 * @return array
	 */
	private function form( $show_titles ) {
		return array(
			'id'       => 'consult',
			'fields'   => array(),
			'steps'    => array(
				array(
					'key'   => 'project',
					'title' => 'Your project',
				),
				array(
					'key'   => 'you',
					'title' => 'About you',
				),
			),
			'settings' => array(
				'multistep' => array( 'show_step_titles' => $show_titles ),
			),
		);
	}

	/**
	 * @return array
	 */
	public function progress_style_provider() {
		return array(
			'steps' => array( 'steps' ),
			'dots'  => array( 'dots' ),
		);
	}

	/**
	 * Only the first progress step is current on first render.
	 *
	 * @dataProvider progress_style_provider
	 * @param string $style Progress style.
	 */
	public function test_first_progress_step_is_current( $style ) {
		$form = $this->form( true );
		$html = $this->call_renderer(
			'render_progress_indicator',
			array( $form['steps'], array( 'multistep' => array( 'progress_style' => $style ) ) )
		);

		$this->assertSame( 1, substr_count( $html, 'aria-current="step"' ), "{$style}: exactly one step is current" );
		$this->assertMatchesRegularExpression(
			'/<div class="fre-progress__step fre-progress__step--active" data-step="1" aria-current="step">/',
			$html,
			"{$style}: the current step is the active one"
		);
	}

	/**
	 * With titles shown, each step is a group named by its visible title,
	 * and the title has the id that names it (frontend.js focuses it).
	 */
	public function test_steps_are_groups_named_by_their_titles() {
		$html = $this->call_renderer( 'render_multistep_fields', array( $this->form( true ), array() ) );

		foreach ( array( 1, 2 ) as $n ) {
			$id = "fre-consult-step-{$n}-title";
			$this->assertMatchesRegularExpression(
				'/<div class="fre-step[^"]*" data-step="' . $n . '" data-step-key="[a-z]+" role="group" aria-labelledby="' . $id . '">/',
				$html,
				"step {$n} is a group labelled by its title"
			);
			$this->assertStringContainsString( '<h4 class="fre-step__title" id="' . $id . '">', $html, "step {$n} title carries the id" );
		}
		$this->assertStringNotContainsString( 'aria-label="Step', $html, 'no generic label when a title names the step' );
	}

	/**
	 * With titles hidden, each step is a group labelled "Step N of M".
	 */
	public function test_steps_without_titles_are_labelled_by_position() {
		$html = $this->call_renderer( 'render_multistep_fields', array( $this->form( false ), array() ) );

		$this->assertStringContainsString( 'role="group" aria-label="Step 1 of 2"', $html );
		$this->assertStringContainsString( 'role="group" aria-label="Step 2 of 2"', $html );
		$this->assertStringNotContainsString( 'fre-step__title', $html );
		$this->assertStringNotContainsString( 'aria-labelledby', $html );
	}

	/**
	 * @return array
	 */
	public function autocomplete_provider() {
		return array(
			'email defaults to email'  => array( 'PForms_Field_Email', 'email', null, 'email' ),
			'tel defaults to tel'      => array( 'PForms_Field_Tel', 'tel', null, 'tel' ),
			'author value wins'        => array( 'PForms_Field_Email', 'email', 'work email', 'work email' ),
			'author can turn it off'   => array( 'PForms_Field_Tel', 'tel', 'off', 'off' ),
			'text is never guessed'    => array( 'PForms_Field_Text', 'text', null, null ),
			'text keeps author value'  => array( 'PForms_Field_Text', 'text', 'name', 'name' ),
		);
	}

	/**
	 * Email and tel carry their purpose; other types only what the author set.
	 *
	 * @dataProvider autocomplete_provider
	 * @param string      $class    Field class.
	 * @param string      $type     Field type.
	 * @param string|null $authored Author's autocomplete value, if any.
	 * @param string|null $expected Expected attribute value, or null for none.
	 */
	public function test_autocomplete_comes_from_the_type( $class, $type, $authored, $expected ) {
		if ( ! class_exists( $class ) ) {
			$this->markTestSkipped( "Field class {$class} is not loaded." );
		}
		$field = array(
			'key'   => 'name',
			'type'  => $type,
			'label' => 'Name',
		);
		if ( null !== $authored ) {
			$field['autocomplete'] = $authored;
		}

		$html = ( new $class() )->render( $field, '', array( 'id' => 'contact' ) );

		if ( null === $expected ) {
			$this->assertStringNotContainsString( 'autocomplete=', $html, "{$type}: no token is guessed from the field's name" );
		} else {
			$this->assertStringContainsString( 'autocomplete="' . $expected . '"', $html );
			$this->assertSame( 1, substr_count( $html, 'autocomplete=' ), 'one autocomplete attribute' );
		}
	}
}
