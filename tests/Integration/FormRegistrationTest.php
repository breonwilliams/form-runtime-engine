<?php
/**
 * Form Registration Integration Tests.
 *
 * Tests for form registration workflow.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for form registration.
 */
class FormRegistrationTest extends IntegrationTestCase {

    /**
     * Test registering a simple form.
     */
    public function test_register_simple_form() {
        $config = $this->load_form_fixture( 'simple-contact' );

        $result = $this->register_form( 'contact', $config );

        $this->assertTrue( $result );
        $this->assertFormExists( 'contact' );
    }

    /**
     * Test registering a form with all field types.
     */
    public function test_register_form_with_all_field_types() {
        $config = array(
            'title'  => 'All Fields Form',
            'fields' => array(
                array( 'key' => 'text_field', 'type' => 'text' ),
                array( 'key' => 'email_field', 'type' => 'email' ),
                array( 'key' => 'tel_field', 'type' => 'tel' ),
                array( 'key' => 'textarea_field', 'type' => 'textarea' ),
                array(
                    'key'     => 'select_field',
                    'type'    => 'select',
                    'options' => array(
                        array( 'value' => 'a', 'label' => 'A' ),
                    ),
                ),
                array(
                    'key'     => 'radio_field',
                    'type'    => 'radio',
                    'options' => array(
                        array( 'value' => 'b', 'label' => 'B' ),
                    ),
                ),
                array( 'key' => 'checkbox_field', 'type' => 'checkbox' ),
                array( 'key' => 'file_field', 'type' => 'file' ),
                array( 'key' => 'hidden_field', 'type' => 'hidden' ),
                array( 'key' => 'message_field', 'type' => 'message', 'content' => 'Test' ),
                array( 'key' => 'section_field', 'type' => 'section' ),
                array( 'key' => 'date_field', 'type' => 'date' ),
                array( 'key' => 'address_field', 'type' => 'address' ),
            ),
        );

        $result = $this->register_form( 'all_fields', $config );

        $this->assertTrue( $result );
        $this->assertFormExists( 'all_fields' );

        // Verify all fields are registered.
        $registered = pforms_get_form( 'all_fields' );
        $this->assertCount( 13, $registered['fields'] );
    }

    /**
     * Test registering a form with duplicate field key fails.
     */
    public function test_duplicate_field_key_rejected() {
        $config = array(
            'fields' => array(
                array( 'key' => 'email', 'type' => 'email' ),
                array( 'key' => 'email', 'type' => 'text' ), // Duplicate.
            ),
        );

        $result = $this->register_form( 'duplicate', $config );

        $this->assertFalse( $result );
        $this->assertFormNotExists( 'duplicate' );
    }

    /**
     * Test registering a form with invalid field type fails.
     */
    public function test_invalid_field_type_rejected() {
        $config = array(
            'fields' => array(
                array( 'key' => 'bad_field', 'type' => 'nonexistent_type' ),
            ),
        );

        $result = $this->register_form( 'invalid_type', $config );

        $this->assertFalse( $result );
        $this->assertFormNotExists( 'invalid_type' );
    }

    /**
     * Test registered form can be retrieved with pforms_get_form().
     */
    public function test_form_exists_after_registration() {
        $config = $this->load_form_fixture( 'simple-contact' );

        $this->register_form( 'retrieve_test', $config );

        $retrieved = pforms_get_form( 'retrieve_test' );

        $this->assertIsArray( $retrieved );
        $this->assertEquals( 'Contact Us', $retrieved['title'] );
        $this->assertArrayHasKey( 'fields', $retrieved );
        $this->assertArrayHasKey( 'settings', $retrieved );
    }

    /**
     * Test form gets merged with default settings.
     */
    public function test_form_merges_with_defaults() {
        $config = array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        );

        $this->register_form( 'defaults_test', $config );

        $form = pforms_get_form( 'defaults_test' );

