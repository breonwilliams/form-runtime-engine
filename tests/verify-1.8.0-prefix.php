<?php
/**
 * 1.8.0 prefix-rename verification harness.
 *
 * Runs inside a live WordPress context (the renamed Promptless Forms plugin
 * must be ACTIVE). Verifies that the fre/FRE_ → pforms/PForms_ rename loaded
 * cleanly, that the option migration carried legacy data across, and that no
 * legacy `fre_`-prefixed plugin options were left behind.
 *
 * Run from Local's Site Shell at the WordPress root:
 *
 *     wp eval-file wp-content/plugins/form-runtime-engine/tests/verify-1.8.0-prefix.php
 *
 * Excluded from the WP.org build (the whole tests/ dir is excluded by
 * bin/build-release.sh). Read-only — it does not modify any data.
 *
 * @package FormRuntimeEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
	fwrite( STDERR, "Must run inside WordPress (via: wp eval-file ...).\n" );
	exit( 1 );
}

$pass = 0;
$fail = 0;

/**
 * Assert helper.
 *
 * @param string $label Human-readable check name.
 * @param bool   $ok    Whether the check passed.
 * @param string $note  Optional detail shown on failure (or always).
 */
function pforms_v180_assert( $label, $ok, $note = '' ) {
	global $pass, $fail;
	if ( $ok ) {
		$pass++;
		echo "  PASS  {$label}" . ( $note ? "  ({$note})" : '' ) . "\n";
	} else {
		$fail++;
		echo "  FAIL  {$label}" . ( $note ? "  ({$note})" : '' ) . "\n";
	}
}

echo "\n=== Promptless Forms 1.8.0 prefix-rename verification ===\n\n";

// ---------------------------------------------------------------------------
echo "-- Constants --\n";
// ---------------------------------------------------------------------------
pforms_v180_assert( 'PForms_VERSION defined', defined( 'PForms_VERSION' ), defined( 'PForms_VERSION' ) ? PForms_VERSION : 'missing' );
pforms_v180_assert( 'PForms_VERSION is 1.8.0', defined( 'PForms_VERSION' ) && version_compare( PForms_VERSION, '1.8.0', '>=' ) );
pforms_v180_assert( 'PForms_PLUGIN_DIR defined', defined( 'PForms_PLUGIN_DIR' ) );
pforms_v180_assert( 'PForms_PLUGIN_URL defined', defined( 'PForms_PLUGIN_URL' ) );
pforms_v180_assert( 'PForms_UPLOAD_DIR preserved as fre-uploads', defined( 'PForms_UPLOAD_DIR' ) && PForms_UPLOAD_DIR === 'fre-uploads', defined( 'PForms_UPLOAD_DIR' ) ? PForms_UPLOAD_DIR : 'missing' );
pforms_v180_assert( 'No leftover PForms_VERSION constant', ! defined( 'PForms_VERSION' ) );

// ---------------------------------------------------------------------------
echo "\n-- Core classes load --\n";
// ---------------------------------------------------------------------------
$core_classes = array(
	'Promptless_Forms', 'PForms_Registry', 'PForms_Renderer', 'PForms_Shortcode',
	'PForms_Submission_Handler', 'PForms_Validator', 'PForms_Sanitizer', 'PForms_Upgrader',
	'PForms_Forms_Repository', 'PForms_Connector_API', 'PForms_Connector_Auth',
	'PForms_Connector_Admin', 'PForms_Entry', 'PForms_Entry_Query', 'PForms_Webhook_Dispatcher',
	'PForms_Email_Notification', 'PForms_Capabilities', 'PForms_Upload_Handler', 'PForms_Admin',
);
foreach ( $core_classes as $cls ) {
	pforms_v180_assert( "class {$cls} exists", class_exists( $cls ) );
}

// ---------------------------------------------------------------------------
echo "\n-- Backward-compat alias + accessor --\n";
// ---------------------------------------------------------------------------
pforms_v180_assert( 'Form_Runtime_Engine alias resolves', class_exists( 'Form_Runtime_Engine' ) );
pforms_v180_assert( 'pforms() accessor exists', function_exists( 'pforms' ) );
if ( function_exists( 'pforms' ) ) {
	$inst = pforms();
	pforms_v180_assert( 'pforms() returns Promptless_Forms', $inst instanceof Promptless_Forms );
	pforms_v180_assert( 'pforms()->registry is PForms_Registry', isset( $inst->registry ) && $inst->registry instanceof PForms_Registry );
}
pforms_v180_assert( 'old pforms() accessor is gone', ! function_exists( 'fre' ) );
pforms_v180_assert( 'public API pforms_register_form() exists', function_exists( 'pforms_register_form' ) );
pforms_v180_assert( 'old pforms_register_form() is gone', ! function_exists( 'pforms_register_form' ) );

