<?php
/**
 * Query serialisation tests.
 *
 * Section 21.4 names three properties that keep a second backend possible at
 * near-zero cost, and this is the third: the domain model has to be
 * serialisable, so a backend that is not on this server can be sent the query
 * itself rather than a dialect of somebody's query language.
 *
 * The round trip is also what the cache key is built from, so a property that
 * fails to survive it is a property two different queries can share a cache
 * entry over.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SearchQuery;

/**
 * @covers \SolrEngine\Contracts\SearchQuery
 */
final class SearchQuerySerialisationTest extends TestCase {

	/**
	 * A query using every property there is.
	 *
	 * @return SearchQuery
	 */
	private function full_query(): SearchQuery {
		return ( new SearchQuery( 'red laptop', 3 ) )
			->with_post_types( array( 'post', 'product' ) )
			->with_filter( 'category', array( 12, 14 ) )
			->with_filter( 'pa_size', array( '16gb', 'ssd' ), 'and' )
			->with_sort( 'date_desc' )
			->with_pagination( 4, 25 )
			->with_language( 'de-DE' )
			->with_highlight()
			->with_facet( 'category', '1/12' )
			->with_facet( 'post_type' );
	}

	public function test_every_property_survives_a_round_trip(): void {
		$original = $this->full_query();
		$restored = SearchQuery::from_array( $original->to_array() );

		$this->assertSame( $original->to_array(), $restored->to_array() );
	}

	public function test_a_round_trip_through_json_survives_too(): void {
		// The real boundary. JSON turns an integer into a float, an empty
		// associative array into a list, and a scalar into whatever it feels
		// like — which is why from_array() re-coerces rather than trusting.
		$original = $this->full_query();

		$json     = json_encode( $original->to_array() );
		$restored = SearchQuery::from_array( (array) json_decode( (string) $json, true ) );

		$this->assertSame( $original->to_array(), $restored->to_array() );
		$this->assertSame( $original->cache_key(), $restored->cache_key() );
	}

	public function test_the_restored_query_answers_the_same_questions(): void {
		$restored = SearchQuery::from_array( $this->full_query()->to_array() );

		$this->assertSame( 'red laptop', $restored->terms() );
		$this->assertSame( 3, $restored->site_id() );
		$this->assertSame( array( 'post', 'product' ), $restored->post_types() );
		$this->assertSame( 'date_desc', $restored->sort() );
		$this->assertSame( 4, $restored->page() );
		$this->assertSame( 25, $restored->per_page() );
		$this->assertSame( 75, $restored->offset() );
		$this->assertSame( 'de-DE', $restored->language() );
		$this->assertTrue( $restored->wants_highlight() );
		$this->assertSame( 'and', $restored->filter_mode( 'pa_size' ) );
		$this->assertSame( 'or', $restored->filter_mode( 'category' ) );
		$this->assertSame( '1/12', $restored->facet_parent( 'category' ) );
	}

	public function test_the_cache_key_changes_with_every_property(): void {
		// The reason the key is derived from to_array() rather than from a
		// second hand-written list. A property missing from the key means two
		// queries that differ only in it share a cache entry, and one visitor
		// is served the other's results.
		$base = new SearchQuery( 'laptop', 1 );

		$variants = array(
			'terms'      => new SearchQuery( 'laptops', 1 ),
			'site'       => new SearchQuery( 'laptop', 2 ),
			'post_types' => $base->with_post_types( array( 'page' ) ),
			'filter'     => $base->with_filter( 'category', array( 1 ) ),
			'mode'       => $base->with_filter( 'category', array( 1, 2 ), 'and' ),
			'sort'       => $base->with_sort( 'date_desc' ),
			'page'       => $base->with_pagination( 2, 10 ),
			'per_page'   => $base->with_pagination( 1, 20 ),
			'language'   => $base->with_language( 'fr' ),
			'highlight'  => $base->with_highlight(),
			'facet'      => $base->with_facet( 'category' ),
			'documents'  => $base->without_documents(),
		);

		$keys = array( $base->cache_key() );

		foreach ( $variants as $name => $variant ) {
			$this->assertNotContains(
				$variant->cache_key(),
				$keys,
				'changing ' . $name . ' must change the cache key'
			);

			$keys[] = $variant->cache_key();
		}
	}

	public function test_a_filter_mode_is_never_a_third_thing(): void {
		// Arriving from JSON, a mode could be anything. Anything that is not
		// "and" has to mean "or", which is what a caller gets by saying nothing.
		$restored = SearchQuery::from_array(
			array(
				'terms'        => 'x',
				'site_id'      => 1,
				'filters'      => array(
					'a' => array( 1 ),
					'b' => array( 2 ),
				),
				'filter_modes' => array(
					'a' => 'AND',
					'b' => 'whatever',
				),
			)
		);

		$this->assertSame( 'or', $restored->filter_mode( 'a' ) );
		$this->assertSame( 'or', $restored->filter_mode( 'b' ) );
	}

	public function test_missing_keys_take_their_defaults(): void {
		// A query serialised by a newer version must be readable by an older
		// one, and the reverse. Both are the same rule: ignore what you do not
		// know, default what is not there.
		$restored = SearchQuery::from_array(
			array(
				'terms'          => 'laptop',
				'invented_later' => true,
			)
		);

		$this->assertSame( 'laptop', $restored->terms() );
		$this->assertSame( 0, $restored->site_id() );
		$this->assertSame( 'relevance', $restored->sort() );
		$this->assertSame( 1, $restored->page() );
		$this->assertTrue( $restored->wants_documents() );
		$this->assertFalse( $restored->wants_highlight() );
	}

	public function test_unusable_filter_values_are_dropped(): void {
		$restored = SearchQuery::from_array(
			array(
				'terms'   => 'x',
				'site_id' => 1,
				'filters' => array(
					'good' => array( 1, 'two' ),
					'bad'  => array( array( 'nested' ) ),
				),
			)
		);

		$this->assertArrayHasKey( 'good', $restored->filters() );
		$this->assertArrayNotHasKey( 'bad', $restored->filters() );
	}
}