        // Check default settings are applied.
        $this->assertEquals( 'Submit', $form['settings']['submit_button_text'] );
        $this->assertEquals( 'Thank you for your submission.', $form['settings']['success_message'] );
        $this->assertTrue( $form['settings']['notification']['enabled'] );
        $this->assertTrue( $form['settings']['spam_protection']['honeypot'] );
        $this->assertTrue( $form['settings']['store_entries'] );
    }

    /**
     * Test multi-step form registration.
     */
    public function test_multistep_form_registration() {
        $config = $this->load_form_fixture( 'multi-step' );

        $result = $this->register_form( 'multistep', $config );

        $this->assertTrue( $result );

        $form = pforms_get_form( 'multistep' );

        $this->assertArrayHasKey( 'steps', $form );
        $this->assertCount( 3, $form['steps'] );
        $this->assertEquals( 'contact', $form['steps'][0]['key'] );
    }

    /**
     * Test form with empty ID fails.
     */
    public function test_empty_form_id_fails() {
        $config = array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        );

        $result = pforms_register_form( '', $config );

        $this->assertFalse( $result );
    }

    /**
     * Test form ID is sanitized.
     */
    public function test_form_id_sanitized() {
        $config = array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        );

        // Register with unsanitized ID.
        $result = pforms_register_form( 'Test Form ID!', $config );

        $this->assertTrue( $result );

        // Should be registered with sanitized key.
        $this->assertFormExists( 'testformid' );

        // Verify the sanitized form can be retrieved.
        $form = pforms_get_form( 'testformid' );
        $this->assertIsArray( $form );
        $this->assertArrayHasKey( 'fields', $form );
    }

    /**
     * Test form without fields array fails.
     */
    public function test_form_without_fields_fails() {
        $config = array(
            'title' => 'No Fields',
        );

        $result = $this->register_form( 'no_fields', $config );

        $this->assertFalse( $result );
    }

    /**
     * Test form with empty fields array fails.
     */
    public function test_form_with_empty_fields_fails() {
        $config = array(
            'fields' => array(),
        );

        $result = $this->register_form( 'empty_fields', $config );

        $this->assertFalse( $result );
    }

    /**
     * Test field without key fails.
     */
    public function test_field_without_key_fails() {
        $config = array(
            'fields' => array(
                array( 'type' => 'text', 'label' => 'No Key' ),
            ),
        );

        $result = $this->register_form( 'no_key', $config );

        $this->assertFalse( $result );
    }

    /**
     * Test pforms_form_registered action fires.
     */
    public function test_form_registered_action_fires() {
        $config = $this->load_form_fixture( 'simple-contact' );
        $fired  = false;

        add_action( 'pforms_form_registered', function( $form_id, $form_config ) use ( &$fired ) {
            $fired = true;
            $this->assertEquals( 'action_test', $form_id );
            $this->assertArrayHasKey( 'fields', $form_config );
        }, 10, 2 );

        $this->register_form( 'action_test', $config );

        $this->assertTrue( $fired, 'pforms_form_registered action should have fired' );
    }

    /**
     * Test registry get_all() returns all forms.
     */
    public function test_registry_get_all() {
        $config = array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        );

        $this->register_form( 'form1', $config );
        $this->register_form( 'form2', $config );
        $this->register_form( 'form3', $config );

        $all = $this->plugin->registry->get_all();

        $this->assertArrayHasKey( 'form1', $all );
        $this->assertArrayHasKey( 'form2', $all );
        $this->assertArrayHasKey( 'form3', $all );
    }

    /**
     * Test unregistering a form.
     */
    public function test_unregister_form() {
        $config = array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        );

        $this->register_form( 'to_remove', $config );
        $this->assertFormExists( 'to_remove' );

        $result = $this->plugin->registry->unregister( 'to_remove' );

        $this->assertTrue( $result );
        $this->assertFormNotExists( 'to_remove' );
    }

    /**
     * Test get_field() retrieves specific field.
     */
    public function test_get_field() {
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'field_test', $config );

        $field = $this->plugin->registry->get_field( 'field_test', 'email' );

        $this->assertIsArray( $field );
        $this->assertEquals( 'email', $field['key'] );
        $this->assertEquals( 'email', $field['type'] );
    }

    /**
     * Test get_field() returns null for nonexistent field.
     */
    public function test_get_field_nonexistent() {
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'field_test2', $config );

        $field = $this->plugin->registry->get_field( 'field_test2', 'nonexistent' );

        $this->assertNull( $field );
    }

    /**
     * Test get_settings() retrieves form settings.
     */
    public function test_get_settings() {
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'settings_test', $config );

        $settings = $this->plugin->registry->get_settings( 'settings_test' );

        $this->assertIsArray( $settings );
        $this->assertEquals( 'Send Message', $settings['submit_button_text'] );
    }

    /**
     * Test pforms_field_types filter can add custom types.
     */
    public function test_custom_field_type_filter() {
        add_filter( 'pforms_field_types', function( $types ) {
            $types[] = 'custom_type';
            return $types;
        });

        $config = array(
            'fields' => array(
                array( 'key' => 'custom', 'type' => 'custom_type' ),
            ),
        );

        $result = $this->register_form( 'custom_type_test', $config );

        // Should pass with custom type registered.
        $this->assertTrue( $result );
    }
}
