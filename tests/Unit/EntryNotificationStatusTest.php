<?php
/**
 * The Entries screen's Email column says only what this plugin can stand
 * behind.
 *
 * The bug these pin: a form whose own notification is switched off showed a
 * grey dash on every entry, identical to "nothing happened", and a site owner
 * read it as a delivery failure against a real lead. Nothing had failed — a
 * workflow plugin was sending the team email, which this column cannot see.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * PForms_Entry_Notification_Status.
 */
class EntryNotificationStatusTest extends UnitTestCase {

    /**
     * Notification setting to report per form id, or false for "no such form".
     *
     * @var array<string, mixed>
     */
    private $forms = array();

    protected function set_up() {
        parent::set_up();

        require_once FRE_TEST_PLUGIN_DIR . 'includes/Admin/class-fre-entry-notification-status.php';

        \PForms_Entry_Notification_Status::flush_cache();

        Functions\when( 'esc_attr' )->returnArg( 1 );
        Functions\when( 'esc_html' )->returnArg( 1 );
        Functions\when( 'esc_url' )->returnArg( 1 );
        Functions\when( 'esc_html__' )->returnArg( 1 );

        $forms = &$this->forms;
        $registry = new class( $forms ) {
            private $forms;
            public function __construct( &$forms ) { $this->forms = &$forms; }
            public function get( $form_id ) {
                return array_key_exists( $form_id, $this->forms ) ? $this->forms[ $form_id ] : null;
            }
        };

        // Same accessor shape the other suites use, and the same global, so
        // whichever test defines pforms() first, both keep working.
        if ( ! function_exists( 'pforms' ) ) {
            $GLOBALS['__fre_test_plugin'] = new class( $registry ) {
                public $registry;
                public function __construct( $registry ) { $this->registry = $registry; }
            };
            eval( 'function pforms() { return $GLOBALS["__fre_test_plugin"]; }' );
        } else {
            $GLOBALS['__fre_test_plugin']->registry = $registry;
        }
    }

    protected function tear_down() {
        \PForms_Entry_Notification_Status::flush_cache();
        parent::tear_down();
    }

    /**
     * Register a form with its notification on or off.
     *
     * @param string $form_id Form id.
     * @param bool   $enabled Whether its own notification is on.
     * @return void
     */
    private function form( $form_id, $enabled ) {
        $this->forms[ $form_id ] = array(
            'settings' => array( 'notification' => array( 'enabled' => $enabled ) ),
        );
    }

    /**
     * An entry row.
     *
     * @param array $overrides Fields to override.
     * @return array
     */
    private function entry( array $overrides = array() ) {
        return array_merge(
            array(
                'id'                 => 195,
                'form_id'            => 'quote',
                'notification_sent'  => 0,
                'notification_error' => '',
            ),
            $overrides
        );
    }

    // ── This plugin's own record ────────────────────────────────────────

    public function test_a_recorded_send_is_sent() {
        $this->form( 'quote', true );

        $status = \PForms_Entry_Notification_Status::for_entry( $this->entry( array( 'notification_sent' => 1 ) ) );

        $this->assertSame( 'sent', $status['state'] );
        $this->assertStringContainsString( 'dashicons-yes', \PForms_Entry_Notification_Status::column_html( $this->entry( array( 'notification_sent' => 1 ) ) ) );
    }

    public function test_a_recorded_error_is_failed() {
        $this->form( 'quote', true );

        $status = \PForms_Entry_Notification_Status::for_entry( $this->entry( array( 'notification_error' => 'SMTP refused' ) ) );

        $this->assertSame( 'failed', $status['state'] );
        $this->assertSame( 'SMTP refused', $status['description'] );
    }

    public function test_notification_off_reads_as_a_setting_not_a_failure() {
        // The false alarm. This form's email is off on purpose because
        // something else sends it; every entry showed the same dash as a
        // failure would.
        $this->form( 'quote', false );

        $status = \PForms_Entry_Notification_Status::for_entry( $this->entry() );
        $html   = \PForms_Entry_Notification_Status::column_html( $this->entry() );

        $this->assertSame( 'off', $status['state'] );
        $this->assertSame( 'Off', $status['label'] );
        $this->assertStringNotContainsString( 'dashicons-minus', $html, 'a dash among ticks reads as a failure' );
        $this->assertStringNotContainsString( 'dashicons-warning', $html );
        $this->assertStringContainsString( 'Off', $html );
    }

