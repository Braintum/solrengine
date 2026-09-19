<?php
/**
 * Faceting against a real Solr.
 *
 * The facet layer makes claims a unit test cannot settle: that Solr agrees a
 * tagged filter can be excluded from one facet's domain and not from the
 * query, that a prefix over the materialised path field returns exactly one
 * level of a tree, and — the one that matters most — that the number rendered
 * beside a filter is the number of results that filter produces. A count that
 * disagrees with its own result set is the defining bug of faceted search, and
 * only a live index can prove it does not happen here.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\ResponseParser;

/**
 * @covers \SolrEngine\Search\FacetTranslator
 * @covers \SolrEngine\Search\ResponseParser
 * @covers \SolrEngine\Search\FilterRegistry
 */
final class FacetTest extends TestCase {

	/**
	 * Base Solr URL.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Collection under test.
	 *
	 * @var string
	 */
	private static $collection = '';

	/**
	 * Site this suite owns, so other backend suites cannot affect its counts.
	 *
	 * Every suite owns a block of two: its own site and the neighbour it uses
	 * to prove cross-site isolation. Overlapping blocks make a suite pass or
	 * fail depending on which one ran first, which is the worst kind of test.
	 *
	 * @var int
	 */
	private const SITE = 701;

	/**
	 * A neighbouring site in the same collection, which must never be counted.
	 *
	 * @var int
	 */
	private const OTHER_SITE = 702;

	public static function setUpBeforeClass(): void {
		$base = getenv( 'SOLRENGINE_TEST_SOLR_URL' );

		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}

		self::$base = rtrim( $base, '/' );

		$collection = getenv( 'SOLRENGINE_TEST_SOLR_COLLECTION' );

		self::$collection = is_string( $collection ) && '' !== $collection ? $collection : 'wordpress';

		$state = ( new SchemaStateFactory() )->from_response( self::call( 'GET', '/schema', null, array() ), true );

		foreach ( ( new SchemaPlanner() )->plan( ( new ContractRegistry() )->current(), $state )->operations() as $operation ) {
			self::call( 'POST', '/schema', array( $operation->command() => $operation->payload() ), array() );
		}