// ---------------------------------------------------------------------------
echo "\n-- Shortcodes --\n";
// ---------------------------------------------------------------------------
pforms_v180_assert( '[promptless_form] registered', shortcode_exists( 'promptless_form' ) );
pforms_v180_assert( '[pforms_form] registered', shortcode_exists( 'pforms_form' ) );
pforms_v180_assert( 'old [fre_form] is gone', ! shortcode_exists( 'fre_form' ) );
pforms_v180_assert( 'old [client_form] is gone', ! shortcode_exists( 'client_form' ) );

// ---------------------------------------------------------------------------
echo "\n-- Hooks wired to new names --\n";
// ---------------------------------------------------------------------------
pforms_v180_assert( 'webhook dispatcher listens on pforms_submission_complete', has_action( 'pforms_submission_complete' ) !== false );
pforms_v180_assert( 'nothing left on pforms_submission_complete', has_action( 'pforms_submission_complete' ) === false );

// ---------------------------------------------------------------------------
echo "\n-- Capability --\n";
// ---------------------------------------------------------------------------
if ( class_exists( 'PForms_Capabilities' ) ) {
	pforms_v180_assert( 'MANAGE_FORMS constant is pforms_manage_forms', PForms_Capabilities::MANAGE_FORMS === 'pforms_manage_forms', PForms_Capabilities::MANAGE_FORMS );
}
$admin = get_role( 'administrator' );
if ( $admin ) {
	pforms_v180_assert( 'administrator has pforms_manage_forms', $admin->has_cap( 'pforms_manage_forms' ) );
	pforms_v180_assert( 'legacy pforms_manage_forms revoked from administrator', ! $admin->has_cap( 'pforms_manage_forms' ) );
}

// ---------------------------------------------------------------------------
echo "\n-- Option migration result --\n";
// ---------------------------------------------------------------------------
pforms_v180_assert( 'legacy pforms_plugin_version cleared', get_option( 'pforms_plugin_version', null ) === null );
pforms_v180_assert( 'pforms_plugin_version set', get_option( 'pforms_plugin_version', '' ) !== '' );

// Direct DB scan: no plugin `fre_`-prefixed options should remain. Transients
// (which self-heal) are excluded; so is anything not matching the plugin's
// known legacy keys to avoid false alarms from unrelated plugins.
global $wpdb;
$known_legacy = array(
	'pforms_plugin_version', 'pforms_db_version', 'pforms_migration_error', 'pforms_email_failures',
	'pforms_client_forms', 'pforms_connector_call_log', 'pforms_honeypot_secret', 'pforms_failed_email_queue',
	'pforms_quarantine_suffix', 'pforms_twilio_settings', 'pforms_twilio_db_version', 'pforms_twilio_migration_error',
	'pforms_connector_enabled', 'pforms_connector_entry_read_enabled', 'pforms_google_places_api_key', 'pforms_form_config_errors',
);
$placeholders = implode( ',', array_fill( 0, count( $known_legacy ), '%s' ) );
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$leftover = $wpdb->get_col( $wpdb->prepare( "SELECT option_name FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $known_legacy ) );
pforms_v180_assert( 'no legacy fre_ plugin options remain', empty( $leftover ), empty( $leftover ) ? 'clean' : implode( ', ', $leftover ) );

// Spot-check that migrated data is readable under the new key (only meaningful
// if this site had forms before the upgrade).
$forms = get_option( 'pforms_client_forms', array() );
pforms_v180_assert( 'pforms_client_forms is an array', is_array( $forms ), is_array( $forms ) ? count( $forms ) . ' form(s)' : 'not an array' );

// ---------------------------------------------------------------------------
echo "\n-- DB tables preserved (NOT renamed) --\n";
// ---------------------------------------------------------------------------
$entries_table = $wpdb->prefix . 'fre_entries';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $entries_table ) );
pforms_v180_assert( "entries table {$entries_table} still exists", $exists === $entries_table, $exists ? 'found' : 'MISSING — data would be orphaned' );

// ---------------------------------------------------------------------------
echo "\n=== RESULT: {$pass} passed, {$fail} failed ===\n\n";
if ( $fail > 0 ) {
	echo "One or more checks FAILED — do not submit until resolved.\n";
	exit( 1 );
}
echo "All checks passed.\n";
exit( 0 );
