<?php
/**
 * Facet request construction tests.
 *
 * The facet layer's security property is the same as the query layer's: a
 * caller names a facet and the server decides what that means. These assert
 * that nothing a caller sends reaches Solr as structure, and that the two
 * halves of multi-select faceting — the tag on the filter and the exclusion in
 * the domain — actually match.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FacetTranslator;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;

/**
 * @covers \SolrEngine\Search\FacetTranslator
 * @covers \SolrEngine\Search\FacetRegistry
 * @covers \SolrEngine\Contracts\FacetDefinition
 */
final class FacetTranslatorTest extends TestCase {

	private function registry(): FacetRegistry {
		$registry = new FacetRegistry();

		$registry->register(
			FacetDefinition::terms( 'post_type', 'se_s_post_type', 'Content type', FieldType::STRING )
		);
		$registry->register(
			FacetDefinition::terms( 'topic', 'se_is_taxid_topic', 'Topic', FieldType::INT )->with_limit( 5 )
		);
		$registry->register(
			FacetDefinition::hierarchy( 'category', 'category', 'se_ss_taxpath_category', 'Category' )
		);
		$registry->register(
			FacetDefinition::range( 'price', 'se_f_meta_price', 'Price', 0.0, 1000.0, 250.0 )->with_refine()
		);

		return $registry;
	}

	private function translator(): FacetTranslator {
		return new FacetTranslator( $this->registry() );
	}

	// -- What a caller may ask for -----------------------------------------

	public function test_only_registered_keys_produce_a_facet(): void {
		$query = ( new SearchQuery( 'laptop', 1 ) )->with_facets( array( 'topic', 'se_s_status' ) );

		$facets = $this->translator()->translate( $query );

		$this->assertArrayHasKey( 'topic', $facets );
		$this->assertArrayNotHasKey( 'se_s_status', $facets );
	}

	public function test_a_facet_names_the_field_the_registry_declared(): void {
		$facets = $this->translator()->translate(
			( new SearchQuery( '', 1 ) )->with_facets( array( 'topic' ) )
		);

		// The caller sent "topic". Which field that is, and how many buckets
		// come back, are decisions made on this side of the wire.
		$this->assertSame( 'se_is_taxid_topic', $facets['topic']['field'] );
		$this->assertSame( 5, $facets['topic']['limit'] );
		$this->assertSame( 'terms', $facets['topic']['type'] );
	}

	// -- Multi-select -------------------------------------------------------

	public function test_a_facet_excludes_its_own_filter_from_its_domain(): void {
		$facets = $this->translator()->translate(
			( new SearchQuery( '', 1 ) )->with_facets( array( 'topic' ) )
		);

		$this->assertSame(
			array( FacetRegistry::tag_for( 'topic' ) ),
			$facets['topic']['domain']['excludeTags']
		);
	}

	public function test_the_tag_on_the_filter_matches_the_tag_the_facet_excludes(): void {
		// The two halves of multi-select faceting are written in different
		// classes. A mismatch between them produces counts that look plausible
		// and are wrong, which is the kind of bug nobody reports.
		$query = ( new SearchQuery( 'laptop', 1 ) )
			->with_filter( 'topic', array( 42 ) )
			->with_facets( array( 'topic' ) );

		$params = ( new QueryTranslator(
			new RelevanceProfile(),
			$this->filters(),
			array(),
			$this->registry()
		) )->translate( $query );

		$tagged = '';

		foreach ( $params['fq'] as $clause ) {
			if ( 0 === strpos( $clause, '{!tag=' ) ) {
				$tagged = $clause;
			}
		}

		$this->assertStringStartsWith( '{!tag=' . FacetRegistry::tag_for( 'topic' ) . '}', $tagged );
		$this->assertStringContainsString( FacetRegistry::tag_for( 'topic' ), (string) $params['json.facet'] );
	}

	public function test_a_filter_without_a_facet_is_not_tagged(): void {
		$query = ( new SearchQuery( '', 1 ) )->with_filter( 'author', array( 7 ) );

		$params = ( new QueryTranslator(
			new RelevanceProfile(),
			$this->filters(),
			array(),
			$this->registry()
		) )->translate( $query );

		foreach ( $params['fq'] as $clause ) {
			$this->assertStringStartsNotWith( '{!tag=', $clause );
		}
	}

	public function test_a_single_choice_facet_keeps_its_own_filter(): void {
		$registry = new FacetRegistry();

		$registry->register(
			FacetDefinition::terms( 'topic', 'se_is_taxid_topic', 'Topic' )->with_multi_select( false )
		);

		$facets = ( new FacetTranslator( $registry ) )->translate(
			( new SearchQuery( '', 1 ) )->with_facets( array( 'topic' ) )
		);

		$this->assertArrayNotHasKey( 'domain', $facets['topic'] );
	}

