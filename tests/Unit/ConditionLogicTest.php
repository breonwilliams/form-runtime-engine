<?php
/**
 * Condition Logic Unit Tests.
 *
 * Tests for conditional field evaluation in PForms_Validator.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for the condition logic evaluation.
 */
class ConditionLogicTest extends UnitTestCase {

    /**
     * Validator instance.
     *
     * @var \PForms_Validator
     */
    private $validator;

    /**
     * Set up the validator.
     */
    protected function set_up() {
        parent::set_up();

        // Additional mocks for validator.
        Functions\when( 'current_time' )->justReturn( date( 'Y-m-d H:i:s' ) );

        // Load required classes.
        require_once FRE_TEST_PLUGIN_DIR . 'includes/class-fre-autoloader.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/interface-fre-field-type.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/abstract-fre-field-type.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-text.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-select.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-checkbox.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-radio.php';

        $this->validator = new \PForms_Validator();
    }

    /**
     * Create a simple form config with conditional field.
     *
     * @param array $condition Condition configuration.
     * @return array Form configuration.
     */
    private function create_conditional_form( array $condition ) {
        return array(
            'fields' => array(
                array(
                    'key'  => 'trigger_field',
                    'type' => 'select',
                    'options' => array(
                        array( 'value' => 'a', 'label' => 'Option A' ),
                        array( 'value' => 'b', 'label' => 'Option B' ),
                        array( 'value' => 'c', 'label' => 'Option C' ),
                    ),
                ),
                array(
                    'key'        => 'conditional_field',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => $condition,
                ),
            ),
            'settings' => array(),
        );
    }

