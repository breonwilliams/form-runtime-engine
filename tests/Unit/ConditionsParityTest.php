<?php
/**
 * The server treats conditional fields the way the browser does.
 *
 * Four gaps found writing the documentation (2026-09-19), each of which made
 * the server disagree with what the visitor saw:
 *  - a required field inside a HIDDEN SECTION still failed validation, so the
 *    form could not be submitted at all;
 *  - `contains` on a checkbox group compared the word "Array", so a field the
 *    browser showed was treated as hidden and the visitor's answer stripped;
 *  - a rule naming a key with a capital letter looked up the wrong input name
 *    and always read an empty value;
 *  - radio, checkbox and message fields built their own wrapper without
 *    `data-conditions` or the column class, so conditions never hid them.
 * Plus: a required FILE field was never enforced on the server.
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

class ConditionsParityTest extends UnitTestCase {

	/** @var \PForms_Validator */
	private $validator;

	protected function set_up() {
		parent::set_up();
		Functions\when( 'current_time' )->justReturn( date( 'Y-m-d H:i:s' ) );
		Functions\when( 'apply_filters' )->alias( function ( $tag, $value ) {
			return $value;
		} );

		require_once FRE_TEST_PLUGIN_DIR . 'includes/class-fre-autoloader.php';
		require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
		require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-conditions.php';
		require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-validator.php';
		require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/interface-fre-field-type.php';
		require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/abstract-fre-field-type.php';
		foreach ( array( 'text', 'select', 'checkbox', 'radio', 'message', 'file' ) as $type ) {
			require_once FRE_TEST_PLUGIN_DIR . "includes/Fields/class-fre-field-{$type}.php";
		}
		$this->validator = new \PForms_Validator();
		$_FILES          = array();
	}

	protected function tear_down() {
		$_FILES = array();
		parent::tear_down();
	}

	private function section_form() {
		return array(
			'fields'   => array(
				array( 'key' => 'kind', 'type' => 'select', 'options' => array( array( 'value' => 'home', 'label' => 'Home' ), array( 'value' => 'business', 'label' => 'Business' ) ) ),
				array( 'key' => 'biz', 'type' => 'section', 'label' => 'Business details', 'conditions' => array( 'rules' => array( array( 'field' => 'kind', 'operator' => 'equals', 'value' => 'business' ) ) ) ),
				array( 'key' => 'company', 'type' => 'text', 'required' => true, 'section' => 'biz' ),
			),
			'settings' => array(),
		);
	}

	public function test_a_required_field_in_a_hidden_section_is_not_validated() {
		$this->assertTrue( $this->validator->validate( $this->section_form(), array( 'pforms_field_kind' => 'home', 'pforms_field_company' => '' ) ) );
	}

	public function test_a_required_field_in_a_shown_section_is_validated() {
		$this->assertInstanceOf( 'WP_Error', $this->validator->validate( $this->section_form(), array( 'pforms_field_kind' => 'business', 'pforms_field_company' => '' ) ) );
	}

	public function test_values_in_a_hidden_section_are_stripped() {
		$out = \PForms_Conditions::strip_hidden_field_values( $this->section_form(), array( 'kind' => 'home', 'company' => 'Acme' ) );
		$this->assertArrayNotHasKey( 'company', $out );
	}

	public function test_contains_on_a_checkbox_group_matches_like_the_browser() {
		$form = array(
			'fields' => array(
				array( 'key' => 'topics', 'type' => 'checkbox', 'options' => array( array( 'value' => 'design', 'label' => 'Design' ), array( 'value' => 'tech', 'label' => 'Tech' ) ) ),
				array( 'key' => 'stack', 'type' => 'text', 'conditions' => array( 'rules' => array( array( 'field' => 'topics', 'operator' => 'contains', 'value' => 'tech' ) ) ) ),
			),
		);
		$this->assertTrue( \PForms_Conditions::field_is_visible( $form['fields'][1], $form, array( 'topics' => array( 'design', 'tech' ) ) ) );
		$this->assertFalse( \PForms_Conditions::field_is_visible( $form['fields'][1], $form, array( 'topics' => array( 'design' ) ) ) );
		$kept = \PForms_Conditions::strip_hidden_field_values( $form, array( 'topics' => array( 'tech' ), 'stack' => 'PHP' ) );
		$this->assertSame( 'PHP', $kept['stack'], 'the answer the visitor gave is kept' );
	}

	public function test_a_rule_on_a_capitalised_key_reads_the_submitted_value() {
		$form = array(
			'fields' => array(
				array( 'key' => 'Service', 'type' => 'select', 'options' => array( array( 'value' => 'repair', 'label' => 'Repair' ) ) ),
				array( 'key' => 'detail', 'type' => 'text', 'required' => true, 'conditions' => array( 'rules' => array( array( 'field' => 'Service', 'operator' => 'equals', 'value' => 'repair' ) ) ) ),
			),
		);
		// Raw POST shape: the input is named with sanitize_key() of the key.
		$this->assertTrue( \PForms_Conditions::field_is_visible( $form['fields'][1], $form, array( 'pforms_field_service' => 'repair' ) ) );
	}

	public function test_a_required_file_field_needs_a_file() {
		$form = array( 'fields' => array( array( 'key' => 'photo', 'type' => 'file', 'required' => true ) ) );
		$this->assertInstanceOf( 'WP_Error', $this->validator->validate( $form, array() ) );

		$_FILES['pforms_file_photo'] = array( 'name' => 'pothole.jpg' );
		$this->assertTrue( $this->validator->validate( $form, array() ) );

		$_FILES['pforms_file_photo'] = array( 'name' => array( '' ) );
		$this->assertInstanceOf( 'WP_Error', $this->validator->validate( $form, array() ), 'an empty multiple-file input carries no file' );
	}

	public function test_a_programmatic_submission_skips_the_file_presence_check() {
		$form = array( 'fields' => array( array( 'key' => 'photo', 'type' => 'file', 'required' => true ) ) );
		$this->assertTrue( $this->validator->validate( $form, array(), array( 'files' => false ) ) );
	}

	public function test_an_optional_or_hidden_file_field_needs_nothing() {
		$form = array(
			'fields' => array(
				array( 'key' => 'kind', 'type' => 'select', 'options' => array( array( 'value' => 'a', 'label' => 'A' ) ) ),
				array( 'key' => 'photo', 'type' => 'file', 'required' => true, 'conditions' => array( 'rules' => array( array( 'field' => 'kind', 'operator' => 'equals', 'value' => 'b' ) ) ) ),
				array( 'key' => 'extra', 'type' => 'file' ),
			),
		);
		$this->assertTrue( $this->validator->validate( $form, array( 'pforms_field_kind' => 'a' ) ) );
	}

	/**
	 * @dataProvider hand_built_wrappers
	 */
	public function test_every_field_wrapper_carries_conditions_and_column( $field ) {
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_kses' )->returnArg( 1 );
		Functions\when( 'checked' )->justReturn( '' );
		$field['key']        = 'probe';
		$field['column']     = '1/2';
		$field['conditions'] = array( 'rules' => array( array( 'field' => 'kind', 'operator' => 'equals', 'value' => 'x' ) ) );

		$class = 'PForms_Field_' . ucfirst( $field['type'] );
		$html  = ( new $class() )->render( $field, '', array( 'id' => 'f1' ) );

		$this->assertStringContainsString( 'data-conditions=', $html, "{$field['type']} wrapper has no data-conditions" );
		$this->assertMatchesRegularExpression( '/class="[^"]*fre-col--1-2/', $html, "{$field['type']} wrapper has no column class" );
	}

	public function hand_built_wrappers() {
		return array(
			'radio'           => array( array( 'type' => 'radio', 'label' => 'Pick', 'options' => array( array( 'value' => 'x', 'label' => 'X' ) ) ) ),
			'checkbox single' => array( array( 'type' => 'checkbox', 'label' => 'Agree' ) ),
			'checkbox group'  => array( array( 'type' => 'checkbox', 'label' => 'Topics', 'options' => array( array( 'value' => 'x', 'label' => 'X' ) ) ) ),
			'message'         => array( array( 'type' => 'message', 'content' => 'Hello' ) ),
		);
	}
}