		self::call( 'POST', '/update', self::fixtures(), array( 'commit' => 'true' ) );
	}

	protected function setUp(): void {
		if ( '' === self::$base ) {
			$this->markTestSkipped( 'Set SOLRENGINE_TEST_SOLR_URL to run backend tests.' );
		}
	}

	// -- Terms --------------------------------------------------------------

	public function test_a_terms_facet_counts_the_index(): void {
		$facets = $this->facets( ( new SearchQuery( '', self::SITE ) )->with_facets( array( 'topic' ) ) );
		$counts = $this->counts( $facets, 'topic' );

		// Compared without regard to order. Solr breaks a tie on count by
		// index order, which is a detail of this fixture rather than something
		// the facet layer promises.
		ksort( $counts );

		$this->assertSame(
			array(
				'17' => 2,
				'42' => 2,
			),
			$counts
		);
	}

	public function test_counts_never_include_documents_a_visitor_cannot_see(): void {
		// A count is a disclosure. The private fixture carries topic 42 and the
		// other site's carries it too; if either were counted, the panel would
		// tell an anonymous visitor how much private content exists.
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facets( array( 'topic' ) ) ),
			'topic'
		);

		$this->assertSame( 2, $counts['42'] );
	}

	// -- Multi-select -------------------------------------------------------

	public function test_selecting_one_value_leaves_the_others_countable(): void {
		$query = ( new SearchQuery( '', self::SITE ) )
			->with_filter( 'topic', array( 42 ) )
			->with_facets( array( 'topic' ) );

		$counts = $this->counts( $this->facets( $query ), 'topic' );

		// The whole point of the tag and exclude pair: a visitor who ticked
		// "42" can still see that 17 has results and change their mind.
		$this->assertSame( 2, $counts['17'] ?? 0 );
		$this->assertSame( 2, $counts['42'] ?? 0 );
	}

	public function test_a_single_choice_facet_narrows_itself(): void {
		// The contrast that shows the exclusion is doing the work: without it,
		// every alternative reads zero the moment one value is selected.
		$registry = $this->registry();

		$registry->register(
			FacetDefinition::terms( 'topic', 'se_is_taxid_topic', 'Topic', FieldType::INT )->with_multi_select( false )
		);

		$query = ( new SearchQuery( '', self::SITE ) )
			->with_filter( 'topic', array( 42 ) )
			->with_facets( array( 'topic' ) );

		$counts = $this->counts( $this->facets( $query, $registry ), 'topic' );

		$this->assertArrayNotHasKey( '17', $counts );
		$this->assertSame( 2, $counts['42'] );
	}

	public function test_a_filter_still_narrows_the_results_it_is_excluded_from(): void {
		$query = ( new SearchQuery( '', self::SITE ) )
			->with_filter( 'topic', array( 42 ) )
			->with_facets( array( 'topic' ) );

		// Excluding the tag from a facet's domain must not exclude it from the
		// query. If it did, a filter panel would count correctly and filter
		// nothing.
		$this->assertSame( 2, $this->parse( $query )->total() );
	}

	// -- Hierarchy ----------------------------------------------------------

	public function test_a_hierarchy_facet_returns_one_level_of_the_tree(): void {
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facets( array( 'category' ) ) ),
			'category'
		);

		$this->assertSame(
			array(
				'0/12' => 3,
				'0/99' => 1,
			),
			$counts
		);
	}

	public function test_descending_shows_the_children_of_one_node(): void {
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facet( 'category', '0/12' ) ),
			'category'
		);

		$this->assertSame( array( '1/12/34' => 2 ), $counts );
	}

	public function test_a_branch_counts_everything_below_it(): void {
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facet( 'category', '1/12/34' ) ),
			'category'
		);

		// 56 sits under 34, and its one document is counted at both levels —
		// which is what makes a parent count usable as a preview of the branch.
		$this->assertSame( array( '2/12/34/56' => 1 ), $counts );
	}

	public function test_a_count_equals_the_results_that_selecting_it_produces(): void {
		// The property the whole feature rests on. A panel offering "Guides
		// (3)" that leads to a page of two results is worse than no panel.
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facets( array( 'category' ) ) ),
			'category'
		);

		$selected = ( new SearchQuery( '', self::SITE ) )->with_filter( 'category', array( '0/12' ) );

		$this->assertSame( $counts['0/12'], $this->parse( $selected )->total() );
	}

	// -- Ranges -------------------------------------------------------------

	public function test_a_range_facet_counts_bands(): void {
		$counts = $this->counts(
			$this->facets( ( new SearchQuery( '', self::SITE ) )->with_facets( array( 'price' ) ) ),
			'price'
		);

		$this->assertSame(
			array(
				'0'    => 2,
				'500'  => 1,
				'1000' => 1,
			),
			$counts
		);
	}

	public function test_selecting_a_band_produces_that_band(): void {
		$query = ( new SearchQuery( '', self::SITE ) )->with_filter( 'price', array( '500' ) );

		$this->assertSame( 1, $this->parse( $query )->total() );
	}

	// -- Shape of the request ------------------------------------------------

	public function test_a_facets_only_request_returns_counts_and_no_documents(): void {
		$query = ( new SearchQuery( '', self::SITE ) )
			->with_facets( array( 'topic' ) )
			->without_documents();

		$results = $this->parse( $query );

		$this->assertSame( array(), $results->hits() );
		$this->assertSame( 4, $results->total() );
		$this->assertNotSame( array(), $results->facet( 'topic' )->buckets() );
	}

	public function test_a_hostile_drill_down_position_reaches_solr_as_nothing(): void {
		// The one facet input that does not come from the registry. It is
		// refused on the way in, so the request is a plain top-level facet
		// rather than a query somebody else wrote.
		$query = ( new SearchQuery( '', self::SITE ) )
			->with_facet( 'category', '0/12"} OR se_s_visibility:private' );

		$counts = $this->counts( $this->facets( $query ), 'category' );

		$this->assertSame(
			array(
				'0/12' => 3,
				'0/99' => 1,
			),
			$counts
		);
	}

	// -- Plumbing ------------------------------------------------------------

	private function registry(): FacetRegistry {
		$registry = new FacetRegistry();

		$registry->register(
			FacetDefinition::terms( 'topic', 'se_is_taxid_topic', 'Topic', FieldType::INT )
		);
		$registry->register(
			FacetDefinition::hierarchy( 'category', 'category', 'se_ss_taxpath_category', 'Category' )
		);
		$registry->register(
			FacetDefinition::range( 'price', 'se_f_meta_price', 'Price', 0.0, 1500.0, 500.0 )
		);

		return $registry;
	}

	private function filters(): FilterRegistry {
		$filters = new FilterRegistry();

		$filters->register( 'topic', 'se_is_taxid_topic', FieldType::INT, 'Topic' );
		$filters->register( 'category', 'se_ss_taxpath_category', FieldType::STRING, 'Category' );
		$filters->register_bands( 'price', 'se_f_meta_price', 500.0, 'Price' );

		return $filters;
	}

	/**
	 * Run a query and return the parsed results.
	 *
	 * @param SearchQuery        $query    Query to run.
	 * @param FacetRegistry|null $registry Facets to count.
	 * @return \SolrEngine\Contracts\SearchResults
	 */
	private function parse( SearchQuery $query, ?FacetRegistry $registry = null ) {
		$registry = $registry ?? $this->registry();

		$translator = new QueryTranslator( new RelevanceProfile(), $this->filters(), array(), $registry );
		$response   = self::call( 'GET', '/select', null, $translator->translate( $query ) );

		return ( new ResponseParser( $registry ) )->parse( $response, $query );
	}

	/**
	 * Facet results for a query.
	 *
	 * @param SearchQuery        $query    Query to run.
	 * @param FacetRegistry|null $registry Facets to count.
	 * @return array<string,\SolrEngine\Contracts\FacetResult>
	 */
	private function facets( SearchQuery $query, ?FacetRegistry $registry = null ): array {
		return $this->parse( $query, $registry )->facets();
	}

	/**
	 * Bucket counts keyed by value.
	 *
	 * @param array<string,\SolrEngine\Contracts\FacetResult> $facets Facet results.
	 * @param string                                          $key    Facet key.
	 * @return array<string,int>
	 */
	private function counts( array $facets, string $key ): array {
		$out = array();

		foreach ( $facets[ $key ]->buckets() as $bucket ) {
			if ( $bucket->count() > 0 ) {
				$out[ $bucket->value() ] = $bucket->count();
			}
		}

		return $out;
	}

	/**
	 * Documents the assertions depend on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function fixtures(): array {
		$make = static function ( int $id, array $fields, int $site = self::SITE, string $visibility = 'public' ): array {
			return array_merge(
				array(
					'id'                        => sprintf( 'se:%d:post:%d', $site, $id ),
					DocumentFields::SITE        => $site,
					DocumentFields::OBJECT_TYPE => 'post',
					DocumentFields::OBJECT_ID   => $id,
					DocumentFields::POST_TYPE   => 'post',
					DocumentFields::VISIBILITY  => $visibility,
					DocumentFields::TITLE       => 'Facet fixture ' . $id,
					DocumentFields::URL         => 'https://example.test/' . $id,
				),
				$fields
			);
		};

		return array(
			$make(
				8001,
				array(
					'se_is_taxid_topic'      => array( 42 ),
					'se_ss_taxpath_category' => array( '0/12', '1/12/34' ),
					'se_f_meta_price'        => 100.0,
				)
			),
			$make(
				8002,
				array(
					'se_is_taxid_topic'      => array( 42 ),
					'se_ss_taxpath_category' => array( '0/12' ),
					'se_f_meta_price'        => 300.0,
				)
			),
			$make(
				8003,
				array(
					'se_is_taxid_topic'      => array( 17 ),
					'se_ss_taxpath_category' => array( '0/12', '1/12/34', '2/12/34/56' ),
					'se_f_meta_price'        => 700.0,
				)
			),
			$make(
				8004,
				array(
					'se_is_taxid_topic'      => array( 17 ),
					'se_ss_taxpath_category' => array( '0/99' ),
					'se_f_meta_price'        => 1200.0,
				)
			),
			// Must never be counted: not public.
			$make(
				8005,
				array(
					'se_is_taxid_topic'      => array( 42 ),
					'se_ss_taxpath_category' => array( '0/12' ),
					'se_f_meta_price'        => 100.0,
				),
				self::SITE,
				'private'
			),
			// Must never be counted: another site in the same collection.
			$make(
				8006,
				array(
					'se_is_taxid_topic'      => array( 42 ),
					'se_ss_taxpath_category' => array( '0/12' ),
					'se_f_meta_price'        => 100.0,
				),
				self::OTHER_SITE
			),
		);
	}

	/**
	 * Perform an HTTP request, encoding repeated parameters as Solr needs.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path within the collection.
	 * @param mixed               $body   JSON payload, or null.
	 * @param array<string,mixed> $query  Query parameters.
	 * @return array<string,mixed>
	 */
	private static function call( string $method, string $path, $body, array $query ): array {
		$query['wt'] = 'json';
		$pairs       = array();

		foreach ( $query as $key => $value ) {
			foreach ( (array) $value as $item ) {
				$rendered = is_bool( $item ) ? ( $item ? 'true' : 'false' ) : (string) $item;
				$pairs[]  = rawurlencode( (string) $key ) . '=' . rawurlencode( $rendered );
			}
		}

		$url = self::$base . '/' . self::$collection . $path . '?' . implode( '&', $pairs );

		$options = array(
			'http' => array(
				'method'        => $method,
				'timeout'       => 30,
				'ignore_errors' => true,
				'header'        => "Accept: application/json\r\n",
			),
		);

		if ( null !== $body ) {
			$options['http']['header'] .= "Content-Type: application/json\r\n";
			$options['http']['content'] = (string) wp_json_encode( $body );
		}

		set_error_handler( static fn(): bool => true );
		$raw = file_get_contents( $url, false, stream_context_create( $options ) );
		restore_error_handler();

		if ( false === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );

		return is_array( $decoded ) ? $decoded : array();
	}
}
