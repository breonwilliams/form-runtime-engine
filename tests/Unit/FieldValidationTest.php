<?php
/**
 * Field Validation Unit Tests.
 *
 * Tests for individual field type validation.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Tests for field validation.
 */
class FieldValidationTest extends UnitTestCase {

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
        Functions\when( 'is_email' )->alias( function( $email ) {
            return filter_var( $email, FILTER_VALIDATE_EMAIL ) !== false;
        });

        // Load required classes.
        require_once FRE_TEST_PLUGIN_DIR . 'includes/class-fre-autoloader.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-logger.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-validator.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/interface-fre-field-type.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/abstract-fre-field-type.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-text.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-email.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-tel.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-textarea.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-select.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-radio.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-checkbox.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-hidden.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-message.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-section.php';
        require_once FRE_TEST_PLUGIN_DIR . 'includes/Fields/class-fre-field-date.php';

        $this->validator = new \PForms_Validator();
    }

    /**
     * Helper to create form config with single field.
     *
     * @param array $field Field configuration.
     * @return array Form configuration.
     */
    private function create_form_with_field( array $field ) {
        return array(
            'fields'   => array( $field ),
            'settings' => array(),
        );
    }

    /**
     * Translate clean field keys into the wire shape the validator reads.
     *
     * PForms_Validator looks a value up by `$field_type->get_name( $field )`,
     * which is `pforms_field_{key}` — the same string the renderer puts in the
     * input's name attribute, and therefore the key that actually arrives in
     * $_POST. The submission handler does this translation itself for
     * programmatic callers (prefix_field_keys(), see CONNECTOR_SPEC §9.9),
     * because clean keys are the natural shape for a JSON API while the
     * prefix exists to avoid collisions with WordPress POST params.
     *
     * These tests originally passed clean keys straight through, so every
     * lookup missed and the validator saw an empty value for every field.
     * Tests asserting "valid input passes" failed outright; tests asserting
     * "empty input fails" PASSED for the wrong reason, since a missing key and
     * an empty value are indistinguishable at that point. Going through this
     * helper keeps the test bodies readable while sending what a browser sends.
     *
     * @param array $data Values keyed by clean field key.
     * @return array Values keyed by input name.
     */
    private function wire( array $data ) {
        $prefixed = array();

        foreach ( $data as $key => $value ) {
            $prefixed[ 'pforms_field_' . $key ] = $value;
        }

        return $prefixed;
    }

    /**
     * Pin the helper to production.
     *
     * wire() hardcodes the `pforms_field_` prefix. If production ever changes
     * what get_name() returns, every lookup in this file would miss again and
     * the damage would be mostly SILENT: the "invalid input is rejected" tests
     * would keep passing, because a missing value is empty and empty input is
     * rejected too. Only the "valid input passes" half would go red. This test
     * fails loudly instead, and names the reason.
     */
    public function test_wire_helper_matches_production_input_name() {
        $field      = array( 'key' => 'name', 'type' => 'text' );
        $field_type = new \PForms_Field_Text();

        $wired = array_keys( $this->wire( array( 'name' => 'John Doe' ) ) );

        $this->assertSame(
            $field_type->get_name( $field ),
            $wired[0],
            'wire() must produce the same key the renderer puts in the input name attribute.'
        );
    }

    // =====================
    // TEXT FIELD TESTS
    // =====================

    /**
     * Test required text field with value passes.
     */
    public function test_text_field_required_with_value_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'name',
            'type'     => 'text',
            'required' => true,
        ) );

        $data   = array( 'name' => 'John Doe' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test required text field empty fails.
     */
    public function test_text_field_required_empty_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'name',
            'type'     => 'text',
            'required' => true,
        ) );

        $data   = array( 'name' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertEquals( 'validation_failed', $result->get_error_code() );
    }

    /**
     * Test optional text field empty passes.
     */
    public function test_text_field_optional_empty_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'name',
            'type'     => 'text',
            'required' => false,
        ) );

        $data   = array( 'name' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test text field minlength validation.
     */
    public function test_text_field_minlength_validation() {
        $form_config = $this->create_form_with_field( array(
            'key'       => 'username',
            'type'      => 'text',
            'minlength' => 3,
        ) );

        // Too short.
        $data   = array( 'username' => 'ab' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // Meets minimum.
        $data   = array( 'username' => 'abc' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test text field maxlength validation.
     */
    public function test_text_field_maxlength_validation() {
        $form_config = $this->create_form_with_field( array(
            'key'       => 'code',
            'type'      => 'text',
            'maxlength' => 5,
        ) );

        // Too long.
        $data   = array( 'code' => 'abcdef' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // Meets maximum.
        $data   = array( 'code' => 'abcde' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // EMAIL FIELD TESTS
    // =====================

    /**
     * Test email field with valid email passes.
     */
    public function test_email_field_valid_email_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'email',
            'type'     => 'email',
            'required' => true,
        ) );

        $data   = array( 'email' => 'test@example.com' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test email field with invalid email fails.
     */
    public function test_email_field_invalid_email_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'email',
            'type'     => 'email',
            'required' => true,
        ) );

        $invalid_emails = array(
            'not-an-email',
            'missing@domain',
            '@no-local-part.com',
            'spaces in@email.com',
        );

        foreach ( $invalid_emails as $email ) {
            $data   = array( 'email' => $email );
            $result = $this->validator->validate( $form_config, $this->wire( $data ) );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "Email '{$email}' should fail validation"
            );
        }
    }

    /**
     * Test email field empty when required fails.
     */
    public function test_email_field_required_empty_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'email',
            'type'     => 'email',
            'required' => true,
        ) );

        $data   = array( 'email' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    // =====================
    // TEL FIELD TESTS
    // =====================

    /**
     * Test tel field with valid phone passes.
     */
    public function test_tel_field_valid_phone_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'phone',
            'type'     => 'tel',
            'required' => true,
        ) );

        $valid_phones = array(
            '555-123-4567',
            '(555) 123-4567',
            '+1 555 123 4567',
            '5551234567',
        );

        foreach ( $valid_phones as $phone ) {
            $data   = array( 'phone' => $phone );
            $result = $this->validator->validate( $form_config, $this->wire( $data ) );

            $this->assertTrue( $result, "Phone '{$phone}' should pass validation" );
        }
    }

    /**
     * Test tel field optional empty passes.
     */
    public function test_tel_field_optional_empty_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'phone',
            'type'     => 'tel',
            'required' => false,
        ) );

        $data   = array( 'phone' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // TEXTAREA FIELD TESTS
    // =====================

    /**
     * Test textarea field required with value passes.
     */
    public function test_textarea_field_required_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'message',
            'type'     => 'textarea',
            'required' => true,
            'rows'     => 5,
        ) );

        $data   = array( 'message' => 'This is a test message.' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test textarea field with minlength/maxlength.
     */
    public function test_textarea_field_length_validation() {
        $form_config = $this->create_form_with_field( array(
            'key'       => 'description',
            'type'      => 'textarea',
            'minlength' => 10,
            'maxlength' => 100,
        ) );

        // Too short.
        $data   = array( 'description' => 'Short' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // Valid length.
        $data   = array( 'description' => 'This is a valid description.' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // SELECT FIELD TESTS
    // =====================

    /**
     * Test select field with valid option passes.
     */
    public function test_select_field_valid_option_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'country',
            'type'     => 'select',
            'required' => true,
            'options'  => array(
                array( 'value' => 'us', 'label' => 'United States' ),
                array( 'value' => 'ca', 'label' => 'Canada' ),
            ),
        ) );

        $data   = array( 'country' => 'us' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test select field with placeholder value when required fails.
     */
    public function test_select_field_placeholder_required_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'         => 'country',
            'type'        => 'select',
            'required'    => true,
            'placeholder' => 'Select a country',
            'options'     => array(
                array( 'value' => 'us', 'label' => 'United States' ),
            ),
        ) );

        $data   = array( 'country' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    // =====================
    // RADIO FIELD TESTS
    // =====================

    /**
     * Test radio field with valid option passes.
     */
    public function test_radio_field_valid_option_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'contact_method',
            'type'     => 'radio',
            'required' => true,
            'options'  => array(
                array( 'value' => 'email', 'label' => 'Email' ),
                array( 'value' => 'phone', 'label' => 'Phone' ),
            ),
        ) );

        $data   = array( 'contact_method' => 'email' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test radio field required without selection fails.
     */
    public function test_radio_field_required_empty_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'contact_method',
            'type'     => 'radio',
            'required' => true,
            'options'  => array(
                array( 'value' => 'email', 'label' => 'Email' ),
            ),
        ) );

        $data   = array( 'contact_method' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    // =====================
    // CHECKBOX FIELD TESTS
    // =====================

    /**
     * Test single checkbox required and checked passes.
     */
    public function test_single_checkbox_required_checked_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'agree_terms',
            'type'     => 'checkbox',
            'required' => true,
        ) );

        $data   = array( 'agree_terms' => '1' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test single checkbox required and unchecked fails.
     */
    public function test_single_checkbox_required_unchecked_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'agree_terms',
            'type'     => 'checkbox',
            'required' => true,
        ) );

        $data   = array( 'agree_terms' => '' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test checkbox group with valid selection passes.
     */
    public function test_checkbox_group_valid_selection_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'interests',
            'type'     => 'checkbox',
            'required' => true,
            'options'  => array(
                array( 'value' => 'tech', 'label' => 'Technology' ),
                array( 'value' => 'design', 'label' => 'Design' ),
            ),
        ) );

        $data   = array( 'interests' => array( 'tech', 'design' ) );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test checkbox group required without selection fails.
     */
    public function test_checkbox_group_required_empty_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'interests',
            'type'     => 'checkbox',
            'required' => true,
            'options'  => array(
                array( 'value' => 'tech', 'label' => 'Technology' ),
            ),
        ) );

        $data   = array( 'interests' => array() );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    // =====================
    // DATE FIELD TESTS
    // =====================

    /**
     * Test date field with valid date passes.
     */
    public function test_date_field_valid_date_passes() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'appointment_date',
            'type'     => 'date',
            'required' => true,
        ) );

        $data   = array( 'appointment_date' => '2024-06-15' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test date field with invalid format fails.
     */
    public function test_date_field_invalid_format_fails() {
        $form_config = $this->create_form_with_field( array(
            'key'      => 'appointment_date',
            'type'     => 'date',
            'required' => true,
        ) );

        $invalid_dates = array(
            '15/06/2024',    // Wrong format.
            '06-15-2024',    // Wrong format.
            'not-a-date',
            '2024-13-01',    // Invalid month.
            '2024-06-32',    // Invalid day.
        );

        foreach ( $invalid_dates as $date ) {
            $data   = array( 'appointment_date' => $date );
            $result = $this->validator->validate( $form_config, $this->wire( $data ) );

            $this->assertInstanceOf(
                'WP_Error',
                $result,
                "Date '{$date}' should fail validation"
            );
        }
    }

    /**
     * Test date field min/max validation.
     */
    public function test_date_field_min_max_validation() {
        $form_config = $this->create_form_with_field( array(
            'key'  => 'event_date',
            'type' => 'date',
            'min'  => '2024-01-01',
            'max'  => '2024-12-31',
        ) );

        // Before min.
        $data   = array( 'event_date' => '2023-12-31' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // After max.
        $data   = array( 'event_date' => '2025-01-01' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // Within range.
        $data   = array( 'event_date' => '2024-06-15' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // HIDDEN FIELD TESTS
    // =====================

    /**
     * Test hidden field value is preserved.
     */
    public function test_hidden_field_value_preserved() {
        $form_config = $this->create_form_with_field( array(
            'key'     => 'source',
            'type'    => 'hidden',
            'default' => 'website',
        ) );

        $data   = array( 'source' => 'website' );
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // MESSAGE/SECTION FIELD TESTS
    // =====================

    /**
     * Test message field is not validated (display-only).
     */
    public function test_message_field_not_validated() {
        $form_config = $this->create_form_with_field( array(
            'key'     => 'notice',
            'type'    => 'message',
            'content' => '<p>Important notice</p>',
        ) );

        $data   = array(); // No data submitted for message field.
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    /**
     * Test section field is not validated (container only).
     */
    public function test_section_field_not_validated() {
        $form_config = $this->create_form_with_field( array(
            'key'   => 'personal_info',
            'type'  => 'section',
            'label' => 'Personal Information',
        ) );

        $data   = array();
        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }

    // =====================
    // INPUT LENGTH VALIDATION
    // =====================

    /**
     * Test input length validation prevents memory exhaustion.
     */
    public function test_validate_input_lengths() {
        // Create a very long string.
        $long_string = str_repeat( 'a', 150000 );

        $data = array(
            'name' => $long_string,
        );

        $result = $this->validator->validate_input_lengths( $data, 100000 );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertEquals( 'input_too_long', $result->get_error_code() );
    }

    /**
     * Test input length validation with nested arrays.
     */
    public function test_validate_input_lengths_nested_array() {
        $long_string = str_repeat( 'a', 150000 );

        $data = array(
            'interests' => array( 'normal', $long_string ),
        );

        $result = $this->validator->validate_input_lengths( $data, 100000 );

        $this->assertInstanceOf( 'WP_Error', $result );
    }

    /**
     * Test normal input lengths pass validation.
     */
    public function test_validate_input_lengths_normal_passes() {
        $data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'message' => 'This is a normal message.',
        );

        $result = $this->validator->validate_input_lengths( $data );

        $this->assertTrue( $result );
    }

    // =====================
    // MULTIPLE FIELD VALIDATION
    // =====================

    /**
     * Test form with multiple fields validates all.
     */
    public function test_multiple_fields_all_validated() {
        $form_config = array(
            'fields' => array(
                array(
                    'key'      => 'name',
                    'type'     => 'text',
                    'required' => true,
                ),
                array(
                    'key'      => 'email',
                    'type'     => 'email',
                    'required' => true,
                ),
                array(
                    'key'      => 'message',
                    'type'     => 'textarea',
                    'required' => true,
                ),
            ),
            'settings' => array(),
        );

        // Missing name and message.
        $data = array(
            'name'    => '',
            'email'   => 'test@example.com',
            'message' => '',
        );

        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertInstanceOf( 'WP_Error', $result );

        // Check that field errors are recorded.
        $error_data = $result->get_error_data();
        $this->assertArrayHasKey( 'field_errors', $error_data );
        $this->assertArrayHasKey( 'name', $error_data['field_errors'] );
        $this->assertArrayHasKey( 'message', $error_data['field_errors'] );
    }

    /**
     * Test form with all valid fields passes.
     */
    public function test_multiple_fields_all_valid_passes() {
        $form_config = $this->load_form_fixture( 'simple-contact' );

        $data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'This is a test message.',
        );

        $result = $this->validator->validate( $form_config, $this->wire( $data ) );

        $this->assertTrue( $result );
    }
}
