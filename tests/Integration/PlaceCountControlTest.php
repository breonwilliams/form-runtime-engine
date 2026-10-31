<?php
/**
 * The Forms list's Adjust count control (Q-001): AJAX action
 * pforms_set_place_count, behind pforms_manage_forms and a nonce.
 *
 * @package FormRuntimeEngine\Tests\Integration
 */

namespace FRE\Tests\Integration;

/**
 * Refusals and the adjust / reset path of PForms_Forms_Manager::ajax_set_place_count().
 */
class PlaceCountControlTest extends \WP_Ajax_UnitTestCase {

    public static function set_up_before_class() {
        parent::set_up_before_class();
        ( new \PForms_Migrator() )->run_migrations();
    }

    public function set_up() {
        parent::set_up();
        $this->save( 'p6-ctl-capped', array( 'max_entries' => 10 ) );
        ( new \PForms_Place_Counter() )->set( 'p6-ctl-capped', 6 );
        $this->save( 'p6-ctl-plain', null );
    }

    public function tear_down() {
        \PForms_Forms_Repository::delete( 'p6-ctl-capped' );
        \PForms_Forms_Repository::delete( 'p6-ctl-plain' );
        $_POST    = array();
        $_REQUEST = array();
        parent::tear_down();
    }

    private function save( $form_id, $availability ) {
        $config = array( 'fields' => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ) );
        if ( null !== $availability ) {
            $config['settings'] = array( 'availability' => $availability );
        }
        $this->assertNotWPError( \PForms_Forms_Repository::save( $form_id, array( 'config' => wp_json_encode( $config ) ) ) );
    }

    private function as_manager() {
        $user = self::factory()->user->create_and_get( array( 'role' => 'administrator' ) );
        $user->add_cap( \PForms_Capabilities::MANAGE_FORMS );
        wp_set_current_user( $user->ID );
    }

    /**
     * Call the handler like admin-ajax.php does.
     *
     * @return array|string Decoded JSON response, or the wp_die() message.
     */
    private function call( array $post ) {
        $_POST                = array_merge( array( 'action' => 'pforms_set_place_count' ), $post );
        $_REQUEST             = $_POST;
        $this->_last_response = '';

        ob_start();
        try {
            \PForms_Forms_Manager::ajax_set_place_count();
        } catch ( \WPAjaxDieContinueException $e ) {
            return json_decode( $this->_last_response, true );
        } catch ( \WPAjaxDieStopException $e ) {
            return $e->getMessage();
        }
        ob_end_clean();
        $this->fail( 'The handler returned without answering.' );
    }

    private function places( $form_id = 'p6-ctl-capped' ) {
        return ( new \PForms_Place_Counter() )->get( $form_id );
    }

    public function test_the_action_is_registered() {
        new \PForms_Admin();
        $this->assertNotFalse( has_action( 'wp_ajax_pforms_set_place_count', array( 'PForms_Forms_Manager', 'ajax_set_place_count' ) ) );
        $this->assertFalse( has_action( 'wp_ajax_nopriv_pforms_set_place_count' ) );
    }

    public function test_a_request_without_the_capability_is_refused() {
        $user = self::factory()->user->create( array( 'role' => 'subscriber' ) );
        wp_set_current_user( $user );

        $response = $this->call( array(
            'nonce'   => wp_create_nonce( 'pforms_admin_nonce' ),
            'form_id' => 'p6-ctl-capped',
            'count'   => '0',
        ) );

        $this->assertIsArray( $response );
        $this->assertFalse( $response['success'] );
        $this->assertSame( 'Unauthorized', $response['data']['message'] );
        $this->assertSame( 6, $this->places() );
    }

    public function test_a_logged_out_request_is_refused() {
        wp_set_current_user( 0 );

        $response = $this->call( array( 'form_id' => 'p6-ctl-capped', 'count' => '0' ) );

        $this->assertFalse( $response['success'] );
        $this->assertSame( 6, $this->places() );
    }

    public function test_a_request_without_a_nonce_is_refused() {
        $this->as_manager();

        $this->assertSame( '-1', $this->call( array( 'form_id' => 'p6-ctl-capped', 'count' => '0' ) ) );
        $this->assertSame( 6, $this->places() );
    }

    public function test_a_request_with_a_wrong_nonce_is_refused() {
        $this->as_manager();

        $this->assertSame( '-1', $this->call( array(
            'nonce'   => wp_create_nonce( 'some_other_action' ),
            'form_id' => 'p6-ctl-capped',
            'count'   => '0',
        ) ) );
        $this->assertSame( 6, $this->places() );
    }

    public function test_the_owner_can_adjust_and_reset_the_count() {
        $this->as_manager();
        $nonce = wp_create_nonce( 'pforms_admin_nonce' );

        $response = $this->call( array( 'nonce' => $nonce, 'form_id' => 'p6-ctl-capped', 'count' => '9' ) );
        $this->assertTrue( $response['success'] );
        $this->assertSame( 9, $response['data']['count'] );
        $this->assertSame( 9, $this->places() );

        $response = $this->call( array( 'nonce' => $nonce, 'form_id' => 'p6-ctl-capped', 'count' => '0' ) );
        $this->assertTrue( $response['success'] );
        $this->assertSame( 0, $this->places() );
    }

    public function test_bad_counts_unknown_forms_and_uncounted_forms_are_refused() {
        $this->as_manager();
        $nonce = wp_create_nonce( 'pforms_admin_nonce' );

        foreach ( array( '-1', '2.5', 'ten', '', '1234567890' ) as $bad ) {
            $response = $this->call( array( 'nonce' => $nonce, 'form_id' => 'p6-ctl-capped', 'count' => $bad ) );
            $this->assertFalse( $response['success'], "count={$bad}" );
            $this->assertSame( 'Enter a whole number, 0 or more.', $response['data']['message'] );
        }
        $this->assertSame( 6, $this->places() );

        $response = $this->call( array( 'nonce' => $nonce, 'form_id' => 'p6-ctl-nope', 'count' => '1' ) );
        $this->assertSame( 'Form not found.', $response['data']['message'] );

        $response = $this->call( array( 'nonce' => $nonce, 'form_id' => '', 'count' => '1' ) );
        $this->assertSame( 'Form ID is required.', $response['data']['message'] );

        // A form that never had a cap has nothing to count; setting one would make it start counting.
        $response = $this->call( array( 'nonce' => $nonce, 'form_id' => 'p6-ctl-plain', 'count' => '3' ) );
        $this->assertFalse( $response['success'] );
        $this->assertSame( 'This form has no entry limit, so there is no count to adjust.', $response['data']['message'] );
        $this->assertFalse( ( new \PForms_Place_Counter() )->exists( 'p6-ctl-plain' ) );
    }
}
