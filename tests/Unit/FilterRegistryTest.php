<?php
/**
 * Filter allowlist tests.
 *
 * The registry is the security boundary for filtering: an unregistered key
 * cannot reach Solr, and a registered one reaches it only as an escaped value
 * against a field the server chose.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Search\FilterRegistry;

/**
 * @covers \SolrEngine\Search\FilterRegistry
 */
final class FilterRegistryTest extends TestCase {

	private function taxonomies(): array {
		return array(
			'category' => array(
				'label'        => 'Categories',
				'hierarchical' => true,
			),
			'post_tag' => array(
				'label'        => 'Tags',
				'hierarchical' => false,
			),
		);
	}

	public function test_a_flat_taxonomy_filters_on_term_identifiers(): void {
		$registry = FilterRegistry::defaults( $this->taxonomies() );

		$this->assertSame(
			'se_is_taxid_post_tag:(9)',
			$registry->clause_for( 'post_tag', array( 9 ) )
		);
	}

	public function test_a_hierarchical_taxonomy_filters_on_the_path(): void {
		// "In Guides" means "in Guides or anything below it", which is both
		// what a visitor means by it and what makes the filter agree with the
		// count rendered beside it.
		$registry = FilterRegistry::defaults( $this->taxonomies() );

		$this->assertSame(
			'se_ss_taxpath_category:(1\/12\/34)',
			$registry->clause_for( 'category', array( '1/12/34' ) )
		);
	}

	public function test_several_values_for_one_key_are_an_or(): void {
		$registry = FilterRegistry::defaults( $this->taxonomies() );

		$this->assertSame(
			'se_is_taxid_post_tag:(9 OR 11)',
			$registry->clause_for( 'post_tag', array( 9, 11 ) )
		);
	}

	public function test_an_unregistered_key_produces_nothing(): void {
		$registry = FilterRegistry::defaults();

		$this->assertFalse( $registry->has( 'se_s_visibility' ) );
		$this->assertSame( '', $registry->clause_for( 'se_s_visibility', array( 'private' ) ) );
	}

	public function test_a_field_outside_solrengines_prefix_is_refused(): void {
		$registry = new FilterRegistry();

		// A registry entry pointing at another application's field would make
		// a shared collection readable through this plugin's endpoint.
		$registry->register( 'theirs', 'their_field_s', FieldType::STRING, 'Theirs' );

		$this->assertFalse( $registry->has( 'theirs' ) );
	}

	// -- Bands --------------------------------------------------------------

	public function test_a_band_value_becomes_the_range_it_names(): void {
		$registry = new FilterRegistry();

		$registry->register_bands( 'price', 'se_f_meta_price', 250.0, 'Price' );

		// The caller sends the lower bound it was given as a bucket key; the
		// server, which declared the gap, turns it back into a band.
		$this->assertSame(
			'se_f_meta_price:[250 TO 500}',
			$registry->clause_for( 'price', array( '250' ) )
		);
	}

	public function test_band_bounds_do_not_overlap(): void {
		$registry = new FilterRegistry();

		$registry->register_bands( 'price', 'se_f_meta_price', 100.0 );

		// The upper bound is exclusive, so a value of exactly 200 falls in one
		// band rather than two and the counts still add up.
		$clause = $registry->clause_for( 'price', array( 100, 200 ) );

		$this->assertSame(
			'(se_f_meta_price:[100 TO 200} OR se_f_meta_price:[200 TO 300})',
			$clause
		);
	}

	public function test_a_band_filter_refuses_values_that_are_not_numbers(): void {
		$registry = new FilterRegistry();

		$registry->register_bands( 'price', 'se_f_meta_price', 100.0 );

		$this->assertSame( '', $registry->clause_for( 'price', array( '100 TO *]' ) ) );
	}

	public function test_a_band_needs_a_gap(): void {
		$registry = new FilterRegistry();

		$registry->register_bands( 'price', 'se_f_meta_price', 0.0 );

		$this->assertFalse( $registry->has( 'price' ) );
	}
}
