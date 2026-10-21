<?php
/**
 * The entries list leads with the person, and internal keys stay internal.
 *
 * Sparrowmere dry run (2026-10): the consultation form asks about the project
 * on step 1 and for the person on step 3, so every lead was listed as "What
 * kind of project is it?: Patio or hardscape / Which material do you prefer?:
 * Natural stone". And the entry page ended with a "pforms form version | 1"
 * row: the internal `_pforms_form_version` stamp printed as an answer.
 *
 * @package FormRuntimeEngine\Tests\Unit
 */

namespace FRE\Tests\Unit;

use PForms_Entry_Summary;

class EntrySummaryTest extends UnitTestCase {

	/** Field configs by key, in form order. */
	private function map( array $fields ) {
		$map = array();
		foreach ( $fields as $f ) {
			$map[ $f['key'] ] = $f;
		}
		return $map;
	}

	private function keys( array $parts ) {
		return array_map(
			function ( $p ) {
				return implode( '+', $p['keys'] );
			},
			$parts
		);
	}

	public function test_identity_fields_lead_even_when_asked_last() {
		$map    = $this->map(
			array(
				array( 'key' => 'project_type', 'type' => 'radio' ),
				array( 'key' => 'material', 'type' => 'select' ),
				array( 'key' => 'name', 'type' => 'text', 'autocomplete' => 'name' ),
				array( 'key' => 'email', 'type' => 'email' ),
			)
		);
		$fields = array(
			'project_type'         => 'patio',
			'material'             => 'stone',
			'name'                 => 'Ada Lovelace',
			'email'                => 'ada@example.com',
			'_pforms_form_version' => 1,
		);
		$this->assertSame( array( 'name', 'email' ), $this->keys( PForms_Entry_Summary::pick( $fields, $map ) ) );
	}

	public function test_a_first_and_last_name_pair_reads_as_one_name() {
		$map    = $this->map(
			array(
				array( 'key' => 'workshop', 'type' => 'select' ),
				array( 'key' => 'first_name', 'type' => 'text' ),
				array( 'key' => 'last_name', 'type' => 'text', 'autocomplete' => 'family-name' ),
				array( 'key' => 'contact', 'type' => 'email' ),
			)
		);
		$fields = array(
			'workshop'   => 'joinery',
			'first_name' => 'Ada',
			'last_name'  => 'Lovelace',
			'contact'    => 'ada@example.com',
		);
		$parts  = PForms_Entry_Summary::pick( $fields, $map );
		$this->assertSame( array( 'first_name+last_name', 'contact' ), $this->keys( $parts ) );
		$this->assertSame( 'Name', $parts[0]['label'] );
		$this->assertNull( $parts[1]['label'] );
	}

	public function test_email_only_is_followed_by_the_first_answer() {
		$map    = $this->map(
			array(
				array( 'key' => 'topic', 'type' => 'select' ),
				array( 'key' => 'work_email', 'type' => 'email' ),
			)
		);
		$fields = array( 'topic' => 'billing', 'work_email' => 'a@b.co' );
		$this->assertSame( array( 'work_email', 'topic' ), $this->keys( PForms_Entry_Summary::pick( $fields, $map ) ) );
	}

	public function test_without_identity_fields_it_is_the_first_two_answers() {
		$fields = array( 'a' => 'one', 'b' => 'two', 'c' => 'three' );
		$this->assertSame( array( 'a', 'b' ), $this->keys( PForms_Entry_Summary::pick( $fields, array() ) ) );
	}

	public function test_empty_answers_are_skipped_including_an_empty_name() {
		$map    = $this->map(
			array(
				array( 'key' => 'name', 'type' => 'text' ),
				array( 'key' => 'phone', 'type' => 'tel' ),
				array( 'key' => 'notes', 'type' => 'textarea' ),
			)
		);
		$fields = array( 'name' => '  ', 'phone' => '', 'notes' => 'Call after 5', 'extras' => array( '' ) );
		$this->assertSame( array( 'notes' ), $this->keys( PForms_Entry_Summary::pick( $fields, $map ) ) );
	}

	public function test_a_deleted_form_still_finds_name_and_email_by_key() {
		$fields = array( 'message' => 'Hi', 'email' => 'a@b.co', 'full_name' => 'Ada' );
		$this->assertSame( array( 'full_name', 'email' ), $this->keys( PForms_Entry_Summary::pick( $fields, array() ) ) );
	}

	public function test_an_explicit_autocomplete_overrides_the_key() {
		$map    = $this->map( array( array( 'key' => 'name', 'type' => 'text', 'autocomplete' => 'organization' ) ) );
		$fields = array( 'name' => 'Acme', 'city' => 'Leeds' );
		// "name" here is the company: it is not promoted, so form order holds.
		$this->assertSame( array( 'name', 'city' ), $this->keys( PForms_Entry_Summary::pick( $fields, $map ) ) );
		$map['name']['autocomplete'] = 'section-a billing name';
		$this->assertSame( array( 'name', 'city' ), $this->keys( PForms_Entry_Summary::pick( array( 'city' => 'Leeds', 'name' => 'Ada' ), $map ) ) );
	}

	public function test_internal_keys_are_never_answers() {
		$this->assertTrue( PForms_Entry_Summary::is_internal_key( '_pforms_form_version' ) );
		$this->assertFalse( PForms_Entry_Summary::is_internal_key( 'pforms_form_version' ) );
		$this->assertSame(
			array( 'name' => 'Ada' ),
			PForms_Entry_Summary::answers( array( 'name' => 'Ada', '_pforms_form_version' => 3 ) )
		);
		$this->assertSame(
			array(),
			PForms_Entry_Summary::pick( array( '_pforms_form_version' => 3 ), array() )
		);
	}

	public function test_the_admin_views_use_it() {
		$dir = dirname( __DIR__, 2 ) . '/includes/Admin/';
		$this->assertStringContainsString( 'PForms_Entry_Summary::pick(', file_get_contents( $dir . 'class-fre-entries-list-table.php' ) );
		$this->assertStringContainsString( 'PForms_Entry_Summary::answers( $fields )', file_get_contents( $dir . 'class-fre-entry-detail.php' ) );
		$this->assertStringContainsString( 'PForms_Entry_Summary::answers( $fields )', file_get_contents( $dir . 'class-fre-csv-exporter.php' ) );
	}
}
