<?php
/**
 * Field naming and value coercion tests.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\DocumentFields;

/**
 * @covers \SolrEngine\Documents\DocumentFields
 * @covers \SolrEngine\Contracts\FieldType
 */
final class DocumentFieldsTest extends TestCase {

	// -- Names must land in a dynamic pattern ------------------------------

	public function test_meta_fields_use_the_type_prefix(): void {
		$this->assertSame( 'se_f_meta_price', DocumentFields::meta( FieldType::FLOAT, 'price' ) );
		$this->assertSame( 'se_ss_meta_colours', DocumentFields::meta( FieldType::STRINGS, 'colours' ) );
	}

	public function test_taxonomy_term_names_sit_under_the_copyfield_glob(): void {
		// The contract copies se_ss_tax_* into both the catch-all and the
		// taxonomy aggregate. A different prefix would silently stop taxonomy
		// terms being searchable at all.
		$this->assertStringStartsWith( 'se_ss_tax_', DocumentFields::taxonomy_terms( 'category' ) );
	}

	public function test_taxonomy_ids_and_paths_sit_outside_that_glob(): void {
		// Copying numeric IDs into searchable text would make a search for
		// "34" match every post in term 34.
		$this->assertStringStartsNotWith( 'se_ss_tax_', DocumentFields::taxonomy_ids( 'category' ) );
		$this->assertStringStartsNotWith( 'se_ss_tax_', DocumentFields::taxonomy_paths( 'category' ) );
	}

	/**
	 * @dataProvider awkward_names
	 */
	public function test_names_are_reduced_to_what_solr_accepts( string $input, string $expected ): void {
		$this->assertSame( $expected, DocumentFields::sanitise( $input ) );
	}

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	public function awkward_names(): array {
		return array(
			array( '_price', 'price' ),
			array( 'my-field', 'my_field' ),
			array( 'my.field', 'my_field' ),
			array( 'Field Name', 'field_name' ),
			array( 'pa_screen-size', 'pa_screen_size' ),
			array( '???', 'unnamed' ),
			array( '', 'unnamed' ),
		);
	}

	public function test_every_generated_name_matches_a_contract_pattern(): void {
		// Any name that does not match a dynamic pattern has nowhere to land
		// and would be rejected by Solr at index time.
		$names = array(
			DocumentFields::meta( FieldType::FLOAT, 'price' ),
			DocumentFields::meta( FieldType::DATE, 'event_date' ),
			DocumentFields::taxonomy_terms( 'product_cat' ),
			DocumentFields::taxonomy_ids( 'product_cat' ),
			DocumentFields::taxonomy_paths( 'product_cat' ),
			DocumentFields::TITLE,
			DocumentFields::SITE,
			DocumentFields::DATE,
		);

		foreach ( $names as $name ) {
			$matched = false;

			foreach ( FieldType::all() as $type ) {
				if ( 0 === strpos( $name, 'se_' . $type . '_' ) ) {
					$matched = true;
					break;
				}
			}

			$this->assertTrue( $matched, $name . ' matches no dynamic field pattern' );
		}
	}

	// -- Dates -------------------------------------------------------------

	public function test_dates_are_emitted_as_utc_with_a_trailing_z(): void {
		// The 0.1.0 prototype emitted ISO 8601 with a numeric offset, which a
		// strict Solr date field rejects.
		$this->assertSame( '2026-09-19T10:30:00Z', DocumentFields::date( '2026-09-19 10:30:00' ) );
	}

	public function test_the_wordpress_empty_date_is_not_a_date(): void {
		$this->assertNull( DocumentFields::date( '0000-00-00 00:00:00' ) );
	}

	public function test_an_unparseable_date_is_dropped(): void {
		$this->assertNull( DocumentFields::date( 'sometime last Tuesday-ish' ) );
		$this->assertNull( DocumentFields::date( '' ) );
	}

	public function test_a_timestamp_is_accepted(): void {
		$this->assertSame( '1970-01-01T00:00:01Z', DocumentFields::date( 1 ) );
	}

	// -- Coercion ----------------------------------------------------------

	public function test_numeric_strings_become_numbers(): void {
		$this->assertSame( 1299, DocumentFields::coerce( FieldType::INT, '1299' ) );
		$this->assertSame( 1299.99, DocumentFields::coerce( FieldType::FLOAT, '1299.99' ) );
	}

	public function test_a_non_numeric_value_for_a_number_is_dropped(): void {
		// This is the case that makes Solr's schemaless mode unusable: a meta
		// key that usually holds a number and once holds "N/A" would poison
		// the field forever. Here it costs one value.
		$this->assertNull( DocumentFields::coerce( FieldType::INT, 'N/A' ) );
		$this->assertNull( DocumentFields::coerce( FieldType::FLOAT, 'call for price' ) );
	}

	/**
	 * @dataProvider booleanish
	 */
	public function test_wordpress_style_booleans_are_understood( $input, ?bool $expected ): void {
		$this->assertSame( $expected, DocumentFields::coerce( FieldType::BOOL, $input ) );
	}

	/**
	 * @return array<int,array{0:mixed,1:bool|null}>
	 */
	public function booleanish(): array {
		return array(
			array( '1', true ),
			array( 'yes', true ),
			array( true, true ),
			array( '0', false ),
			array( 'no', false ),
			array( '', false ),
			array( false, false ),
			array( 'maybe', null ),
		);
	}

	public function test_a_single_valued_field_takes_the_first_member_of_an_array(): void {
		// Plugins commonly store one value as a one-element array.
		$this->assertSame( 'red', DocumentFields::coerce( FieldType::STRING, array( 'red', 'blue' ) ) );
	}

	public function test_a_multi_valued_field_keeps_every_usable_member(): void {
		$this->assertSame(
			array( 1, 2, 3 ),
			DocumentFields::coerce( FieldType::INTS, array( '1', '2', 'nope', '3' ) )
		);
	}

	public function test_a_multi_valued_field_deduplicates(): void {
		$this->assertSame(
			array( 'red', 'blue' ),
			DocumentFields::coerce( FieldType::STRINGS, array( 'red', 'blue', 'red' ) )
		);
	}

	public function test_objects_and_nested_arrays_are_dropped(): void {
		// Serialised meta is common and has no sensible flat representation.
		$this->assertNull( DocumentFields::coerce( FieldType::STRING, new \stdClass() ) );
		$this->assertNull( DocumentFields::coerce( FieldType::STRINGS, array( array( 'nested' ) ) ) );
	}

	public function test_an_empty_string_is_dropped_rather_than_indexed(): void {
		$this->assertNull( DocumentFields::coerce( FieldType::STRING, '' ) );
		$this->assertNull( DocumentFields::coerce( FieldType::STRINGS, array( '', '' ) ) );
	}

	public function test_null_is_always_dropped(): void {
		foreach ( FieldType::all() as $type ) {
			$this->assertNull( DocumentFields::coerce( $type, null ) );
		}
	}
}
