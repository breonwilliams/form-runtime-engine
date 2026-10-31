<?php
/**
 * What counts toward a form's cap (Q-001).
 *
 * The count is a number of accepted submissions, kept apart from the entry
 * rows. These tests pin what the counter itself guarantees: storing spam,
 * deleting entries and "Mark as Not Spam" leave it alone, it works with
 * store_entries off, and adding a cap to a form with entries starts it at
 * the non-spam entries stored then. And Q-010: once a form has had a cap its
 * count is kept and keeps counting while the cap is off, so a restored cap
 * resumes exactly; only deleting the form removes it.
 *
 * NOT proven here (P3): that the submission handler claims a place only on
 * the accepted path — i.e. that a honeypot-flagged submission or a dry run
 * never calls claim(). Here the spam rule is pinned at the storage level.
 *
 * Row order matters: PForms_Entry::create() runs START TRANSACTION, which
 * commits the test's transaction. Entries are therefore created before any
 * form is saved or count written, so those are still rolled back; the
 * committed rows are removed in tear_down_after_class().
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Counting rules for PForms_Place_Counter.
 */
class PlaceCountingRulesTest extends IntegrationTestCase {

    /**
     * @var \PForms_Place_Counter
     */
    private $counter;

    /**
     * @var \PForms_Entry
     */
    private $entries;

    public function set_up() {
        parent::set_up();
        $this->counter = new \PForms_Place_Counter();
        $this->entries = new \PForms_Entry();
    }

    public static function tear_down_after_class() {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'pforms\\_places\\_p2-rule-%'" );
        $ids = $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id LIKE 'p2-rule-%'" );
        foreach ( $ids as $id ) {
            $wpdb->delete( $wpdb->prefix . 'fre_entry_meta', array( 'entry_id' => (int) $id ) );
            $wpdb->delete( $wpdb->prefix . 'fre_entries', array( 'id' => (int) $id ) );
        }
        $wpdb->query( 'COMMIT' );

        parent::tear_down_after_class();
    }

    private function form_id( $name ) {
        return 'p2-rule-' . $name . '-' . strtolower( wp_generate_password( 6, false ) );
    }

    private function config_json( $availability = null, array $settings = array() ) {
        if ( null !== $availability ) {
            $settings['availability'] = $availability;
        }
        $config = array(
            'title'  => 'Camp',
            'fields' => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
        );
        if ( $settings ) {
            $config['settings'] = $settings;
        }
        return wp_json_encode( $config );
    }

