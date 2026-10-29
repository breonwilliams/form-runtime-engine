<?php
/**
 * A closed form is refused on both submission paths, before any side effect.
 *
 * Both paths are driven for real: the AJAX handler (handle_submission(), the
 * visitor's path, with $_POST / $_FILES and wp_send_json) and the programmatic
 * one (process_submission(), the connector's test submit). Every test form is
 * saved like an admin form, with a webhook, and notifications on, so a
 * refused submission that leaked would show up as a stored row, a mail, an
 * HTTP request or a fired action — each of which is asserted separately.
 *
 * Row order: PForms_Entry::create() runs START TRANSACTION, which commits the
 * test's transaction, so rows are removed explicitly in tear_down().
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Thrown by the test's wp_die handler where wp_send_json() would end the
 * request. An \Error, not an \Exception, so the handler's own
 * catch ( Exception ) cannot swallow it and answer a second time.
 */
class AjaxExit extends \Error {}

/**
 * Availability enforcement in PForms_Submission_Handler.
 */
class AvailabilityEnforcementTest extends IntegrationTestCase {

    const WEBHOOK_URL = 'https://hooks.example.com/p3-availability';

    const PNG_1X1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQI12NgYGAAAAAEAAEnNCcKAAAAAElFTkSuQmCC';

    /** @var array Mails wp_mail() was asked to send. */
    private $mails = array();

    /** @var array HTTP requests made (the webhook). */
    private $http = array();

    /** @var array<string,int> How often each watched action fired. */
    private $fired = array();

    /** @var array Arguments of each pforms_form_full call. */
    private $full_calls = array();

    /** @var string[] Form IDs created by the running test. */
    private $forms = array();

    /** @var string[] Temporary files to remove. */
    private $tmp_files = array();

    public function set_up() {
        parent::set_up();

        $this->mails      = array();
        $this->http       = array();
        $this->full_calls = array();
        $this->fired      = array(
            'pforms_entry_created'       => 0,
            'pforms_submission_complete' => 0,
            'pforms_form_full'           => 0,
            'pforms_webhook_sent'        => 0,
            'pforms_webhook_failed'      => 0,
        );

        \PForms_Webhook_Dispatcher::init();

        add_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10, 2 );
        add_filter( 'pre_http_request', array( $this, 'capture_http' ), 10, 3 );
        foreach ( array_keys( $this->fired ) as $hook ) {
            add_action( $hook, array( $this, 'count_' . $hook ), 1, 4 );
        }
        add_action( 'pforms_form_full', array( $this, 'record_full' ), 10, 4 );