    public function test_notification_on_with_nothing_recorded_is_not_sent() {
        $this->form( 'quote', true );

        $this->assertSame( 'not_sent', \PForms_Entry_Notification_Status::for_entry( $this->entry() )['state'] );
    }

    public function test_a_form_with_no_notification_block_counts_as_on() {
        // The submission handler treats a missing block as on; the column
        // must not claim the notification is switched off.
        $this->forms['quote'] = array( 'settings' => array() );

        $this->assertSame( 'not_sent', \PForms_Entry_Notification_Status::for_entry( $this->entry() )['state'] );
    }

    public function test_a_deleted_form_keeps_its_entries_readable() {
        // Deleting a form keeps its entries. We cannot look up a setting that
        // no longer exists, so we must not assert it was off.
        $this->assertSame( 'not_sent', \PForms_Entry_Notification_Status::for_entry( $this->entry( array( 'form_id' => 'gone' ) ) )['state'] );
    }

    // ── Another plugin's claim ──────────────────────────────────────────

    public function test_another_plugin_can_report_that_it_sent_the_email() {
        $this->form( 'quote', false );

        Functions\when( 'apply_filters' )->alias( function ( $hook, $status ) {
            if ( 'pforms_entry_notification_status' !== $hook ) {
                return $status;
            }
            return array(
                'state'  => 'external',
                'label'  => 'Sent by a workflow',
                'source' => 'Example Workflows',
                'url'    => 'https://example.test/run/12',
            );
        } );

        $status = \PForms_Entry_Notification_Status::for_entry( $this->entry() );
        $html   = \PForms_Entry_Notification_Status::column_html( $this->entry() );

        $this->assertSame( 'external', $status['state'] );
        $this->assertSame( 'Sent by a workflow', $status['label'] );
        $this->assertStringContainsString( 'Sent by a workflow', $html );
        $this->assertStringContainsString( 'https://example.test/run/12', $html );
    }

    public function test_someone_elses_claim_is_attributed_and_never_our_tick() {
        // The constraint that matters: the column may repeat another plugin's
        // claim, but must never present it as a delivery this plugin made and
        // can vouch for.
        $this->form( 'quote', false );

        Functions\when( 'apply_filters' )->alias( function ( $hook, $status ) {
            if ( 'pforms_entry_notification_status' !== $hook ) {
                return $status;
            }
            return array( 'state' => 'external', 'label' => 'Sent by a workflow', 'source' => 'Example Workflows' );
        } );

        $entry = $this->entry();
        $html  = \PForms_Entry_Notification_Status::column_html( $entry );

        $this->assertStringNotContainsString( 'dashicons-yes', $html, 'the green tick means WE sent it' );
        $this->assertStringContainsString(
            'Reported by Example Workflows',
            \PForms_Entry_Notification_Status::tooltip( \PForms_Entry_Notification_Status::for_entry( $entry ) )
        );
    }

    public function test_a_filter_cannot_borrow_the_sent_state_for_its_own_send() {
        // 'sent' is this plugin's own record. A filter returning it with a
        // source attached must not produce an attributed green tick.
        $this->form( 'quote', false );

        Functions\when( 'apply_filters' )->alias( function ( $hook, $status ) {
            if ( 'pforms_entry_notification_status' !== $hook ) {
                return $status;
            }
            return array( 'state' => 'sent', 'label' => 'Sent', 'source' => 'Example Workflows', 'url' => 'https://example.test/run/12' );
        } );

        $status = \PForms_Entry_Notification_Status::for_entry( $this->entry() );

        $this->assertSame( '', $status['source'], 'only an external claim carries a source' );
        $this->assertSame( '', $status['url'] );
    }

    public function test_an_unusable_filter_result_falls_back_to_our_own_status() {
        $this->form( 'quote', false );

        foreach ( array( 'not-an-array', array(), array( 'state' => 'invented' ), array( 'state' => 'external', 'label' => 'Sent' ) ) as $returned ) {
            \PForms_Entry_Notification_Status::flush_cache();
            Functions\when( 'apply_filters' )->alias( function ( $hook, $status ) use ( $returned ) {
                return 'pforms_entry_notification_status' === $hook ? $returned : $status;
            } );

            $this->assertSame(
                'off',
                \PForms_Entry_Notification_Status::for_entry( $this->entry() )['state'],
                'an external claim with no source is not renderable, so it is ignored'
            );
        }
    }
}