    private function save( $form_id, $availability = null, array $settings = array() ) {
        $result = \PForms_Forms_Repository::save( $form_id, array( 'config' => $this->config_json( $availability, $settings ) ) );
        $this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : 'save failed' );
        // What the next request would do on pforms_init.
        $this->plugin->registry->unregister( $form_id );
        \PForms_Forms_Repository::register_all_with_runtime_registry();
        return $result;
    }

    private function store_entry( $form_id, $spam = false ) {
        $id = $this->entries->create( $form_id, array( 'email' => 'a@example.com' ), array( 'is_spam' => $spam ? 1 : 0 ) );
        $this->assertIsInt( $id );
        return $id;
    }

    // ------------------------------------------------------------------
    // Claim / release basics.
    // ------------------------------------------------------------------

    public function test_claim_succeeds_at_most_cap_times() {
        $form_id = $this->form_id( 'cap' );
        $this->counter->set( $form_id, 0 );

        $wins = 0;
        for ( $i = 0; $i < 5; $i++ ) {
            $wins += (int) $this->counter->claim( $form_id, 3 );
        }

        $this->assertSame( 3, $wins );
        $this->assertSame( 3, $this->counter->get( $form_id ) );
    }

    public function test_cap_of_zero_never_admits_and_null_cap_counts_nothing() {
        $form_id = $this->form_id( 'zero' );

        $this->assertFalse( $this->counter->claim( $form_id, 0 ) );
        $this->assertTrue( $this->counter->claim( $form_id, null ), 'Uncapped: always accepted.' );
        $this->assertFalse( $this->counter->exists( $form_id ), 'Uncapped forms keep no count.' );
    }

    public function test_release_returns_a_place_and_never_goes_below_zero() {
        $form_id = $this->form_id( 'release' );
        $this->counter->set( $form_id, 0 );

        $this->assertTrue( $this->counter->claim( $form_id, 1 ) );
        $this->assertFalse( $this->counter->claim( $form_id, 1 ) );
        $this->assertTrue( $this->counter->release( $form_id ) );
        $this->assertSame( 0, $this->counter->get( $form_id ) );
        $this->assertFalse( $this->counter->release( $form_id ), 'Nothing to give back at 0.' );
        $this->assertSame( 0, $this->counter->get( $form_id ) );
        $this->assertTrue( $this->counter->claim( $form_id, 1 ) );
    }

    public function test_set_adjusts_the_count_for_the_owner_control() {
        $form_id = $this->form_id( 'adjust' );
        $this->counter->set( $form_id, 7 );
        $this->assertSame( 7, $this->counter->get( $form_id ) );
        $this->counter->set( $form_id, 0 );
        $this->assertSame( 0, $this->counter->get( $form_id ) );
        $this->counter->set( $form_id, -4 );
        $this->assertSame( 0, $this->counter->get( $form_id ), 'Never negative.' );
    }

    // ------------------------------------------------------------------
    // Q-001 counting rules.
    // ------------------------------------------------------------------

    public function test_spam_entries_never_take_a_place() {
        $form_id = $this->form_id( 'spam' );
        $spam_id = $this->store_entry( $form_id, true );
        $real_id = $this->store_entry( $form_id );

        $this->counter->set( $form_id, 1 );

        $this->store_entry( $form_id, true ); // Commits; the row above is already set.
        $this->assertSame( 1, $this->counter->get( $form_id ), 'Storing a spam entry does not take a place.' );

        $this->entries->mark_spam( $real_id );
        $this->assertSame( 1, $this->counter->get( $form_id ), 'Marking an entry spam does not give its place back.' );
        unset( $spam_id );
    }

    public function test_mark_as_not_spam_takes_no_place_and_works_when_full() {
        $form_id = $this->form_id( 'notspam' );
        $spam_id = $this->store_entry( $form_id, true );

        $this->save( $form_id, array( 'max_entries' => 1 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 1 ) );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'], 'The form is full.' );

        $this->assertNotFalse( $this->entries->mark_not_spam( $spam_id ), 'Restoring is allowed on a full form.' );
        $this->assertSame( 0, (int) $this->entries->get( $spam_id )['is_spam'] );
        $this->assertSame( 1, $this->counter->get( $form_id ), 'A restored entry does not take a place.' );
    }

    public function test_deleting_entries_never_gives_a_place_back() {
        $form_id = $this->form_id( 'delete' );
        $first   = $this->store_entry( $form_id );
        $second  = $this->store_entry( $form_id );

        $this->save( $form_id, array( 'max_entries' => 2 ) );
        $this->assertSame( 2, $this->counter->get( $form_id ), 'Seeded from the two stored entries.' );

        $this->assertTrue( $this->entries->delete( $first ) );
        $this->assertTrue( $this->entries->delete( $second ) );

        $this->assertSame( 2, $this->counter->get( $form_id ), 'PForms_Entry::delete() leaves the count alone.' );
        $this->assertFalse( $this->counter->claim( $form_id, 2 ), 'Still full after the entries are gone.' );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'] );
    }

    public function test_store_entries_off_form_can_have_a_cap_and_counts_claims() {
        $form_id = $this->form_id( 'nostore' );
        $this->save( $form_id, array( 'max_entries' => 2 ), array( 'store_entries' => false ) );

        $this->assertSame( 0, $this->counter->get( $form_id ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );
        $this->assertFalse( $this->counter->claim( $form_id, 2 ) );

        $this->assertSame( 0, $this->entries->count( $form_id ), 'No entry rows exist.' );
        $this->assertSame( 2, $this->counter->get( $form_id ), 'The count does not depend on entry rows.' );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'] );
    }

    public function test_adding_a_cap_to_a_form_with_entries_starts_at_non_spam_entries() {
        $form_id = $this->form_id( 'seed' );
        $this->store_entry( $form_id );
        $this->store_entry( $form_id );
        $this->store_entry( $form_id );
        $this->store_entry( $form_id, true );

        $this->save( $form_id ); // Uncapped first.
        $this->assertFalse( $this->counter->exists( $form_id ), 'An uncapped form keeps no count.' );

        $this->save( $form_id, array( 'max_entries' => 5 ) );
        $this->assertTrue( $this->counter->exists( $form_id ) );
        $this->assertSame( 3, $this->counter->get( $form_id ), '3 non-spam entries; the spam one does not count.' );

        $result = \PForms_Availability::check( $form_id );
        $this->assertTrue( $result['open'] );
        $this->assertSame( 3, $result['count'], 'check() reads the real count.' );
        $this->assertSame( 5, $result['cap'] );
    }

    public function test_changing_the_cap_keeps_the_places_already_taken() {
        $form_id = $this->form_id( 'recap' );
        $this->save( $form_id, array( 'max_entries' => 2 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );

        $this->save( $form_id, array( 'max_entries' => 4 ) );
        $this->assertSame( 2, $this->counter->get( $form_id ) );
        $this->assertTrue( \PForms_Availability::check( $form_id )['open'] );
    }

    // ------------------------------------------------------------------
    // Q-010: a count, once started, is kept until the form is deleted.
    // ------------------------------------------------------------------

    public function test_cap_less_save_keeps_the_count_and_restoring_the_cap_resumes_it() {
        $form_id = $this->form_id( 'resume' );
        $first   = $this->store_entry( $form_id );
        $second  = $this->store_entry( $form_id );

        $this->save( $form_id, array( 'max_entries' => 3 ) );
        $this->assertSame( 2, $this->counter->get( $form_id ), 'Seeded from the two stored entries.' );
        $this->assertTrue( $this->counter->claim( $form_id, 3 ) );

        // The entries go; that never gives a place back.
        $this->assertTrue( $this->entries->delete( $first ) );
        $this->assertTrue( $this->entries->delete( $second ) );

        $this->save( $form_id ); // Cap removed.
        $this->assertTrue( $this->counter->exists( $form_id ), 'A save without a cap keeps the count.' );
        $this->assertSame( 3, $this->counter->get( $form_id ) );
        $this->assertTrue( \PForms_Availability::check( $form_id )['open'], 'Uncapped: open.' );

        $this->save( $form_id, array( 'max_entries' => 3 ) ); // Cap restored.
        $this->assertSame( 3, $this->counter->get( $form_id ), 'Resumes the stored count; not re-seeded from the (now zero) entries.' );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'] );
        $this->assertFalse( $this->counter->claim( $form_id, 3 ) );
    }

    public function test_submissions_accepted_while_uncapped_count_when_the_cap_returns() {
        $form_id = $this->form_id( 'uncapped' );
        $this->save( $form_id, array( 'max_entries' => 4 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 4 ) );

        $this->save( $form_id ); // Cap off.
        $this->assertTrue( $this->counter->claim( $form_id, null ), 'Uncapped: accepted.' );
        $this->assertTrue( $this->counter->claim( $form_id, null ) );
        $this->assertSame( 3, $this->counter->get( $form_id ), 'Counted while uncapped.' );
        $this->assertTrue( $this->counter->release( $form_id ), 'A failed store gives it back, capped or not.' );
        $this->assertSame( 2, $this->counter->get( $form_id ) );
        $this->assertTrue( $this->counter->claim( $form_id, null ) );

        $this->save( $form_id, array( 'max_entries' => 4 ) ); // Cap back.
        $result = \PForms_Availability::check( $form_id );
        $this->assertSame( 3, $result['count'], 'The uncapped submissions are in the restored count.' );
        $this->assertTrue( $result['open'] );
        $this->assertTrue( $this->counter->claim( $form_id, 4 ) );
        $this->assertFalse( $this->counter->claim( $form_id, 4 ) );
    }

    public function test_connector_update_whose_config_omits_availability_keeps_the_count() {
        $form_id = $this->form_id( 'patch' );
        $this->save( $form_id, array( 'max_entries' => 2 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );

        $request = new \WP_REST_Request( 'PATCH', '/fre/v1/connector/forms/' . $form_id );
        $request->set_param( 'form_id', $form_id );
        $request->set_param( 'config', $this->config_json() ); // Replaces config wholesale; no availability.
        $response = ( new \PForms_Connector_API() )->handle_update_form( $request );
        $this->assertNotWPError( $response );
        $this->assertStringNotContainsString( 'availability', \PForms_Forms_Repository::get( $form_id )['config'] );

        $this->assertTrue( $this->counter->exists( $form_id ), 'The connector update did not erase the count.' );
        $this->assertSame( 1, $this->counter->get( $form_id ) );
    }

    public function test_deleting_the_form_removes_the_count_and_a_recreated_form_is_seeded_afresh() {
        $form_id = $this->form_id( 'delform' );
        $this->store_entry( $form_id );

        $this->save( $form_id, array( 'max_entries' => 5 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 5 ) );
        $this->assertTrue( $this->counter->claim( $form_id, 5 ) );
        $this->assertSame( 3, $this->counter->get( $form_id ) );

        $this->assertTrue( \PForms_Forms_Repository::delete( $form_id ) );
        $this->assertFalse( $this->counter->exists( $form_id ), 'Deleting the form removes its count.' );

        // Entries are not cascade-deleted with the form; a new form under the
        // same ID starts at its stored non-spam entries (Q-001(a)).
        $this->save( $form_id, array( 'max_entries' => 5 ) );
        $this->assertSame( 1, $this->counter->get( $form_id ) );
    }

    public function test_never_capped_form_keeps_no_count_and_counting_it_costs_one_statement() {
        global $wpdb;
        $form_id = $this->form_id( 'never' );
        $this->save( $form_id );

        $before = $wpdb->num_queries;
        $this->assertTrue( $this->counter->claim( $form_id, null ) );
        $this->assertSame( 1, $wpdb->num_queries - $before, 'One statement (a 0-row UPDATE by unique key), no read.' );

        $this->assertFalse( $this->counter->exists( $form_id ), 'Nothing is created for a form that never had a cap.' );
        $this->assertFalse( $this->counter->release( $form_id ) );
    }

    public function test_php_registered_form_cap_removed_in_code_keeps_and_resumes_its_count() {
        $form_id = $this->form_id( 'phpresume' );
        $fields  = array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) );
        $capped  = array( 'fields' => $fields, 'settings' => array( 'availability' => array( 'max_entries' => 2 ) ) );

        $this->assertTrue( pforms_register_form( $form_id, $capped ) );
        $this->assertTrue( $this->counter->claim( $form_id, 2 ) );

        // Next deploy: cap removed in code. Same rule as a DB form.
        $this->plugin->registry->unregister( $form_id );
        $this->assertTrue( pforms_register_form( $form_id, array( 'fields' => $fields ) ) );
        $this->assertTrue( $this->counter->claim( $form_id, null ) );
        $this->assertSame( 2, $this->counter->get( $form_id ), 'Counted while uncapped.' );

        // Cap back in code: resumes, full.
        $this->plugin->registry->unregister( $form_id );
        $this->assertTrue( pforms_register_form( $form_id, $capped ) );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'] );
        $this->assertFalse( $this->counter->claim( $form_id, 2 ) );
    }

    public function test_check_reads_the_count_for_a_php_registered_capped_form() {
        $form_id = $this->form_id( 'php' );
        $this->assertTrue( pforms_register_form( $form_id, array(
            'fields'   => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
            'settings' => array( 'availability' => array( 'max_entries' => 1 ) ),
        ) ) );

        $this->assertTrue( \PForms_Availability::check( $form_id )['open'] );
        $this->assertTrue( $this->counter->claim( $form_id, 1 ), 'First claim creates the row and takes the place.' );
        $this->assertSame( 'cap_reached', \PForms_Availability::check( $form_id )['reason'] );
    }

    public function test_count_is_never_read_or_written_through_the_options_or_cache_api() {
        $form_id = $this->form_id( 'api' );
        $name    = \PForms_Place_Counter::option_name( $form_id );
        $touched = array();
        $spy     = function ( $value ) use ( &$touched ) {
            $touched[] = current_filter();
            return $value;
        };
        foreach ( array( "pre_option_{$name}", "option_{$name}", "pre_update_option_{$name}", "pre_transient_{$name}" ) as $hook ) {
            add_filter( $hook, $spy );
        }

        $this->counter->set( $form_id, 0 );
        $this->counter->claim( $form_id, 2 );
        $this->counter->release( $form_id );
        $this->counter->get( $form_id );

        $this->assertSame( array(), $touched );
        $this->assertFalse( wp_cache_get( $name, 'options' ) );
        $this->assertSame( 'no', $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare(
            "SELECT autoload FROM {$GLOBALS['wpdb']->options} WHERE option_name = %s",
            $name
        ) ), 'Not autoloaded, so never in the alloptions cache.' );
    }
}
