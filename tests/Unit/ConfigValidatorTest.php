<?php
/**
 * Config Validator Unit Tests.
 *
 * Tests for PForms_JSON_Schema_Validator class.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the JSON schema validator.
 */
class ConfigValidatorTest extends UnitTestCase {

    /**
     * Load the class under test.
     */
    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Security/class-fre-json-schema-validator.php';
    }

    /**
     * Test valid simple form configuration passes validation.
     */
    public function test_valid_simple_config_passes() {
        $config = $this->load_form_fixture( 'simple-contact' );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertEmpty( $result['errors'] );
    }

    /**
     * Test valid multi-step form configuration passes validation.
     */
    public function test_valid_multistep_config_passes() {
        $config = $this->load_form_fixture( 'multi-step' );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertEmpty( $result['errors'] );
    }

    /**
     * Test configuration without fields array fails validation.
     */
    public function test_missing_fields_array_fails() {
        $config = array(
            'title' => 'Test Form',
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'fields', $result['errors'][0] );
    }

    /**
     * Test empty fields array fails validation.
     */
    public function test_empty_fields_array_fails() {
        $config = array(
            'title'  => 'Test Form',
            'fields' => array(),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'empty', $result['errors'][0] );
    }

    /**
     * Test field without key fails validation.
     */
    public function test_field_without_key_fails() {
        $config = array(
            'fields' => array(
                array(
                    'type'  => 'text',
                    'label' => 'Name',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'key', $result['errors'][0] );
    }

    /**
     * Test field without type fails validation.
     */
    public function test_field_without_type_fails() {
        $config = array(
            'fields' => array(
                array(
                    'key'   => 'name',
                    'label' => 'Name',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'type', $result['errors'][0] );
    }

    /**
     * Test invalid field type fails validation.
     */
    public function test_invalid_field_type_fails() {
        $config = array(
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'invalid_type',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'invalid_type', $result['errors'][0] );
    }

    /**
     * Test duplicate field keys fail validation.
     */
    public function test_duplicate_field_keys_fail() {
        $config = array(
            'fields' => array(
                array(
                    'key'  => 'email',
                    'type' => 'email',
                ),
                array(
                    'key'  => 'email',
                    'type' => 'text',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Duplicate', $result['errors'][0] );
    }

    /**
     * Test select field without options fails validation.
     */
    public function test_select_without_options_fails() {
        $config = array(
            'fields' => array(
                array(
                    'key'   => 'country',
                    'type'  => 'select',
                    'label' => 'Country',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'options', $result['errors'][0] );
    }

    /**
     * Test radio field without options fails validation.
     */
    public function test_radio_without_options_fails() {
        $config = array(
            'fields' => array(
                array(
                    'key'   => 'gender',
                    'type'  => 'radio',
                    'label' => 'Gender',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'options', $result['errors'][0] );
    }

    /**
     * Test checkbox without options passes (single checkbox).
     */
    public function test_checkbox_without_options_passes() {
        $config = array(
            'fields' => array(
                array(
                    'key'   => 'agree',
                    'type'  => 'checkbox',
                    'label' => 'I agree',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] );
    }

    /**
     * Test all valid field types pass validation.
     */
    public function test_all_valid_field_types_pass() {
        $valid_types = array(
            'text', 'email', 'tel', 'textarea', 'select', 'radio',
            'checkbox', 'file', 'hidden', 'message', 'section', 'date', 'address',
        );

        foreach ( $valid_types as $type ) {
            $config = array(
                'fields' => array(
                    array(
                        'key'  => 'test_field',
                        'type' => $type,
                    ),
                ),
            );

            // Add options for types that require them.
            if ( in_array( $type, array( 'select', 'radio' ), true ) ) {
                $config['fields'][0]['options'] = array(
                    array( 'value' => 'a', 'label' => 'Option A' ),
                );
            }

            $result = \PForms_JSON_Schema_Validator::validate( $config );

            $this->assertTrue(
                $result['valid'],
                "Field type '{$type}' should be valid. Errors: " . implode( ', ', $result['errors'] )
            );
        }
    }

    /**
     * Test invalid steps configuration fails validation.
     */
    public function test_invalid_steps_config_fails() {
        $config = array(
            'steps'  => 'not an array',
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'text',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Steps', $result['errors'][0] );
    }

    /**
     * Test step without key fails validation.
     */
    public function test_step_without_key_fails() {
        $config = array(
            'steps' => array(
                array(
                    'title' => 'Step 1',
                ),
            ),
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'text',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'key', $result['errors'][0] );
    }

    /**
     * Test duplicate step keys fail validation.
     */
    public function test_duplicate_step_keys_fail() {
        $config = array(
            'steps' => array(
                array( 'key' => 'step1', 'title' => 'Step 1' ),
                array( 'key' => 'step1', 'title' => 'Duplicate' ),
            ),
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'text',
                    'step' => 'step1',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
        $this->assertStringContainsString( 'Duplicate step', $result['errors'][0] );
    }

    /**
     * Test field referencing non-existent step generates warning.
     */
    public function test_field_referencing_unknown_step_warns() {
        $config = array(
            'steps' => array(
                array( 'key' => 'step1', 'title' => 'Step 1' ),
            ),
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'text',
                    'step' => 'nonexistent',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] ); // It's a warning, not an error.
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'unknown step', $result['warnings'][0] );
    }

    /**
     * Test unknown field property generates warning.
     */
    public function test_unknown_field_property_warns() {
        $config = array(
            'fields' => array(
                array(
                    'key'             => 'name',
                    'type'            => 'text',
                    'unknown_prop'    => 'value',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] ); // It's a warning, not an error.
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'unknown_prop', $result['warnings'][0] );
    }

    /**
     * Test invalid condition rule generates warning.
     */
    public function test_invalid_condition_rule_warns() {
        $config = array(
            'fields' => array(
                array(
                    'key'        => 'name',
                    'type'       => 'text',
                    'conditions' => array(
                        'rules' => array(
                            array(
                                // Missing 'field' and 'operator'.
                                'value' => 'test',
                            ),
                        ),
                    ),
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] ); // It's a warning, not an error.
        $this->assertNotEmpty( $result['warnings'] );
    }

    /**
     * Test get_valid_field_types returns correct types.
     */
    public function test_get_valid_field_types() {
        $types = \PForms_JSON_Schema_Validator::get_valid_field_types();

        $expected = array(
            'text', 'email', 'tel', 'textarea', 'select', 'radio',
            'checkbox', 'file', 'hidden', 'message', 'section', 'date', 'address',
        );

        foreach ( $expected as $type ) {
            $this->assertContains( $type, $types );
        }
    }

    /**
     * Test non-array config fails validation.
     */
    public function test_non_array_config_fails() {
        $result = \PForms_JSON_Schema_Validator::validate( 'not an array' );

        $this->assertFalse( $result['valid'] );
        $this->assertNotEmpty( $result['errors'] );
    }

    /**
     * Test field key with invalid characters generates warning.
     */
    public function test_invalid_field_key_format_warns() {
        $config = array(
            'fields' => array(
                array(
                    'key'  => '123invalid',
                    'type' => 'text',
                ),
            ),
        );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] ); // It's a warning, not an error.
        $this->assertNotEmpty( $result['warnings'] );
        $this->assertStringContainsString( 'should start with a letter', $result['warnings'][0] );
    }

    /**
     * Test valid column values don't generate warnings.
     */
    public function test_valid_column_values_pass() {
        $valid_columns = array( '1/2', '1/3', '2/3', '1/4', '3/4' );

        foreach ( $valid_columns as $column ) {
            $config = array(
                'fields' => array(
                    array(
                        'key'    => 'test_field',
                        'type'   => 'text',
                        'column' => $column,
                    ),
                ),
            );

            $result = \PForms_JSON_Schema_Validator::validate( $config );

            $this->assertTrue( $result['valid'], "Column '{$column}' should be valid" );
        }
    }

    /**
     * Test conditional logic form fixture passes validation.
     */
    public function test_conditional_logic_fixture_passes() {
        $config = $this->load_form_fixture( 'conditional-logic' );

        $result = \PForms_JSON_Schema_Validator::validate( $config );

        $this->assertTrue( $result['valid'] );
        $this->assertEmpty( $result['errors'] );
    }
}