        // wp_send_json() ends an AJAX request through wp_die().
        add_filter( 'wp_doing_ajax', '__return_true' );
        add_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );

        $_POST    = array();
        $_REQUEST = array();
        $_FILES   = array();
    }

    public function tear_down() {
        global $wpdb;

        remove_filter( 'pre_wp_mail', array( $this, 'capture_mail' ), 10 );
        remove_filter( 'pre_http_request', array( $this, 'capture_http' ), 10 );
        foreach ( array_keys( $this->fired ) as $hook ) {
            remove_action( $hook, array( $this, 'count_' . $hook ), 1 );
        }
        remove_action( 'pforms_form_full', array( $this, 'record_full' ), 10 );
        remove_filter( 'wp_doing_ajax', '__return_true' );
        remove_filter( 'wp_die_ajax_handler', array( $this, 'ajax_die_handler' ) );
        remove_all_filters( 'pforms_form_availability' );

        foreach ( $this->forms as $form_id ) {
            $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}fre_entries WHERE form_id = %s", $form_id ) );
            foreach ( $ids as $id ) {
                $wpdb->delete( $wpdb->prefix . 'fre_entry_meta', array( 'entry_id' => (int) $id ) );
                $wpdb->delete( $wpdb->prefix . 'fre_entry_files', array( 'entry_id' => (int) $id ) );
                $wpdb->delete( $wpdb->prefix . 'fre_entries', array( 'id' => (int) $id ) );
            }
            $wpdb->delete( $wpdb->prefix . 'fre_webhook_log', array( 'form_id' => $form_id ) );
            \PForms_Forms_Repository::delete( $form_id );
            ( new \PForms_Place_Counter() )->delete( $form_id );
        }
        $wpdb->query( 'COMMIT' );

        foreach ( $this->tmp_files as $file ) {
            if ( file_exists( $file ) ) {
                unlink( $file );
            }
        }

        $_POST    = array();
        $_REQUEST = array();
        $_FILES   = array();

        parent::tear_down();
    }

    // ------------------------------------------------------------------
    // Hook spies.
    // ------------------------------------------------------------------

    public function capture_mail( $return, $atts ) {
        $this->mails[] = $atts;
        return true;
    }

    public function capture_http( $preempt, $args, $url ) {
        $this->http[] = $url;
        return array(
            'headers'  => array(),
            'body'     => '{"ok":true}',
            'response' => array( 'code' => 200, 'message' => 'OK' ),
            'cookies'  => array(),
        );
    }

    public function count_pforms_entry_created() {
        $this->fired['pforms_entry_created']++;
    }

    public function count_pforms_submission_complete() {
        $this->fired['pforms_submission_complete']++;
    }

    public function count_pforms_form_full() {
        $this->fired['pforms_form_full']++;
    }

    public function count_pforms_webhook_sent() {
        $this->fired['pforms_webhook_sent']++;
    }

    public function count_pforms_webhook_failed() {
        $this->fired['pforms_webhook_failed']++;
    }

    public function record_full( $form_id, $entry_id, $cap, $form_config ) {
        $this->full_calls[] = array(
            'form_id'       => $form_id,
            'entry_id'      => $entry_id,
            'cap'           => $cap,
            'is_config'     => is_array( $form_config ),
            'entry_stored'  => $entry_id ? null !== ( new \PForms_Entry() )->get( $entry_id ) : null,
            'count_at_fire' => ( new \PForms_Place_Counter() )->get( $form_id ),
        );
    }

    public function ajax_die_handler() {
        return function () {
            throw new AjaxExit( 'wp_send_json' );
        };
    }

    // ------------------------------------------------------------------
    // Helpers.
    // ------------------------------------------------------------------

    /**
     * Save a form like the admin does (with a webhook), and register it.
     *
     * @param string     $name         Readable part of the ID.
     * @param array|null $availability settings.availability, null for none.
     * @param array      $settings     Other settings.
     * @param array|null $fields       Fields; default name (required) + email (required).
     * @return string Form ID.
     */
    private function make_form( $name, $availability = null, array $settings = array(), $fields = null ) {
        $form_id = 'p3-' . $name . '-' . strtolower( wp_generate_password( 6, false ) );

        $settings = array_merge(
            array(
                'spam_protection' => array(
                    'honeypot'     => false,
                    'timing_check' => false,
                    'rate_limit'   => array(),
                ),
                'notification'    => array( 'enabled' => true, 'to' => 'owner@example.com' ),
            ),
            $settings
        );
        if ( null !== $availability ) {
            $settings['availability'] = $availability;
        }

        $config = array(
            'title'    => 'Camp',
            'fields'   => null !== $fields ? $fields : array(
                array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
                array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            ),
            'settings' => $settings,
        );

        $saved = \PForms_Forms_Repository::save( $form_id, array(
            'title'           => 'Camp',
            'config'          => wp_json_encode( $config ),
            'webhook_enabled' => true,
            'webhook_url'     => self::WEBHOOK_URL,
        ) );
        $this->assertIsArray( $saved, is_wp_error( $saved ) ? $saved->get_error_message() : 'save failed' );
        $this->forms[] = $form_id;

        $this->plugin->registry->unregister( $form_id );
        $this->assertTrue( pforms_register_form( $form_id, $config ) );

        return $form_id;
    }

    private function valid() {
        return array( 'name' => 'Maya Chen', 'email' => 'maya-' . wp_generate_password( 6, false ) . '@example.com' );
    }

    /**
     * One visitor request through handle_submission().
     *
     * @return array The decoded JSON response.
     */
    private function ajax( $form_id, array $fields, array $extra = array(), array $files = array() ) {
        $post = array(
            'pforms_form_id' => $form_id,
            '_wpnonce'       => wp_create_nonce( 'pforms_submit_' . $form_id ),
        );
        foreach ( $fields as $key => $value ) {
            $post[ 'pforms_field_' . $key ] = $value;
        }
        $_POST    = array_merge( $post, $extra );
        $_REQUEST = $_POST;
        $_FILES   = $files;

        // The site-wide per-IP limit (20 an hour) is always on and counts
        // every request from this one test IP, across test classes. It is
        // not what these tests are about, so each request starts under it.
        delete_transient( 'pforms_global_ip_' . md5( ( new \PForms_Rate_Limiter() )->get_client_ip() ) );

        $exited = false;
        ob_start();
        try {
            ( new \PForms_Submission_Handler() )->handle_submission();
        } catch ( AjaxExit $e ) {
            $exited = true;
        } finally {
            $output = ob_get_clean();
        }

        $this->assertTrue( $exited, 'handle_submission() answers through wp_send_json().' );
        $json = json_decode( $output, true );
        $this->assertIsArray( $json, 'Response was: ' . $output );

        return $json;
    }

    /**
     * One connector request through process_submission().
     */
    private function connector( $form_id, array $data, array $options = array() ) {
        return ( new \PForms_Submission_Handler() )->process_submission( $form_id, $data, $options );
    }

    private function entries( $form_id, $spam = null ) {
        global $wpdb;
        $sql = $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fre_entries WHERE form_id = %s", $form_id );
        if ( null !== $spam ) {
            $sql .= $spam ? ' AND is_spam = 1' : ' AND is_spam = 0';
        }
        return (int) $wpdb->get_var( $sql );
    }

    private function table_rows( $table ) {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}" );
    }

    private function places( $form_id ) {
        return ( new \PForms_Place_Counter() )->get( $form_id );
    }

    /**
     * Every file under uploads/ (including quarantine folders).
     */
    private function files_on_disk() {
        $base = wp_upload_dir()['basedir'];
        if ( ! is_dir( $base ) ) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $base, \FilesystemIterator::SKIP_DOTS ) );
        foreach ( $it as $file ) {
            if ( $file->isFile() ) {
                $n++;
            }
        }
        return $n;
    }

    /**
     * Everything a refused submission must not have touched.
     */
    private function side_effects( $form_id ) {
        return array(
            'entries'          => $this->entries( $form_id ),
            'entry_meta_rows'  => $this->table_rows( 'fre_entry_meta' ),
            'entry_file_rows'  => $this->table_rows( 'fre_entry_files' ),
            'files_on_disk'    => $this->files_on_disk(),
            'attachments'      => (int) wp_count_posts( 'attachment' )->inherit + (int) wp_count_posts( 'attachment' )->private,
            'mails'            => count( $this->mails ),
            'http_requests'    => count( $this->http ),
            'webhook_log_rows' => $this->table_rows( 'fre_webhook_log' ),
            'actions'          => $this->fired,
            'count'            => $this->places( $form_id ),
        );
    }

    /**
     * A $_FILES entry for a real 1x1 PNG. It passes every pre-storage check,
     * but is not an HTTP upload, so storing it (is_uploaded_file()) is refused
     * after the entry row exists — a "refused upload" at the late stage.
     */
    private function png_upload() {
        $tmp = tempnam( sys_get_temp_dir(), 'p3png' );
        file_put_contents( $tmp, base64_decode( self::PNG_1X1 ) );
        $this->tmp_files[] = $tmp;

        return array(
            'pforms_file_artwork' => array(
                'name'     => 'artwork.png',
                'type'     => 'image/png',
                'tmp_name' => $tmp,
                'error'    => UPLOAD_ERR_OK,
                'size'     => filesize( $tmp ),
            ),
        );
    }

    private function fields_with_file() {
        return array(
            array( 'key' => 'name', 'type' => 'text', 'label' => 'Name', 'required' => true ),
            array( 'key' => 'email', 'type' => 'email', 'label' => 'Email', 'required' => true ),
            array( 'key' => 'artwork', 'type' => 'file', 'label' => 'Artwork', 'allowed_types' => array( 'png' ) ),
        );
    }

    /**
     * Make the next INSERT into the entries table fail, as a database error.
     */
    private function break_entry_insert() {
        global $wpdb;
        $table = $wpdb->prefix . 'fre_entries';
        $wpdb->suppress_errors( true );
        add_filter( 'query', $filter = function ( $query ) use ( $table, &$filter ) {
            if ( preg_match( '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $table, '/' ) . '`?\s/i', $query ) ) {
                remove_filter( 'query', $filter );
                return 'SELECT p3_forced_failure FROM p3_no_such_table';
            }
            return $query;
        } );
    }

    // ------------------------------------------------------------------
    // Positive control: an open form DOES produce every side effect, so the
    // "nothing happened" assertions below can fail.
    // ------------------------------------------------------------------

    public function test_control_accepted_submission_produces_every_watched_side_effect() {
        $form_id = $this->make_form( 'control', array( 'max_entries' => 5 ) );
        $before  = $this->side_effects( $form_id );

        $response = $this->ajax( $form_id, $this->valid() );
        $this->assertTrue( $response['success'], wp_json_encode( $response ) );

        $after = $this->side_effects( $form_id );
        $this->assertSame( $before['entries'] + 1, $after['entries'] );
        $this->assertGreaterThan( $before['entry_meta_rows'], $after['entry_meta_rows'] );
        $this->assertGreaterThan( $before['mails'], $after['mails'], 'Notification sent.' );
        $this->assertGreaterThan( $before['http_requests'], $after['http_requests'], 'Webhook sent.' );
        $this->assertSame( 1, $after['actions']['pforms_entry_created'] );
        $this->assertSame( 1, $after['actions']['pforms_submission_complete'] );
        $this->assertSame( 1, $after['actions']['pforms_webhook_sent'] );
        $this->assertSame( 1, $after['count'], 'The accepted submission took a place.' );

        $result = $this->connector( $form_id, $this->valid() );
        $this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_message() : '' );
        $this->assertGreaterThan( 0, $result['entry_id'] );
        $this->assertSame( 2, $this->places( $form_id ), 'A real connector submission counts (Q-001(b)).' );
        $this->assertSame( 2, $this->fired['pforms_submission_complete'] );
    }

    // ------------------------------------------------------------------
    // Closed: nothing stored, sent or fired; the closed message and code.
    // ------------------------------------------------------------------

    /**
     * @return array<string,array>
     */
    public function closed_forms() {
        return array(
            'before opening' => array( array( 'opens_at' => '2099-01-01 09:00', 'before_open_message' => 'Registration opens in 2099.' ), 'not_yet_open', 'Registration opens in 2099.', 0 ),
            'past close'     => array( array( 'closes_at' => '2020-01-01', 'closed_message' => 'Registration has closed.' ), 'closed_date', 'Registration has closed.', 0 ),
            'full'           => array( array( 'max_entries' => 2, 'closed_message' => 'Registration has closed.' ), 'cap_reached', 'Registration has closed.', 2 ),
            'zero places'    => array( array( 'max_entries' => 0 ), 'cap_reached', 'This form is no longer accepting submissions.', 0 ),
        );
    }

    /**
     * @dataProvider closed_forms
     */
    public function test_ajax_closed_form_is_refused_with_no_side_effect( $availability, $reason, $message, $taken ) {
        $form_id = $this->make_form( 'ajax-closed', $availability, array(), $this->fields_with_file() );
        if ( $taken ) {
            ( new \PForms_Place_Counter() )->set( $form_id, $taken );
        }
        $before = $this->side_effects( $form_id );

        $response = $this->ajax( $form_id, $this->valid(), array(), $this->png_upload() );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 'form_closed', $response['data']['code'] );
        $this->assertSame( $reason, $response['data']['reason'] );
        $this->assertSame( $message, $response['data']['message'] );
        $this->assertSame( $before, $this->side_effects( $form_id ), 'No entry, meta, file row, file, attachment, mail, webhook, action or place.' );
        $this->assertSame( 0, $this->fired['pforms_entry_created'] );
        $this->assertSame( 0, $this->fired['pforms_submission_complete'] );
        $this->assertSame( array(), $this->http );
        $this->assertSame( array(), $this->mails );
    }

    /**
     * @dataProvider closed_forms
     */
    public function test_connector_closed_form_is_refused_with_no_side_effect( $availability, $reason, $message, $taken ) {
        $form_id = $this->make_form( 'conn-closed', $availability );
        if ( $taken ) {
            ( new \PForms_Place_Counter() )->set( $form_id, $taken );
        }
        $before = $this->side_effects( $form_id );

        $result = $this->connector( $form_id, $this->valid() );

        $this->assertInstanceOf( 'WP_Error', $result );
        $this->assertSame( 'form_closed', $result->get_error_code() );
        $this->assertSame( $message, $result->get_error_message() );
        $data = $result->get_error_data();
        $this->assertSame( $reason, $data['reason'] );
        $this->assertSame( $form_id, $data['form_id'] );
        $this->assertSame( $before, $this->side_effects( $form_id ) );
        $this->assertSame( 0, $this->fired['pforms_submission_complete'] );
    }

    public function test_before_open_default_message_is_the_dated_one_not_a_generic_error() {
        $form_id  = $this->make_form( 'ajax-default-open', array( 'opens_at' => '2099-03-15 09:00' ) );
        $expected = \PForms_Availability::check( $form_id )['message'];
        $this->assertStringContainsString( '2099', $expected );

        $response = $this->ajax( $form_id, $this->valid() );
        $this->assertSame( 'not_yet_open', $response['data']['reason'] );
        $this->assertSame( $expected, $response['data']['message'] );

        $result = $this->connector( $form_id, $this->valid() );
        $this->assertSame( $expected, $result->get_error_message() );
    }

    public function test_php_form_with_invalid_availability_settings_is_refused_on_both_paths() {
        // Only code can register these (both save paths refuse them); P1
        // fails them closed, and so does every submission.
        $form_id       = 'p3-badphp-' . strtolower( wp_generate_password( 6, false ) );
        $this->forms[] = $form_id;
        $this->assertTrue( pforms_register_form( $form_id, array(
            'fields'   => array( array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ) ),
            'settings' => array(
                'spam_protection' => array( 'honeypot' => false, 'timing_check' => false ),
                'availability'    => array( 'max_entries' => 'unlimited' ),
            ),
        ) ) );
        $before = $this->side_effects( $form_id );

        $response = $this->ajax( $form_id, array( 'name' => 'Maya' ) );
        $this->assertSame( 'form_closed', $response['data']['code'] );
        $this->assertSame( 'invalid_settings', $response['data']['reason'] );

        $result = $this->connector( $form_id, array( 'name' => 'Maya' ) );
        $this->assertSame( 'form_closed', $result->get_error_code() );
        $this->assertSame( 'invalid_settings', $result->get_error_data()['reason'] );

        $this->assertSame( $before, $this->side_effects( $form_id ) );
    }

    // ------------------------------------------------------------------
    // Closed beats invalid.
    // ------------------------------------------------------------------

    public function test_closed_form_with_invalid_fields_is_told_it_is_closed_on_both_paths() {
        $form_id = $this->make_form( 'invalid', array( 'closes_at' => '2020-01-01' ) );
        $before  = $this->side_effects( $form_id );
        $invalid = array( 'name' => '', 'email' => 'not-an-email' );

        $response = $this->ajax( $form_id, $invalid );
        $this->assertSame( 'form_closed', $response['data']['code'] );
        $this->assertArrayNotHasKey( 'field_errors', $response['data'] );

        $result = $this->connector( $form_id, $invalid );
        $this->assertSame( 'form_closed', $result->get_error_code() );

        $this->assertSame( $before, $this->side_effects( $form_id ) );
    }

    // ------------------------------------------------------------------
    // check() was stale: the claim has the last word.
    // ------------------------------------------------------------------

    public function test_claim_refused_after_a_stale_open_check_gets_the_closed_refusal_on_both_paths() {
        $form_id = $this->make_form( 'stale', array( 'max_entries' => 1, 'closed_message' => 'Sold out.' ), array(), $this->fields_with_file() );
        ( new \PForms_Place_Counter() )->set( $form_id, 1 );

        // What a check() made a moment before another request took the last
        // place reports: open, with the cap.
        add_filter( 'pforms_form_availability', function ( $result ) {
            $result['open']    = true;
            $result['reason']  = 'open';
            $result['message'] = '';
            return $result;
        } );
        $before = $this->side_effects( $form_id );

        $response = $this->ajax( $form_id, $this->valid(), array(), $this->png_upload() );
        $this->assertSame( 'form_closed', $response['data']['code'] );
        $this->assertSame( 'cap_reached', $response['data']['reason'] );
        $this->assertSame( 'Sold out.', $response['data']['message'] );

        $result = $this->connector( $form_id, $this->valid() );
        $this->assertSame( 'form_closed', $result->get_error_code() );
        $this->assertSame( 'cap_reached', $result->get_error_data()['reason'] );
        $this->assertSame( 'Sold out.', $result->get_error_message() );

        $this->assertSame( $before, $this->side_effects( $form_id ) );
        $this->assertSame( 1, $this->places( $form_id ), 'Never past the cap.' );
    }

    // ------------------------------------------------------------------
    // Failures after the claim give the place back.
    // ------------------------------------------------------------------

    public function test_ajax_validation_failure_takes_no_place() {
        $form_id = $this->make_form( 'ajax-invalid', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $response = $this->ajax( $form_id, array( 'name' => '', 'email' => 'nope' ) );
        $this->assertSame( 'validation_failed', $response['data']['code'] );
        $this->assertSame( 0, $this->places( $form_id ) );

        $response = $this->ajax( $form_id, $this->valid() );
        $this->assertTrue( $response['success'], 'The place is still there.' );
    }

    public function test_connector_validation_failure_takes_no_place() {
        $form_id = $this->make_form( 'conn-invalid', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->assertInstanceOf( 'WP_Error', $this->connector( $form_id, array( 'name' => 'X' ) ) );
        $this->assertSame( 0, $this->places( $form_id ) );
    }

    public function test_ajax_upload_refused_after_the_entry_is_written_gives_the_place_back() {
        $form_id = $this->make_form( 'upload', array( 'max_entries' => 1 ), array(), $this->fields_with_file() );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $response = $this->ajax( $form_id, $this->valid(), array(), $this->png_upload() );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 'invalid_upload', $response['data']['code'], 'Refused when storing the file, after the claim.' );
        $this->assertSame( 0, $this->entries( $form_id ), 'The entry was removed.' );
        $this->assertSame( 0, $this->places( $form_id ), 'The count after equals the count before.' );
        $this->assertSame( 0, $this->fired['pforms_form_full'], 'A failed last-place submission does not announce a full form.' );
        $this->assertSame( 0, $this->fired['pforms_submission_complete'] );
    }

    public function test_ajax_database_error_gives_the_place_back() {
        $form_id = $this->make_form( 'ajax-dberr', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->break_entry_insert();
        $response = $this->ajax( $form_id, $this->valid() );
        $GLOBALS['wpdb']->suppress_errors( false );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 'processing_error', $response['data']['code'] );
        $this->assertSame( 0, $this->entries( $form_id ) );
        $this->assertSame( 0, $this->places( $form_id ), 'The count after equals the count before.' );
        $this->assertSame( 0, $this->fired['pforms_form_full'] );
    }

    public function test_connector_database_error_gives_the_place_back() {
        $form_id = $this->make_form( 'conn-dberr', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->break_entry_insert();
        $thrown = null;
        try {
            $this->connector( $form_id, $this->valid() );
        } catch ( \Exception $e ) {
            $thrown = $e;
        }
        $GLOBALS['wpdb']->suppress_errors( false );

        $this->assertNotNull( $thrown, 'PForms_Entry::create() reports a database error by throwing; it still reaches the caller.' );
        $this->assertSame( 0, $this->entries( $form_id ) );
        $this->assertSame( 0, $this->places( $form_id ), 'The count after equals the count before.' );
        $this->assertSame( 0, $this->fired['pforms_form_full'] );
    }

    public function test_failure_on_a_counting_uncapped_form_gives_the_count_back() {
        // Q-010: a form that once had a cap keeps counting while uncapped.
        $form_id = $this->make_form( 'uncapped-dberr' );
        ( new \PForms_Place_Counter() )->set( $form_id, 4 );

        $this->break_entry_insert();
        $this->ajax( $form_id, $this->valid() );
        $GLOBALS['wpdb']->suppress_errors( false );
        $this->assertSame( 4, $this->places( $form_id ) );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertSame( 5, $this->places( $form_id ), 'Accepted while uncapped: counted.' );
        $this->assertIsArray( $this->connector( $form_id, $this->valid() ) );
        $this->assertSame( 6, $this->places( $form_id ) );
    }

    // ------------------------------------------------------------------
    // Spam (Q-001): never takes a place; still the fake success.
    // ------------------------------------------------------------------

    public function test_honeypot_submission_gets_fake_success_is_kept_as_spam_and_takes_no_place() {
        $form_id = $this->make_form( 'spam', array( 'max_entries' => 1 ), array(
            'spam_protection' => array( 'honeypot' => true, 'timing_check' => false, 'rate_limit' => array() ),
            'success_message' => 'Thanks, see you at camp.',
        ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );
        $trap = ( new \PForms_Honeypot() )->get_field_name( $form_id );

        $response = $this->ajax( $form_id, $this->valid(), array( $trap => 'i am a bot' ) );

        $this->assertTrue( $response['success'], 'The normal fake success.' );
        $this->assertSame( 'Thanks, see you at camp.', $response['data']['message'] );
        $this->assertSame( 1, $this->entries( $form_id, true ), 'Kept as a spam entry, as before.' );
        $this->assertSame( 0, $this->places( $form_id ), 'Spam never takes a place.' );
        $this->assertSame( 0, $this->fired['pforms_submission_complete'] );
        $this->assertSame( 0, $this->fired['pforms_form_full'] );
        $this->assertSame( array(), $this->http );

        // The one place is still there for a person.
        $this->assertTrue( $this->ajax( $form_id, $this->valid(), array( $trap => '' ) )['success'] );
        $this->assertSame( 1, $this->places( $form_id ) );
        $this->assertSame( 1, $this->entries( $form_id, false ) );
    }

    public function test_honeypot_submission_to_a_closed_form_stores_nothing() {
        $form_id = $this->make_form( 'spam-closed', array( 'closes_at' => '2020-01-01' ), array(
            'spam_protection' => array( 'honeypot' => true, 'timing_check' => false, 'rate_limit' => array() ),
        ) );
        $trap   = ( new \PForms_Honeypot() )->get_field_name( $form_id );
        $before = $this->side_effects( $form_id );

        $response = $this->ajax( $form_id, $this->valid(), array( $trap => 'i am a bot' ) );

        $this->assertSame( 'form_closed', $response['data']['code'] );
        $this->assertSame( $before, $this->side_effects( $form_id ), 'Not even a spam entry.' );
    }

    // ------------------------------------------------------------------
    // store_entries off (Q-001): dates and the cap both enforced.
    // ------------------------------------------------------------------

    public function test_store_entries_off_enforces_dates_on_both_paths() {
        $form_id = $this->make_form( 'nostore-date', array( 'closes_at' => '2020-01-01' ), array( 'store_entries' => false ) );
        $before  = $this->side_effects( $form_id );

        $this->assertSame( 'closed_date', $this->ajax( $form_id, $this->valid() )['data']['reason'] );
        $this->assertSame( 'closed_date', $this->connector( $form_id, $this->valid() )->get_error_data()['reason'] );
        $this->assertSame( $before, $this->side_effects( $form_id ) );
    }

    public function test_store_entries_off_enforces_the_cap_on_the_ajax_path() {
        $form_id = $this->make_form( 'nostore-ajax', array( 'max_entries' => 1 ), array( 'store_entries' => false ) );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertSame( 1, $this->places( $form_id ) );
        $this->assertSame( 0, $this->entries( $form_id ), 'Nothing stored, but counted.' );
        $mails = count( $this->mails );

        $second = $this->ajax( $form_id, $this->valid() );
        $this->assertSame( 'form_closed', $second['data']['code'] );
        $this->assertSame( 'cap_reached', $second['data']['reason'] );
        $this->assertSame( 1, $this->places( $form_id ) );
        $this->assertSame( $mails, count( $this->mails ), 'The refused one sends no mail.' );
    }

    public function test_store_entries_off_enforces_the_cap_on_the_connector_path() {
        $form_id = $this->make_form( 'nostore-conn', array( 'max_entries' => 1 ), array( 'store_entries' => false ) );

        $this->assertIsArray( $this->connector( $form_id, $this->valid() ) );
        $this->assertSame( 1, $this->places( $form_id ) );

        $second = $this->connector( $form_id, $this->valid() );
        $this->assertSame( 'form_closed', $second->get_error_code() );
        $this->assertSame( 'cap_reached', $second->get_error_data()['reason'] );
        $this->assertSame( 1, $this->places( $form_id ) );
    }

    // ------------------------------------------------------------------
    // dry_run (Q-008).
    // ------------------------------------------------------------------

    public function test_dry_run_on_a_closed_form_is_refused_with_the_closed_reason() {
        $dated = $this->make_form( 'dry-date', array( 'closes_at' => '2020-01-01' ) );
        $full  = $this->make_form( 'dry-full', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $full, 1 );

        $result = $this->connector( $dated, $this->valid(), array( 'dry_run' => true ) );
        $this->assertSame( 'form_closed', $result->get_error_code() );
        $this->assertSame( 'closed_date', $result->get_error_data()['reason'] );

        $result = $this->connector( $full, $this->valid(), array( 'dry_run' => true ) );
        $this->assertSame( 'form_closed', $result->get_error_code() );
        $this->assertSame( 'cap_reached', $result->get_error_data()['reason'] );
        $this->assertSame( 1, $this->places( $full ) );

        // Invalid fields: still "closed", not field errors.
        $result = $this->connector( $dated, array(), array( 'dry_run' => true ) );
        $this->assertSame( 'form_closed', $result->get_error_code() );
    }

    public function test_dry_run_on_an_open_capped_form_never_takes_or_holds_a_place() {
        $form_id = $this->make_form( 'dry-open', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );
        $before = $this->side_effects( $form_id );

        for ( $i = 0; $i < 3; $i++ ) {
            $result = $this->connector( $form_id, $this->valid(), array( 'dry_run' => true ) );
            $this->assertIsArray( $result );
            $this->assertTrue( $result['dry_run'] );
        }

        $this->assertSame( $before, $this->side_effects( $form_id ), 'No place, row, mail, webhook or action.' );
        $this->assertSame( 0, $this->places( $form_id ) );
        $this->assertIsArray( $this->connector( $form_id, $this->valid() ), 'The one place is still there.' );
    }

    // ------------------------------------------------------------------
    // Q-003: pforms_form_full.
    // ------------------------------------------------------------------

    public function test_form_full_fires_once_on_the_ajax_submission_that_takes_the_last_place() {
        $form_id = $this->make_form( 'full-ajax', array( 'max_entries' => 2 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertSame( 0, $this->fired['pforms_form_full'], 'Not on a place that is not the last.' );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertSame( 1, $this->fired['pforms_form_full'] );

        $this->assertSame( 'form_closed', $this->ajax( $form_id, $this->valid() )['data']['code'] );
        $this->assertSame( 'form_closed', $this->connector( $form_id, $this->valid() )->get_error_code() );
        $this->assertSame( 1, $this->fired['pforms_form_full'], 'Never on a refused submission.' );

        $call = $this->full_calls[0];
        $this->assertSame( $form_id, $call['form_id'] );
        $this->assertSame( 2, $call['cap'] );
        $this->assertGreaterThan( 0, $call['entry_id'] );
        $this->assertTrue( $call['entry_stored'], 'Fired after the entry is stored.' );
        $this->assertSame( 2, $call['count_at_fire'] );
        $this->assertTrue( $call['is_config'] );
    }

    public function test_form_full_fires_once_on_the_connector_submission_that_takes_the_last_place() {
        $form_id = $this->make_form( 'full-conn', array( 'max_entries' => 2 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->assertIsArray( $this->connector( $form_id, $this->valid() ) );
        $this->assertSame( 0, $this->fired['pforms_form_full'] );

        $this->assertIsArray( $this->connector( $form_id, $this->valid(), array( 'dry_run' => true ) ) );
        $this->assertSame( 0, $this->fired['pforms_form_full'], 'A dry run takes no place, so it fills nothing.' );

        $last = $this->connector( $form_id, $this->valid() );
        $this->assertIsArray( $last );
        $this->assertSame( 1, $this->fired['pforms_form_full'] );
        $this->assertSame( $last['entry_id'], $this->full_calls[0]['entry_id'] );
        $this->assertTrue( $this->full_calls[0]['entry_stored'] );

        $this->assertInstanceOf( 'WP_Error', $this->connector( $form_id, $this->valid() ) );
        $this->assertSame( 'form_closed', $this->ajax( $form_id, $this->valid() )['data']['code'] );
        $this->assertSame( 1, $this->fired['pforms_form_full'] );
    }

    public function test_form_full_fires_with_entry_zero_when_entries_are_not_stored() {
        $form_id = $this->make_form( 'full-nostore', array( 'max_entries' => 1 ), array( 'store_entries' => false ) );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertSame( 1, $this->fired['pforms_form_full'] );
        $this->assertSame( 0, $this->full_calls[0]['entry_id'] );
    }

    public function test_uncapped_form_never_fires_form_full() {
        $form_id = $this->make_form( 'full-none' );
        ( new \PForms_Place_Counter() )->set( $form_id, 0 );

        $this->assertTrue( $this->ajax( $form_id, $this->valid() )['success'] );
        $this->assertIsArray( $this->connector( $form_id, $this->valid() ) );
        $this->assertSame( 0, $this->fired['pforms_form_full'] );
    }

    // ------------------------------------------------------------------
    // The duplicate and idempotency guards still answer truthfully on a
    // form that the first copy filled.
    // ------------------------------------------------------------------

    public function test_repeat_of_the_submission_that_filled_the_form_is_told_it_arrived() {
        $form_id = $this->make_form( 'dup', array( 'max_entries' => 1 ), array( 'success_message' => 'You are in.' ) );
        $values  = $this->valid();

        $this->assertTrue( $this->ajax( $form_id, $values )['success'] );

        // Same content again (a reload and resubmit): "we have it", not "closed".
        $repeat = $this->ajax( $form_id, $values );
        $this->assertTrue( $repeat['success'] );
        $this->assertSame( 'You are in.', $repeat['data']['message'] );
        $this->assertSame( 1, $this->entries( $form_id ) );
        $this->assertSame( 1, $this->places( $form_id ) );

        // Different content: closed.
        $this->assertSame( 'form_closed', $this->ajax( $form_id, $this->valid() )['data']['code'] );
    }

    public function test_retry_of_the_submission_id_that_filled_the_form_gets_its_stored_response() {
        $form_id = $this->make_form( 'idem', array( 'max_entries' => 1 ), array( 'success_message' => 'You are in.' ) );
        $extra   = array( '_pforms_submission_id' => wp_generate_uuid4() );

        $this->assertTrue( $this->ajax( $form_id, $this->valid(), $extra )['success'] );
        $retry = $this->ajax( $form_id, $this->valid(), $extra );

        $this->assertTrue( $retry['success'] );
        $this->assertSame( 'You are in.', $retry['data']['message'] );
        $this->assertSame( 1, $this->entries( $form_id ) );
        $this->assertSame( 1, $this->places( $form_id ) );
    }

    public function test_a_refused_closed_submission_does_not_block_its_retry_once_a_place_returns() {
        // The refusal releases the duplicate-window claim it took, so the same
        // content sent after the owner frees a place is processed, not told
        // "still processing".
        $form_id = $this->make_form( 'retry', array( 'max_entries' => 1 ) );
        ( new \PForms_Place_Counter() )->set( $form_id, 1 );
        $values = $this->valid();

        $this->assertSame( 'form_closed', $this->ajax( $form_id, $values )['data']['code'] );

        ( new \PForms_Place_Counter() )->set( $form_id, 0 );
        $this->assertTrue( $this->ajax( $form_id, $values )['success'] );
        $this->assertSame( 1, $this->places( $form_id ) );
    }
}
