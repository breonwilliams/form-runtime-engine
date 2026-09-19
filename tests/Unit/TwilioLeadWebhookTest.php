<?php
/**
 * A missed-call lead is sent to its form's webhook, without starting
 * workflows.
 *
 * The handler's comment promised that creating the lead would trigger the
 * webhook (to Google Sheets); the dispatcher listens only to
 * pforms_submission_complete, so it never did. The fix calls the dispatcher
 * directly — firing pforms_submission_complete would also start any FlowMint
 * workflow on the form, and a missed call is not a submission. Verified live
 * on Local (webhook sent once with the caller, no submission hook, filter
 * turns it off); this pins the design in source.
 *
 * @package FRE\Tests\Unit
 */

namespace FRE\Tests\Unit;

class TwilioLeadWebhookTest extends UnitTestCase {

	private function lead_method_source() {
		$src   = file_get_contents( \FRE_TEST_PLUGIN_DIR . 'includes/Twilio/class-fre-twilio-handler.php' );
		$start = strpos( $src, 'private function create_lead_entry' );
		$this->assertNotFalse( $start );
		return substr( $src, $start, 3000 );
	}

	public function test_the_lead_is_dispatched_to_the_webhook() {
		$this->assertMatchesRegularExpression( '/PForms_Webhook_Dispatcher::dispatch\(\s*\$entry_id,\s*\$client\[\'form_id\'\],\s*\$data\s*\)/', $this->lead_method_source() );
	}

	public function test_it_does_not_fire_the_submission_hook() {
		$this->assertStringNotContainsString( "do_action( 'pforms_submission_complete'", $this->lead_method_source(), 'that would start FlowMint workflows for a missed call' );
	}

	public function test_it_can_be_turned_off() {
		$this->assertStringContainsString( "apply_filters( 'pforms_twilio_lead_webhook', true", $this->lead_method_source() );
	}
}
