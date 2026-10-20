<?php
/**
 * Notification headers: the sender never comes from submitted data.
 *
 * A client site configured `from_email: "{field:email}"`. The notification
 * went out with the visitor's address as From, the mail provider (Resend)
 * refused it with 403 validation_error because the domain was not verified,
 * and the mail never left WordPress. It only worked while another plugin's
 * "Force From Email" rewrote the header. Since 1.10.0 the plugin is correct
 * on its own:
 *
 *   - from_email resolves system tokens only; a field token is ignored and no
 *     From header is sent, so WordPress's configured sender applies;
 *   - since 1.12.3 an empty or invalid (non-token) from_email sends from the
 *     site's admin email with the configured from_name, as an unset one does. Before, it sent no From header at all, so
 *     wp_mail() used "WordPress <wordpress@domain>" and dropped from_name
 *     (Sparrowmere dry run, 2026-10). Saving an invalid from_email is refused;
 *   - reply_to resolves field tokens exactly as before;
 *   - a legacy form with a field token in from_email and no reply_to gets that
 *     token as its Reply-To, so replies still reach the submitter;
 *   - there is no domain check — a static cross-domain sender is allowed;
 *   - saving a config with a field token in from_email is refused.
 *
 * build_headers() is exercised directly (it is also what the retry path
 * calls), with the class built without its constructor so no database is
 * needed.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

class NotificationHeadersTest extends UnitTestCase {

	const VISITOR = 'visitor@gmail.com';

	protected function set_up() {
		parent::set_up();
		Functions\when( 'get_bloginfo' )->alias( function ( $show = '' ) {
			return 'name' === $show ? 'Test Site' : '';
		} );
		Functions\when( 'home_url' )->justReturn( 'http://example.com' );
	}

	/**
	 * Build headers for a notification config and entry data.
	 */
	private function headers( array $notification, array $entry = array() ) {
		$config = array(
			'id'       => 'contact',
			'title'    => 'Contact',
			'fields'   => array(
				array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ),
				array( 'key' => 'name', 'type' => 'text', 'label' => 'Name' ),
				array( 'key' => 'domain', 'type' => 'text', 'label' => 'Domain' ),
			),
			'settings' => array( 'notification' => $notification ),
		);
		$entry += array( 'email' => self::VISITOR, 'name' => 'Visitor Name' );

		$class  = new \ReflectionClass( \PForms_Email_Notification::class );
		$object = $class->newInstanceWithoutConstructor();
		$method = $class->getMethod( 'build_headers' );
		if ( PHP_VERSION_ID < 80100 ) {
			$method->setAccessible( true ); // required on PHP 7.4 (CI); a deprecated no-op from 8.1
		}
		return $method->invoke( $object, $config, $entry );
	}

	private function header( array $headers, $name ) {
		foreach ( $headers as $h ) {
			if ( 0 === stripos( $h, $name . ':' ) ) {
				return trim( substr( $h, strlen( $name ) + 1 ) );
			}
		}
		return null;
	}

	public function test_field_token_in_from_email_never_produces_the_submitted_address() {
		$headers = $this->headers( array( 'from_name' => '{site_name}', 'from_email' => '{field:email}' ) );

		$this->assertNull( $this->header( $headers, 'From' ), 'no From header: WordPress\'s configured sender applies' );
		foreach ( $headers as $h ) {
			if ( 0 === stripos( $h, 'Reply-To:' ) ) {
				continue;
			}
			$this->assertStringNotContainsString( self::VISITOR, $h, "the submitted address leaked into: {$h}" );
		}
	}

	public function test_field_token_mixed_into_from_email_is_ignored_too() {
		$headers = $this->headers(
			array( 'from_email' => 'noreply@{field:domain}' ),
			array( 'domain' => 'attacker.example' )
		);

		$this->assertNull( $this->header( $headers, 'From' ) );
		$this->assertStringNotContainsString( 'attacker.example', implode( "\n", $headers ) );
	}

	public function test_reply_to_with_field_email_still_resolves() {
		$headers = $this->headers( array( 'from_email' => 'forms@example.com', 'reply_to' => '{field:email}' ) );

		$this->assertSame( 'Test Site <forms@example.com>', $this->header( $headers, 'From' ) );
		$this->assertSame( self::VISITOR, $this->header( $headers, 'Reply-To' ) );
	}

	public function test_outage_configuration_keeps_reply_to_the_submitter() {
		// Both set, as on the client site: From drops, Reply-To is untouched.
		$headers = $this->headers( array( 'from_email' => '{field:email}', 'reply_to' => '{field:email}' ) );

		$this->assertNull( $this->header( $headers, 'From' ) );
		$this->assertSame( self::VISITOR, $this->header( $headers, 'Reply-To' ) );
	}

	public function test_legacy_form_with_token_only_in_from_email_moves_it_to_reply_to() {
		$headers = $this->headers( array( 'from_email' => '{field:email}', 'reply_to' => '' ) );

		$this->assertNull( $this->header( $headers, 'From' ) );
		$this->assertSame( self::VISITOR, $this->header( $headers, 'Reply-To' ), 'replies still reach the submitter' );
	}

	public function test_an_explicit_reply_to_is_never_replaced_by_the_carried_token() {
		$headers = $this->headers( array( 'from_email' => '{field:email}', 'reply_to' => 'office@example.com' ) );

		$this->assertSame( 'office@example.com', $this->header( $headers, 'Reply-To' ) );
	}

	public function test_no_reply_to_configured_means_no_reply_to_header() {
		$headers = $this->headers( array( 'from_email' => '{admin_email}', 'reply_to' => '' ) );

		$this->assertNull( $this->header( $headers, 'Reply-To' ), 'reply_to has no default' );
	}

	public function test_system_tokens_still_resolve_in_from_email() {
		$headers = $this->headers( array( 'from_name' => '{site_name}', 'from_email' => '{admin_email}' ) );

		$this->assertSame( 'Test Site <admin@example.com>', $this->header( $headers, 'From' ) );
	}

	public function test_a_static_cross_domain_sender_is_allowed() {
		// No domain check: staging hosts, migrated sites and ESP sending
		// domains all differ from home_url().
		$headers = $this->headers( array( 'from_email' => 'hello@brand-mail.example.org' ) );

		$this->assertSame( 'Test Site <hello@brand-mail.example.org>', $this->header( $headers, 'From' ) );
	}

	public function test_unset_from_email_falls_back_to_the_admin_email() {
		$headers = $this->headers( array( 'from_name' => 'Forms' ) );

		$this->assertSame( 'Forms <admin@example.com>', $this->header( $headers, 'From' ) );
	}

	/**
	 * Every value the issue measured as sending as "WordPress" now sends from
	 * the admin email with the configured name.
	 *
	 * @return array
	 */
	public function unusable_sender_provider() {
		return array(
			'empty'                    => array( '' ),
			'whitespace'               => array( '   ' ),
			'a bare word'              => array( 'website' ),
			'a token that is not mail' => array( '{site_url}' ),
		);
	}

	/**
	 * @dataProvider unusable_sender_provider
	 * @param string $from_email Configured from_email.
	 */
	public function test_an_unusable_from_email_sends_from_the_admin_email_with_the_name( $from_email ) {
		$headers = $this->headers( array( 'from_name' => 'Sparrowmere Gardens', 'from_email' => $from_email ) );

		$this->assertSame( 'Sparrowmere Gardens <admin@example.com>', $this->header( $headers, 'From' ) );
	}

	public function test_resolve_sender_address() {
		$this->assertSame( 'admin@example.com', \PForms_Email_Notification::resolve_sender_address( '{admin_email}' ) );
		$this->assertSame( 'forms@example.com', \PForms_Email_Notification::resolve_sender_address( ' forms@example.com ' ) );
		$this->assertSame( '', \PForms_Email_Notification::resolve_sender_address( '' ) );
		$this->assertSame( '', \PForms_Email_Notification::resolve_sender_address( 'website' ) );
		$this->assertSame( '', \PForms_Email_Notification::resolve_sender_address( '{site_url}' ) );
		$this->assertSame( '', \PForms_Email_Notification::resolve_sender_address( '{field:email}' ) );
	}

	public function test_field_tokens_still_resolve_in_from_name() {
		$headers = $this->headers( array( 'from_name' => '{field:name} via {site_name}', 'from_email' => 'forms@example.com' ) );

		$this->assertSame( 'Visitor Name via Test Site <forms@example.com>', $this->header( $headers, 'From' ) );
	}

	public function test_an_injection_attempt_in_the_submitted_address_sets_no_reply_to() {
		$headers = $this->headers(
			array( 'from_email' => 'forms@example.com', 'reply_to' => '{field:email}' ),
			array( 'email' => "visitor@gmail.com\r\nBcc: victim@example.com" )
		);

		$this->assertNull( $this->header( $headers, 'Reply-To' ) );
		$this->assertStringNotContainsString( 'victim@example.com', implode( "\n", $headers ) );
	}

	public function test_has_field_token() {
		$this->assertTrue( \PForms_Email_Notification::has_field_token( '{field:email}' ) );
		$this->assertTrue( \PForms_Email_Notification::has_field_token( 'noreply@{field:domain}' ) );
		$this->assertFalse( \PForms_Email_Notification::has_field_token( '{admin_email}' ) );
		$this->assertFalse( \PForms_Email_Notification::has_field_token( 'forms@example.com' ) );
		$this->assertFalse( \PForms_Email_Notification::has_field_token( null ) );
		$this->assertFalse( \PForms_Email_Notification::has_field_token( array( '{field:email}' ) ) );
	}

	/**
	 * A minimal valid config with the given notification.
	 */
	private function config_with( array $notification ) {
		return array(
			'fields'   => array( array( 'key' => 'email', 'type' => 'email', 'label' => 'Email' ) ),
			'settings' => array( 'notification' => $notification ),
		);
	}

	public function test_saving_a_field_token_in_from_email_is_refused() {
		$result = \PForms_JSON_Schema_Validator::validate( $this->config_with( array( 'from_email' => '{field:email}' ) ) );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'from_email cannot contain a {field:...} token', implode( ' ', $result['errors'] ) );
		$this->assertStringContainsString( 'reply_to', implode( ' ', $result['errors'] ), 'the error points to the right setting' );
	}

	public function test_saving_field_tokens_in_reply_to_and_system_tokens_in_from_email_is_fine() {
		$result = \PForms_JSON_Schema_Validator::validate(
			$this->config_with( array( 'from_email' => '{admin_email}', 'reply_to' => '{field:email}', 'subject' => 'From {field:email}' ) )
		);

		$this->assertTrue( $result['valid'], implode( ' ', $result['errors'] ) );
	}

	/**
	 * @return array
	 */
	public function invalid_saved_sender_provider() {
		return array(
			'a bare word'              => array( 'website' ),
			'a token that is not mail' => array( '{site_url}' ),
			'a broken address'         => array( 'hello@' ),
		);
	}

	/**
	 * @dataProvider invalid_saved_sender_provider
	 * @param string $from_email Configured from_email.
	 */
	public function test_saving_a_from_email_that_is_not_an_address_is_refused( $from_email ) {
		$result = \PForms_JSON_Schema_Validator::validate( $this->config_with( array( 'from_email' => $from_email ) ) );

		$this->assertFalse( $result['valid'] );
		$this->assertStringContainsString( 'is not an email address', implode( ' ', $result['errors'] ) );
	}

	public function test_saving_an_empty_from_email_is_allowed_and_means_unset() {
		$result = \PForms_JSON_Schema_Validator::validate( $this->config_with( array( 'from_name' => 'Sparrowmere Gardens', 'from_email' => '' ) ) );

		$this->assertTrue( $result['valid'], implode( ' ', $result['errors'] ) );
	}

	public function test_connector_description_agrees_with_the_registry_default() {
		$registry  = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Core/class-fre-registry.php' );
		$connector = (string) file_get_contents( FRE_TEST_PLUGIN_DIR . 'includes/Connector/class-fre-connector-api.php' );

		$this->assertMatchesRegularExpression( "/'reply_to'\s*=>\s*''/", $registry, 'registry default reply_to is empty' );
		$this->assertStringNotContainsString( 'reply_to defaults to {field:email}', $connector, 'the connector must not advertise a default the registry does not apply' );
		$this->assertStringContainsString( 'reply_to has no default', $connector );
		$this->assertStringContainsString( 'NOT in from_email', $connector, 'the connector must not advertise field tokens in from_email' );
	}
}
