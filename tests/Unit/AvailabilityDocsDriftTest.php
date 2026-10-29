<?php
/**
 * The availability settings, as the code defines them, must be what the
 * agent-facing contract describes: the JSON Schema and the connector
 * rulebook. A key renamed or added in PForms_Availability::DEFAULTS without
 * the docs following fails here instead of drifting silently.
 *
 * Also pins the save-time message for a local time skipped by a
 * daylight-saving change (P1 reviewer finding).
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use Brain\Monkey\Functions;

/**
 * Availability docs drift + DST-gap message.
 */
class AvailabilityDocsDriftTest extends UnitTestCase {

    /**
     * Site timezone served by the wp_timezone stub.
     *
     * @var string
     */
    private $zone = 'America/Los_Angeles';

    protected function set_up() {
        parent::set_up();
        Functions\when( 'wp_timezone' )->alias( function () {
            return new \DateTimeZone( $this->zone );
        } );
    }

    /**
     * The decoded docs/form-schema.json.
     *
     * @return array
     */
    private function schema() {
        $raw = file_get_contents( \FRE_TEST_PLUGIN_DIR . 'docs/form-schema.json' );
        $this->assertIsString( $raw );
        $schema = json_decode( $raw, true );
        $this->assertIsArray( $schema, 'docs/form-schema.json must be valid JSON.' );
        return $schema;
    }

    public function test_every_availability_setting_is_in_the_schema() {
        $schema = $this->schema();

        $this->assertSame(
            '#/$defs/availabilitySettings',
            $schema['$defs']['settings']['properties']['availability']['$ref'] ?? null,
            'settings.availability must be described in the schema.'
        );

        $block = $schema['$defs']['availabilitySettings'];
        $this->assertFalse( $block['additionalProperties'], 'Unknown availability keys are refused at save; the schema must say so.' );
        $this->assertSame(
            array_keys( \PForms_Availability::DEFAULTS ),
            array_keys( $block['properties'] ),
            'The schema must describe exactly the availability keys the code defines.'
        );

        foreach ( $block['properties'] as $key => $definition ) {
            $this->assertNotEmpty( $definition['description'] ?? '', "availability.{$key} needs a description." );
        }

        // The unlimited state and the date format are spelled out.
        $this->assertSame( array( 'integer', 'null' ), $block['properties']['max_entries']['type'] );
        $this->assertSame( 0, $block['properties']['max_entries']['minimum'] );
        $this->assertStringContainsString( 'UNLIMITED', $block['properties']['max_entries']['description'] );
        $date = $schema['$defs']['availabilityDate'];
        $this->assertSame( 1, preg_match( '/' . $date['pattern'] . '/', '2027-03-15 09:00' ) );
        $this->assertSame( 1, preg_match( '/' . $date['pattern'] . '/', '2027-03-15' ) );
        $this->assertSame( 0, preg_match( '/' . $date['pattern'] . '/', '2027-03-15T09:00' ) );
        $this->assertStringContainsString( 'SITE timezone', $date['description'] );
    }

    public function test_every_availability_setting_is_in_the_rulebook() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );

        $this->assertArrayHasKey( 'availability', $rulebook['settings_hints'] );
        $hint = $rulebook['settings_hints']['availability'];

        foreach ( array_keys( \PForms_Availability::DEFAULTS ) as $key ) {
            // Whole-word match: a rename to a substring (max_entries -> entries)
            // must fail here, not slip through a substring check.
            $this->assertMatchesRegularExpression(
                '/(?<![A-Za-z0-9_])' . preg_quote( $key, '/' ) . '(?![A-Za-z0-9_])/',
                $hint,
                "The rulebook's availability hint must name {$key} as a whole word."
            );
        }
    }

    public function test_the_rulebook_states_counting_test_submit_and_update_rules() {
        $rulebook = \PForms_Connector_API::get_connector_rulebook( 'https://example.test/schema' );
        $hint     = $rulebook['settings_hints']['availability'];
        $rules    = $rulebook['critical_rules'];

        // Q-001: what counts, what deleting entries does.
        $this->assertStringContainsString( 'Spam never takes a place', $hint );
        $this->assertStringContainsString( 'NEVER gives a place back', $hint );
        // Q-003: the cap-reached action.
        $this->assertStringContainsString( 'pforms_form_full', $hint );
        // Q-008: closed forms refuse test submits, dry runs too.
        $this->assertStringContainsString( 'refuses formengine_test_submit', $rules['closed_forms_refuse_test_submit'] );
        $this->assertStringContainsString( 'dry runs AND real ones', $rules['closed_forms_refuse_test_submit'] );
        $this->assertStringContainsString( 'form_closed', $rules['closed_forms_refuse_test_submit'] );
        // Q-001b: real test submits count.
        $this->assertStringContainsString( 'dry_run = true', $rules['closed_forms_refuse_test_submit'] );
        // Update replaces config: the block must be sent back.
        $this->assertStringContainsString( 'REMOVES', $rules['availability_survives_update_only_if_sent'] );
        // Read-back.
        $this->assertStringContainsString( 'formengine_get_form', $hint );
    }

    public function test_a_time_skipped_by_dst_gets_a_message_that_says_so() {
        $errors = \PForms_Availability::validate( array( 'opens_at' => '2027-03-14 02:30' ) );

        $this->assertCount( 1, $errors );
        $this->assertStringContainsString( 'does not exist in the site timezone (America/Los_Angeles)', $errors[0] );
        $this->assertStringContainsString( 'settings.availability.opens_at ("2027-03-14 02:30")', $errors[0] );
        $this->assertStringNotContainsString( 'must be a date', $errors[0] );
    }

    public function test_the_hour_after_the_gap_is_accepted() {
        $this->assertSame( array(), \PForms_Availability::validate( array( 'closes_at' => '2027-03-14 03:30' ) ) );
    }

    public function test_the_same_time_in_a_zone_without_that_gap_is_accepted() {
        $this->zone = 'UTC';
        $this->assertSame( array(), \PForms_Availability::validate( array( 'opens_at' => '2027-03-14 02:30' ) ) );
    }

    public function test_a_malformed_or_impossible_time_keeps_the_format_message() {
        foreach ( array( '2027-03-14 25:00', '2027-02-30', '14/03/2027' ) as $value ) {
            $errors = \PForms_Availability::validate( array( 'closes_at' => $value ) );
            $this->assertCount( 1, $errors, $value );
            $this->assertStringContainsString( 'must be a date "YYYY-MM-DD"', $errors[0], $value );
        }
    }
}