	// -- Hierarchy ----------------------------------------------------------

	public function test_a_hierarchy_facet_starts_at_the_top_level(): void {
		$facets = $this->translator()->translate(
			( new SearchQuery( '', 1 ) )->with_facets( array( 'category' ) )
		);

		// Depth 0. Without the depth prefix the top level would be "every path
		// with no slash in it", which a prefix cannot express.
		$this->assertSame( '0/', $facets['category']['prefix'] );
		$this->assertSame( 'se_ss_taxpath_category', $facets['category']['field'] );
	}

	public function test_a_hierarchy_facet_descends_one_level(): void {
		$facets = $this->translator()->translate(
			( new SearchQuery( '', 1 ) )->with_facet( 'category', '1/12/34' )
		);

		$this->assertSame( '2/12/34/', $facets['category']['prefix'] );
	}

	/**
	 * @dataProvider prefixes
	 */
	public function test_child_prefixes( string $parent_path, string $expected ): void {
		$this->assertSame( $expected, FacetTranslator::child_prefix( $parent_path ) );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function prefixes(): array {
		return array(
			'top level' => array( '', '0/' ),
			'one root'  => array( '0/12', '1/12/' ),
			'two deep'  => array( '1/12/34', '2/12/34/' ),
			'malformed' => array( '12', '0/' ),
		);
	}

	public function test_a_parent_that_is_not_a_path_is_refused(): void {
		// The one facet input that does not come from the registry, so it is
		// validated on the way in as well as escaped on the way out.
		$query = ( new SearchQuery( '', 1 ) )->with_facet( 'category', 'se_s_visibility:private' );

		$this->assertSame( '', $query->facet_parent( 'category' ) );
	}

	// -- Ranges -------------------------------------------------------------

	public function test_a_range_facet_carries_the_declared_bounds(): void {
		$facets = $this->translator()->translate(
			( new SearchQuery( '', 1 ) )->with_facets( array( 'price' ) )
		);

		$this->assertSame( 'range', $facets['price']['type'] );
		$this->assertSame( 0.0, $facets['price']['start'] );
		$this->assertSame( 1000.0, $facets['price']['end'] );
		$this->assertSame( 250.0, $facets['price']['gap'] );
		$this->assertSame( 'none', $facets['price']['other'] );
		$this->assertTrue( $facets['price']['refine'] );
	}

	public function test_an_unusable_definition_is_refused_at_registration(): void {
		$registry = new FacetRegistry();

		// A field outside SolrEngine's prefix would let a facet enumerate
		// another application's data in a shared collection.
		$registry->register( FacetDefinition::terms( 'other', 'their_field_s', 'Theirs' ) );

		// A range with no gap asks Solr for an unbounded number of buckets.
		$registry->register( FacetDefinition::range( 'broken', 'se_f_meta_price', 'Price', 0.0, 100.0, 0.0 ) );

		$this->assertFalse( $registry->has( 'other' ) );
		$this->assertFalse( $registry->has( 'broken' ) );
	}

	// -- Documents ----------------------------------------------------------

	public function test_a_facets_only_query_asks_for_no_documents(): void {
		$params = ( new QueryTranslator(
			new RelevanceProfile(),
			$this->filters(),
			array(),
			$this->registry()
		) )->translate(
			( new SearchQuery( 'laptop', 1 ) )->with_facets( array( 'topic' ) )->without_documents()
		);

		$this->assertSame( 0, $params['rows'] );
		$this->assertSame( 0, $params['start'] );
	}

	public function test_a_query_without_facets_sends_no_facet_parameter(): void {
		$params = ( new QueryTranslator(
			new RelevanceProfile(),
			$this->filters(),
			array(),
			$this->registry()
		) )->translate( new SearchQuery( 'laptop', 1 ) );

		$this->assertArrayNotHasKey( 'json.facet', $params );
	}

	public function test_facets_change_the_cache_key(): void {
		$plain = new SearchQuery( 'laptop', 1 );

		// Otherwise a facet request could be answered from a cache entry
		// written by a search that counted nothing.
		$this->assertNotSame(
			$plain->cache_key(),
			$plain->with_facets( array( 'topic' ) )->cache_key()
		);
	}

	private function filters(): FilterRegistry {
		$filters = new FilterRegistry();

		$filters->register( 'topic', 'se_is_taxid_topic', FieldType::INT, 'Topic' );
		$filters->register( 'author', 'se_i_author', FieldType::INT, 'Author' );

		return $filters;
	}
}
