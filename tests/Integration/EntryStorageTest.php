<?php
/**
 * Entry Storage Integration Tests.
 *
 * Tests for entry CRUD operations.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Tests for entry storage.
 */
class EntryStorageTest extends IntegrationTestCase {

    /**
     * Entry repository instance.
     *
     * @var \PForms_Entry
     */
    private $entry_repo;

    /**
     * Set up before each test.
     */
    public function set_up() {
        parent::set_up();

        $this->entry_repo = new \PForms_Entry();

        // Register a test form.
        $config = $this->load_form_fixture( 'simple-contact' );
        $this->register_form( 'contact', $config );

        // Clean up entries.
        $this->clean_entries();
    }

    /**
     * Test entry created with correct data.
     */
    public function test_entry_created_with_correct_data() {
        $data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'phone'   => '555-123-4567',
            'message' => 'Test message.',
        );

        $entry_id = $this->entry_repo->create( 'contact', $data );

        $this->assertIsInt( $entry_id );
        $this->assertGreaterThan( 0, $entry_id );

        // Retrieve and verify.
        $entry = $this->entry_repo->get( $entry_id );

        $this->assertEquals( 'contact', $entry['form_id'] );
        $this->assertEquals( 'unread', $entry['status'] );
        $this->assertEquals( 'John Doe', $entry['fields']['name'] );
        $this->assertEquals( 'john@example.com', $entry['fields']['email'] );
        $this->assertEquals( '555-123-4567', $entry['fields']['phone'] );
        $this->assertEquals( 'Test message.', $entry['fields']['message'] );
    }

    /**
     * Test entry retrieve by ID.
     */
    public function test_entry_retrieve_by_id() {
        $data = array( 'name' => 'Test User', 'email' => 'test@example.com' );

        $entry_id = $this->entry_repo->create( 'contact', $data );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertNotNull( $entry );
        $this->assertEquals( $entry_id, $entry['id'] );
    }

    /**
     * Test retrieving nonexistent entry returns null.
     */
    public function test_retrieve_nonexistent_entry() {
        $entry = $this->entry_repo->get( 999999 );

        $this->assertNull( $entry );
    }

    /**
     * Test entry count.
     */
    public function test_entry_count() {
        // Create 3 entries.
        for ( $i = 0; $i < 3; $i++ ) {
            $this->entry_repo->create( 'contact', array(
                'name'  => "User {$i}",
                'email' => "user{$i}@example.com",
            ) );
        }

        $count = $this->entry_repo->count( 'contact' );

        $this->assertEquals( 3, $count );
    }

    /**
     * Test entry count by status.
     */
    public function test_entry_count_by_status() {
        // Create entries.
        $entry1 = $this->entry_repo->create( 'contact', array( 'name' => 'User 1' ) );
        $entry2 = $this->entry_repo->create( 'contact', array( 'name' => 'User 2' ) );
        $entry3 = $this->entry_repo->create( 'contact', array( 'name' => 'User 3' ) );

        // Mark one as read.
        $this->entry_repo->mark_read( $entry1 );

        $unread_count = $this->entry_repo->count( 'contact', 'unread' );
        $read_count   = $this->entry_repo->count( 'contact', 'read' );

        $this->assertEquals( 2, $unread_count );
        $this->assertEquals( 1, $read_count );
    }

    /**
     * Test entry delete.
     */
    public function test_entry_delete() {
        $entry_id = $this->entry_repo->create( 'contact', array(
            'name'  => 'Delete Me',
            'email' => 'delete@example.com',
        ) );

        $result = $this->entry_repo->delete( $entry_id );

        $this->assertTrue( $result );

        // Verify deleted.
        $entry = $this->entry_repo->get( $entry_id );
        $this->assertNull( $entry );
    }

    /**
     * Test bulk delete (via loop).
     */
    public function test_bulk_delete() {
        $entry_ids = array();

        // Create 5 entries.
        for ( $i = 0; $i < 5; $i++ ) {
            $entry_ids[] = $this->entry_repo->create( 'contact', array(
                'name' => "User {$i}",
            ) );
        }

        $initial_count = $this->entry_repo->count( 'contact' );
        $this->assertEquals( 5, $initial_count );

        // Delete first 3.
        for ( $i = 0; $i < 3; $i++ ) {
            $this->entry_repo->delete( $entry_ids[ $i ] );
        }

        $final_count = $this->entry_repo->count( 'contact' );
        $this->assertEquals( 2, $final_count );
    }

    /**
     * Test mark entry as read.
     */
    public function test_mark_read() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );

        $this->entry_repo->mark_read( $entry_id );

        $entry = $this->entry_repo->get( $entry_id );
        $this->assertEquals( 'read', $entry['status'] );
    }

    /**
     * Test mark entry as unread.
     */
    public function test_mark_unread() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );

        // First mark as read, then unread.
        $this->entry_repo->mark_read( $entry_id );
        $this->entry_repo->mark_unread( $entry_id );

        $entry = $this->entry_repo->get( $entry_id );
        $this->assertEquals( 'unread', $entry['status'] );
    }

    /**
     * Test mark entry as spam.
     */
    public function test_mark_spam() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Spam' ) );

        $this->entry_repo->mark_spam( $entry_id );

        $entry = $this->entry_repo->get( $entry_id );
        $this->assertEquals( 1, $entry['is_spam'] );
    }

    /**
     * Test entry update.
     */
    public function test_entry_update() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );

        $result = $this->entry_repo->update( $entry_id, array(
            'status' => 'read',
        ) );

        $this->assertTrue( $result );

        $entry = $this->entry_repo->get( $entry_id );
        $this->assertEquals( 'read', $entry['status'] );
    }

    /**
     * Test update only allows whitelisted columns.
     */
    public function test_update_whitelist() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );

        // Try to update non-whitelisted column.
        $result = $this->entry_repo->update( $entry_id, array(
            'form_id' => 'hacked',
        ) );

        $this->assertFalse( $result );

        // Verify form_id unchanged.
        $entry = $this->entry_repo->get( $entry_id );
        $this->assertEquals( 'contact', $entry['form_id'] );
    }

    /**
     * Test add meta.
     */
    public function test_add_meta() {
        $entry_id = $this->entry_repo->create( 'contact', array() );

        $result = $this->entry_repo->add_meta( $entry_id, 'custom_field', 'custom_value' );

        $this->assertIsInt( $result );

        $value = $this->entry_repo->get_meta( $entry_id, 'custom_field' );
        $this->assertEquals( 'custom_value', $value );
    }

    /**
     * Test get meta returns null for nonexistent key.
     */
    public function test_get_meta_nonexistent() {
        $entry_id = $this->entry_repo->create( 'contact', array() );

        $value = $this->entry_repo->get_meta( $entry_id, 'nonexistent' );

        $this->assertNull( $value );
    }

    /**
     * Test get all meta.
     */
    public function test_get_all_meta() {
        $data = array(
            'field1' => 'value1',
            'field2' => 'value2',
            'field3' => 'value3',
        );

        $entry_id = $this->entry_repo->create( 'contact', $data );
        $all_meta = $this->entry_repo->get_all_meta( $entry_id );

        $this->assertArrayHasKey( 'field1', $all_meta );
        $this->assertArrayHasKey( 'field2', $all_meta );
        $this->assertArrayHasKey( 'field3', $all_meta );
    }

    /**
     * Test serialized array values.
     */
    public function test_serialized_array_values() {
        $data = array(
            'interests' => array( 'tech', 'design', 'music' ),
        );

        $entry_id = $this->entry_repo->create( 'contact', $data );
        $value    = $this->entry_repo->get_meta( $entry_id, 'interests' );

        $this->assertIsArray( $value );
        $this->assertContains( 'tech', $value );
        $this->assertContains( 'design', $value );
        $this->assertContains( 'music', $value );
    }

    /**
     * Test entry captures IP address.
     */
    public function test_entry_captures_ip() {
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';

        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertEquals( '192.168.1.100', $entry['ip_address'] );
    }

    /**
     * Test entry captures user agent.
     */
    public function test_entry_captures_user_agent() {
        $_SERVER['HTTP_USER_AGENT'] = 'Test Browser/1.0';

        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertEquals( 'Test Browser/1.0', $entry['user_agent'] );
    }

    /**
     * Test entry captures logged-in user ID.
     */
    public function test_entry_captures_user_id() {
        // Create and log in a user.
        $user_id = $this->factory->user->create();
        wp_set_current_user( $user_id );

        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertEquals( $user_id, $entry['user_id'] );

        wp_set_current_user( 0 );
    }

    /**
     * Test entry with no user has null user_id.
     */
    public function test_entry_anonymous_user() {
        wp_set_current_user( 0 );

        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertNull( $entry['user_id'] );
    }

    /**
     * Test created_at and updated_at timestamps.
     */
    public function test_entry_timestamps() {
        $entry_id = $this->entry_repo->create( 'contact', array( 'name' => 'Test' ) );
        $entry    = $this->entry_repo->get( $entry_id );

        $this->assertNotEmpty( $entry['created_at'] );
        $this->assertNotEmpty( $entry['updated_at'] );

        // Should be valid datetime.
        $created = strtotime( $entry['created_at'] );
        $this->assertNotFalse( $created );
    }

    /**
     * Test entry query by form.
     */
    public function test_entry_query_by_form() {
        // Register another form.
        $this->register_form( 'other_form', array(
            'fields' => array(
                array( 'key' => 'name', 'type' => 'text' ),
            ),
        ) );

        // Create entries for different forms.
        $this->entry_repo->create( 'contact', array( 'name' => 'Contact 1' ) );
        $this->entry_repo->create( 'contact', array( 'name' => 'Contact 2' ) );
        $this->entry_repo->create( 'other_form', array( 'name' => 'Other 1' ) );

        $contact_count = $this->entry_repo->count( 'contact' );
        $other_count   = $this->entry_repo->count( 'other_form' );

        $this->assertEquals( 2, $contact_count );
        $this->assertEquals( 1, $other_count );
    }

    /**
     * Test transaction rollback on meta failure.
     *
     * Note: This is hard to test without mocking the database, but we can
     * verify that entries with valid data succeed in a transaction.
     */
    public function test_transaction_success() {
        $data = array(
            'field1' => 'value1',
            'field2' => 'value2',
            'field3' => 'value3',
            'field4' => 'value4',
            'field5' => 'value5',
        );

        $entry_id = $this->entry_repo->create( 'contact', $data );

        // All fields should be stored.
        $entry = $this->entry_repo->get( $entry_id );

        $this->assertCount( 5, $entry['fields'] );
    }

    /**
     * Test duplicate detection with SHA-256 hash.
     */
    public function test_duplicate_detection_hash() {
        $data = array(
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'message' => 'Test message.',
        );

        // First submission - not duplicate.
        $is_duplicate = $this->entry_repo->is_duplicate( 'contact', $data );
        $this->assertFalse( $is_duplicate );

        // Same data - should be duplicate.
        $is_duplicate = $this->entry_repo->is_duplicate( 'contact', $data );
        $this->assertTrue( $is_duplicate );

        // Different data - not duplicate.
        $data['message'] = 'Different message.';
        $is_duplicate    = $this->entry_repo->is_duplicate( 'contact', $data );
        $this->assertFalse( $is_duplicate );
    }
}
