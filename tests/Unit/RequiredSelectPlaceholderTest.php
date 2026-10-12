<?php
/**
 * A required dropdown cannot submit a value the visitor never picked.
 *
 * Without an empty first option the browser preselects the first real
 * option, so `required` never fails and a skipped question submits option
 * one. Found in the video dry run (2026-10): generated forms did not follow
 * the knowledge map's "give every required select a placeholder" rule, so the
 * renderer now guarantees it for required single selects.
 *
 * Optional selects are deliberately unchanged: their value reaches FlowMint
 * workflows, and an existing optional dropdown must keep submitting what it
 * did before.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

class RequiredSelectPlaceholderTest extends UnitTestCase {

    private function render( array $overrides ) {
        if ( ! class_exists( 'PForms_Field_Select' ) ) {
            $this->markTestSkipped( 'PForms_Field_Select is not loaded.' );
        }
        $field = array_merge(
            array(
                'key'     => 'category',
                'type'    => 'select',
                'label'   => 'Category',
                'options' => array(
                    array( 'label' => 'Pothole', 'value' => 'pothole' ),
                    array( 'label' => 'Streetlight', 'value' => 'streetlight' ),
                ),
            ),
            $overrides
        );
        return ( new \PForms_Field_Select() )->render( $field, '', array( 'id' => 'report' ) );
    }

    /** The first <option> in the markup, as [value, label]. */
    private function first_option( $html ) {
        $this->assertSame( 1, preg_match( '/<option value="([^"]*)"[^>]*>([^<]*)<\/option>/', $html, $m ), 'no <option> rendered' );
        return array( $m[1], $m[2] );
    }

    public function test_required_select_without_placeholder_starts_empty() {
        list( $value, $label ) = $this->first_option( $this->render( array( 'required' => true ) ) );
        $this->assertSame( '', $value, 'a required select must open on an empty option, or `required` can never fail' );
        $this->assertSame( 'Choose an option', $label );
    }

    public function test_required_select_uses_the_configured_placeholder() {
        list( $value, $label ) = $this->first_option( $this->render( array( 'required' => true, 'placeholder' => 'Pick a category' ) ) );
        $this->assertSame( '', $value );
        $this->assertSame( 'Pick a category', $label );
    }

    public function test_required_select_has_exactly_one_empty_option() {
        $html = $this->render( array( 'required' => true, 'placeholder' => 'Pick a category' ) );
        $this->assertSame( 1, substr_count( $html, '<option value="">' ) );
    }

    public function test_optional_select_without_placeholder_is_unchanged() {
        list( $value ) = $this->first_option( $this->render( array( 'required' => false ) ) );
        $this->assertSame( 'pothole', $value, 'optional selects keep submitting what they did before (FlowMint compatibility)' );
    }

    public function test_required_multiple_select_gets_no_empty_option() {
        // A multi-select preselects nothing, so `required` already works.
        $html = $this->render( array( 'required' => true, 'multiple' => true ) );
        $this->assertStringNotContainsString( '<option value="">', $html );
    }

    public function test_a_submitted_choice_is_still_selected() {
        $field = array(
            'key' => 'category', 'type' => 'select', 'label' => 'Category', 'required' => true,
            'options' => array( array( 'label' => 'Pothole', 'value' => 'pothole' ), array( 'label' => 'Streetlight', 'value' => 'streetlight' ) ),
        );
        $html = ( new \PForms_Field_Select() )->render( $field, 'streetlight', array( 'id' => 'report' ) );
        $this->assertStringContainsString( '<option value="streetlight" selected>', $html );
    }

    public function test_server_rejects_the_empty_choice_for_a_required_select() {
        $field  = array(
            'key' => 'category', 'type' => 'select', 'label' => 'Category', 'required' => true,
            'options' => array( array( 'label' => 'Pothole', 'value' => 'pothole' ) ),
        );
        $result = ( new \PForms_Field_Select() )->validate( '', $field, array( 'id' => 'report' ) );
        $this->assertInstanceOf( \WP_Error::class, $result );
    }
}