    /**
     * Test equals operator matches exact value.
     */
    public function test_equals_operator_matches() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field'    => 'trigger_field',
                    'operator' => 'equals',
                    'value'    => 'a',
                ),
            ),
        ) );

        // When trigger_field equals 'a', conditional_field should be validated.
        $data = array(
            'trigger_field'     => 'a',
            'conditional_field' => '', // Empty, but required.
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because conditional_field is required and empty.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test equals operator skips validation when not matched.
     */
    public function test_equals_operator_skips_when_not_matched() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field'    => 'trigger_field',
                    'operator' => 'equals',
                    'value'    => 'a',
                ),
            ),
        ) );

        // When trigger_field equals 'b', conditional_field should be skipped.
        $data = array(
            'trigger_field'     => 'b',
            'conditional_field' => '', // Empty, but should be skipped.
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because conditional_field is hidden.
        $this->assertTrue( $result );
    }

    /**
     * Test not_equals operator.
     */
    public function test_not_equals_operator() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field'    => 'trigger_field',
                    'operator' => 'not_equals',
                    'value'    => 'a',
                ),
            ),
        ) );

        // When trigger_field is 'b' (not 'a'), conditional field should be shown.
        $data = array(
            'trigger_field'     => 'b',
            'conditional_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because conditional_field is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test contains operator.
     */
    public function test_contains_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'description',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'urgent_notice',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'description',
                                'operator' => 'contains',
                                'value'    => 'urgent',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // Should be visible when description contains 'urgent'.
        $data = array(
            'description'   => 'This is an urgent request',
            'urgent_notice' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because urgent_notice is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test is_empty operator.
     */
    public function test_is_empty_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'optional_field',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'fallback_field',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'optional_field',
                                'operator' => 'is_empty',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When optional_field is empty, fallback_field should be shown.
        $data = array(
            'optional_field' => '',
            'fallback_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because fallback_field is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test is_not_empty operator.
     */
    public function test_is_not_empty_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'name',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'greeting',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'name',
                                'operator' => 'is_not_empty',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When name is not empty, greeting should be shown.
        $data = array(
            'name'     => 'John',
            'greeting' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because greeting is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test in operator with array value.
     */
    public function test_in_operator() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field'    => 'trigger_field',
                    'operator' => 'in',
                    'value'    => array( 'a', 'b' ),
                ),
            ),
        ) );

        // Should be visible when trigger_field is 'a' or 'b'.
        $data = array(
            'trigger_field'     => 'a',
            'conditional_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because conditional_field is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );

        // Test with value 'c' which is not in the array.
        $data['trigger_field'] = 'c';

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because conditional_field is hidden.
        $this->assertTrue( $result );
    }

    /**
     * Test not_in operator.
     */
    public function test_not_in_operator() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field'    => 'trigger_field',
                    'operator' => 'not_in',
                    'value'    => array( 'a', 'b' ),
                ),
            ),
        ) );

        // Should be visible when trigger_field is NOT 'a' or 'b'.
        $data = array(
            'trigger_field'     => 'c',
            'conditional_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because conditional_field is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test greater_than operator.
     */
    public function test_greater_than_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'quantity',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'bulk_discount',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'quantity',
                                'operator' => 'greater_than',
                                'value'    => '10',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When quantity > 10, bulk_discount should be shown.
        $data = array(
            'quantity'      => '15',
            'bulk_discount' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because bulk_discount is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );

        // Test with quantity = 5.
        $data['quantity'] = '5';

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because bulk_discount is hidden.
        $this->assertTrue( $result );
    }

    /**
     * Test less_than operator.
     */
    public function test_less_than_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'age',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'parent_consent',
                    'type'       => 'checkbox',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'age',
                                'operator' => 'less_than',
                                'value'    => '18',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When age < 18, parent_consent should be shown.
        $data = array(
            'age'            => '16',
            'parent_consent' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because parent_consent is shown and required.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test multiple rules with AND logic.
     */
    public function test_multiple_rules_and_logic() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'country',
                    'type' => 'select',
                    'options' => array(
                        array( 'value' => 'us', 'label' => 'US' ),
                        array( 'value' => 'ca', 'label' => 'Canada' ),
                    ),
                ),
                array(
                    'key'  => 'state',
                    'type' => 'text',
                ),
                array(
                    'key'        => 'special_field',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'logic' => 'and',
                        'rules' => array(
                            array(
                                'field'    => 'country',
                                'operator' => 'equals',
                                'value'    => 'us',
                            ),
                            array(
                                'field'    => 'state',
                                'operator' => 'equals',
                                'value'    => 'CA',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // Both conditions must be met.
        $data = array(
            'country'       => 'us',
            'state'         => 'CA',
            'special_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because both conditions are met and field is required.
        $this->assertInstanceOf( 'WP_Error', $result );

        // Only one condition met.
        $data['state'] = 'NY';

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because not all conditions are met.
        $this->assertTrue( $result );
    }

    /**
     * Test multiple rules with OR logic.
     */
    public function test_multiple_rules_or_logic() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'issue_type',
                    'type' => 'select',
                    'options' => array(
                        array( 'value' => 'billing', 'label' => 'Billing' ),
                        array( 'value' => 'technical', 'label' => 'Technical' ),
                        array( 'value' => 'other', 'label' => 'Other' ),
                    ),
                ),
                array(
                    'key'        => 'urgency',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'logic' => 'or',
                        'rules' => array(
                            array(
                                'field'    => 'issue_type',
                                'operator' => 'equals',
                                'value'    => 'billing',
                            ),
                            array(
                                'field'    => 'issue_type',
                                'operator' => 'equals',
                                'value'    => 'technical',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // Either condition should trigger visibility.
        $data = array(
            'issue_type' => 'billing',
            'urgency'    => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because one condition is met.
        $this->assertInstanceOf( 'WP_Error', $result );

        // Neither condition met.
        $data['issue_type'] = 'other';

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because no conditions are met.
        $this->assertTrue( $result );
    }

    /**
     * Test is_checked operator for checkboxes.
     */
    public function test_is_checked_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'has_account',
                    'type' => 'checkbox',
                ),
                array(
                    'key'        => 'account_number',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'has_account',
                                'operator' => 'is_checked',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When checkbox is checked.
        $data = array(
            'has_account'    => '1',
            'account_number' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because checkbox is checked and field is required.
        $this->assertInstanceOf( 'WP_Error', $result );

        // When checkbox is not checked (key not present - simulates HTML behavior).
        unset( $data['has_account'] );

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because checkbox is not checked (field hidden).
        $this->assertTrue( $result );
    }

    /**
     * Test is_not_checked operator for checkboxes.
     */
    public function test_is_not_checked_operator() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'  => 'skip_details',
                    'type' => 'checkbox',
                ),
                array(
                    'key'        => 'details',
                    'type'       => 'text',
                    'required'   => true,
                    'conditions' => array(
                        'rules' => array(
                            array(
                                'field'    => 'skip_details',
                                'operator' => 'is_not_checked',
                            ),
                        ),
                    ),
                ),
            ),
            'settings' => array(),
        );

        // When checkbox is not checked (key not present - simulates HTML behavior).
        $data = array(
            // 'skip_details' is NOT present - unchecked checkboxes are not submitted.
            'details' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because checkbox is not checked (field visible) and field is required.
        $this->assertInstanceOf( 'WP_Error', $result );

        // When checkbox is checked.
        $data['skip_details'] = '1';

        $result = $this->validator->validate( $form_config, $data );

        // Should pass because checkbox is checked.
        $this->assertTrue( $result );
    }

    /**
     * Test condition with empty rules array.
     */
    public function test_empty_rules_array_shows_field() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(),
        ) );

        $data = array(
            'trigger_field'     => 'a',
            'conditional_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because empty rules means field is shown.
        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test condition with missing operator defaults to true.
     */
    public function test_missing_operator_defaults_to_show() {
        $form_config = $this->create_conditional_form( array(
            'rules' => array(
                array(
                    'field' => 'trigger_field',
                    // Missing operator.
                    'value' => 'a',
                ),
            ),
        ) );

        $data = array(
            'trigger_field'     => 'a',
            'conditional_field' => '',
        );

        $result = $this->validator->validate( $form_config, $data );

        // Should fail because field is shown (invalid rule defaults to show).
        $this->assertInstanceOf( 'WP_Error', $result );
    }
}
