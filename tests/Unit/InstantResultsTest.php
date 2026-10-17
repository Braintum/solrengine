<?php
/**
 * InstantResults tests.
 *
 * Both of the search popup's surfaces call this class and render what it
 * returns, so these tests are the contract the browser relies on: the shape of
 * the body, which tab counts appear, and what a price means.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\Breaker;
use SolrEngine\Contracts\Cache;
use SolrEngine\Contracts\Capabilities;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\FacetBucket;
use SolrEngine\Contracts\FacetResult;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Contracts\Viewer;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\InstantResults;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\NullLogger;

/**
 * @covers \SolrEngine\Search\InstantResults
 */
final class InstantResultsTest extends TestCase {

	/**
	 * The backend double, so tests can read what it was asked.
	 *
	 * @var SearchBackend&object{calls:int,last:?SearchQuery,queries:SearchQuery[]}
	 */
	private $backend;

	/**
	 * Build the class over a backend that answers with a fixed result, or
	 * with whatever a callable returns for each query.
	 *
	 * @param Result|callable $result       What the backend returns.
	 * @param string[]        $post_types   Indexed post types.
	 * @param int             $per_page     Results per page on a type tab.
	 * @param int             $section_size Results per section on All.
	 * @return InstantResults
	 */
	private function instant( $result, array $post_types = array( 'product', 'page', 'post' ), int $per_page = InstantResults::PER_PAGE, int $section_size = InstantResults::SECTION_SIZE ): InstantResults {
		$this->backend = $this->backend( $result );

		$service = new SearchService(
			$this->backend,
			new NullLogger(),
			0,
			$this->viewer(),
			$this->cache(),
			$this->breaker()
		);

		return new InstantResults( $service, $post_types, $per_page, $section_size );
	}

	// -- The query -----------------------------------------------------------

	public function test_query_counts_tabs_and_restricts_to_indexed_types(): void {
		$query = $this->instant( Result::success() )->query( 'lamp', '', 1 );

		$this->assertSame( 'lamp', $query->terms() );
		$this->assertSame( array( 'product', 'page', 'post' ), $query->post_types() );
		$this->assertSame( array( InstantResults::TAB_KEY => '' ), $query->facets() );
		$this->assertSame( array(), $query->filters() );
		$this->assertSame( 1, $query->page() );
		$this->assertSame( InstantResults::PER_PAGE, $query->per_page() );
	}

	public function test_selected_tab_is_a_filter_not_a_narrower_post_type_list(): void {
		// Narrowing post_types would zero every other tab: that restriction is
		// not tagged, so the facet cannot exclude it from its own counts.
		$query = $this->instant( Result::success() )->query( 'lamp', 'product', 1 );

		$this->assertSame( array( 'product', 'page', 'post' ), $query->post_types() );
		$this->assertSame( array( InstantResults::TAB_KEY => array( 'product' ) ), $query->filters() );
	}

	public function test_selected_tab_is_excluded_from_its_own_counts_on_the_wire(): void {
		// The guarantee the tab row depends on, checked where it is made.
		$translator = new QueryTranslator(
			new RelevanceProfile(),
			FilterRegistry::defaults(),
			array(),
			FacetRegistry::defaults()
		);

		$params = $translator->translate( $this->instant( Result::success() )->query( 'lamp', 'product', 1 ) );
		$tag    = FacetRegistry::tag_for( InstantResults::TAB_KEY );

		$tagged = array_values(
			array_filter(
				(array) $params['fq'],
				static function ( $clause ) use ( $tag ): bool {
					return 0 === strpos( (string) $clause, '{!tag=' . $tag . '}' );
				}
			)
		);

		$this->assertCount( 1, $tagged );
		$this->assertStringContainsString( 'product', $tagged[0] );
		$this->assertStringContainsString( '"excludeTags":["' . $tag . '"]', (string) $params['json.facet'] );
	}

	public function test_unknown_tab_reads_as_all(): void {
		$instant = $this->instant( Result::success() );

		$this->assertSame( '', $instant->tab( 'attachment' ) );
		$this->assertSame( array(), $instant->query( 'lamp', $instant->tab( 'attachment' ), 1 )->filters() );
	}

	public function test_page_and_section_sizes_are_capped(): void {
		$instant = $this->instant( $this->sections( array( 'post' => 1 ) ), array( 'post' ), 500, 500 );

		$this->assertSame( InstantResults::MAX_PER_PAGE, $instant->query( 'lamp', 'post', 1 )->per_page() );

		$instant->answer( 'lamp', '', 1 );
		$this->assertSame( InstantResults::MAX_SECTION_SIZE, $this->backend->last->per_page() );
	}

	public function test_text_is_collapsed_and_capped(): void {
		$long = str_repeat( 'a', InstantResults::MAX_LENGTH + 50 );

		$this->instant( $this->results( array() ) )->answer( "  desk \n\t lamp  ", '', 1 );
		$this->assertSame( 'desk lamp', $this->backend->last->terms() );

		$this->instant( $this->results( array() ) )->answer( $long, '', 1 );
		$this->assertSame( InstantResults::MAX_LENGTH, mb_strlen( $this->backend->last->terms() ) );
	}

	// -- Answering -----------------------------------------------------------

	public function test_short_text_answers_empty_without_asking_the_backend(): void {
		$result = $this->instant( $this->results( array() ) )->answer( 'a', 'post', 1 );

		$this->assertFalse( $result->is_failure() );
		$this->assertSame( 0, $this->backend->calls );
		$this->assertSame( 'post', $result->value()['post_type'] );
		$this->assertSame( array(), $result->value()['results'] );
		$this->assertSame( '{}', json_encode( $result->value()['counts'] ) );
	}

	public function test_minimum_length_matches_autocomplete(): void {
		$this->instant( $this->results( array() ) )->answer( str_repeat( 'x', SuggestQuery::MIN_LENGTH ), '', 1 );

		$this->assertSame( 1, $this->backend->calls );
	}

	public function test_backend_failure_is_passed_through(): void {
		foreach ( array( '', 'post' ) as $tab ) {
			$result = $this->instant( Result::failure( 'unreachable', 'Down.' ) )->answer( 'lamp', $tab, 1 );

			$this->assertTrue( $result->is_failure() );
			$this->assertSame( 'unreachable', $result->code() );
		}
	}

	// -- The All tab ---------------------------------------------------------

	public function test_all_tab_is_a_section_per_type_in_the_sites_order(): void {
		$body = $this->instant(
			$this->sections(
				array(
					'post'    => 12,
					'product' => 341,
					'page'    => 0,
				)
			)
		)->answer( 'quis', '', 1 )->value();

		$this->assertSame( array( 'product', 'post' ), array_column( $body['sections'], 'post_type' ) );
		$this->assertSame( array( 341, 12 ), array_column( $body['sections'], 'total' ) );
		$this->assertSame( 'product', $body['sections'][0]['results'][0]['post_type'] );
		$this->assertCount( InstantResults::SECTION_SIZE, $body['sections'][0]['results'] );
		$this->assertSame( 353, $body['total'] );
		$this->assertSame( 353, $body['all'] );
		$this->assertSame( array(), $body['results'] );
		$this->assertFalse( $body['has_more'] );
	}

	public function test_all_tab_counts_first_then_asks_once_per_type_with_a_match(): void {
		$this->instant(
			$this->sections(
				array(
					'post'    => 12,
					'product' => 341,
					'page'    => 0,
				)
			)
		)->answer( 'quis', '', 1 );

		$queries = $this->backend->queries;

		// Counts without documents, then product and post. No query for page,
		// which has nothing to show.
		$this->assertCount( 3, $queries );
		$this->assertFalse( $queries[0]->wants_documents() );
		$this->assertSame( array(), $queries[0]->filters() );

		$this->assertSame( array( InstantResults::TAB_KEY => array( 'product' ) ), $queries[1]->filters() );
		$this->assertSame( array( InstantResults::TAB_KEY => array( 'post' ) ), $queries[2]->filters() );
		$this->assertSame( InstantResults::SECTION_SIZE, $queries[1]->per_page() );
		$this->assertSame( 1, $queries[2]->page() );
	}

	public function test_all_tab_ignores_a_page(): void {
		$body = $this->instant( $this->sections( array( 'post' => 3 ) ) )->answer( 'quis', '', 1, 7 )->value();

		$this->assertSame( 1, $body['page'] );
		$this->assertSame( 1, $this->backend->last->page() );
	}

	public function test_all_tab_fails_whole_when_a_section_fails(): void {
		$counts = $this->sections( array( 'product' => 2 ) );

		$result = $this->instant(
			static function ( SearchQuery $query ) use ( $counts ): Result {
				return $query->wants_documents() ? Result::failure( 'timeout', 'Slow.' ) : $counts( $query );
			}
		)->answer( 'quis', '', 1 );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'timeout', $result->code() );
	}

	// -- Type tabs and paging ------------------------------------------------

	public function test_type_tab_answers_the_page_asked_for(): void {
		$body = $this->instant( $this->results( array( $this->hit( 1, 'product', 'Quis' ) ), array( 'product' => 341 ), 341 ) )
			->answer( 'quis', 'product', 1, 3 )
			->value();

		$this->assertSame( 3, $this->backend->last->page() );
		$this->assertSame( InstantResults::PER_PAGE, $this->backend->last->per_page() );
		$this->assertSame( 3, $body['page'] );
		$this->assertSame( InstantResults::PER_PAGE, $body['per_page'] );
		$this->assertSame( 341, $body['total'] );
		$this->assertTrue( $body['has_more'] );
		$this->assertSame( array(), $body['sections'] );
	}

	public function test_last_page_has_no_more(): void {
		// 341 results, 24 a page: page 15 holds 337–341.
		$body = $this->instant( $this->results( array(), array( 'product' => 341 ), 341 ) )
			->answer( 'quis', 'product', 1, 15 )
			->value();

		$this->assertFalse( $body['has_more'] );

		$body = $this->instant( $this->results( array(), array( 'product' => 48 ), 48 ) )
			->answer( 'quis', 'product', 1, 2 )
			->value();

		$this->assertFalse( $body['has_more'] );
	}

	public function test_paging_stops_at_the_deepest_result_served(): void {
		$instant = $this->instant( $this->results( array(), array( 'product' => 50000 ), 50000 ) );
		$last    = (int) ceil( InstantResults::MAX_RESULTS / InstantResults::PER_PAGE );

		$this->assertSame( $last, $instant->page( 100000 ) );
		$this->assertSame( 1, $instant->page( -4 ) );

		$body = $instant->answer( 'quis', 'product', 1, 100000 )->value();

		$this->assertSame( $last, $this->backend->last->page() );
		$this->assertFalse( $body['has_more'] );
	}

	public function test_body_shape(): void {
		$body = $this->instant(
			$this->results(
				array( $this->hit( 12, 'product', 'Form desk lamp' ) ),
				array(
					'post'       => 1,
					'product'    => 2,
					'page'       => 2,
					// Not indexed any more. Never becomes a tab.
					'attachment' => 9,
				)
			)
		)->answer( 'lamp', 'product', 1 )->value();

		$this->assertSame(
			array( 'query', 'post_type', 'total', 'all', 'counts', 'page', 'per_page', 'has_more', 'results', 'sections', 'took_ms', 'strategy' ),
			array_keys( $body )
		);
		$this->assertSame( 'lamp', $body['query'] );
		$this->assertSame( 'product', $body['post_type'] );
		$this->assertSame( 5, $body['all'] );

		// Ordered by the site's post types, not by Solr's bucket order, so the
		// tab row does not reshuffle as the counts change.
		$this->assertSame(
			'{"product":2,"page":2,"post":1}',
			json_encode( $body['counts'] )
		);

		$this->assertSame(
			array( 'id', 'type', 'post_type', 'title', 'excerpt', 'url', 'thumbnail', 'date', 'author' ),
			array_keys( $body['results'][0] )
		);
		$this->assertSame( 12, $body['results'][0]['id'] );
		$this->assertSame( 'Form desk lamp', $body['results'][0]['title'] );
	}

	public function test_zero_count_types_are_not_tabs(): void {
		$body = $this->instant(
			$this->results(
				array(),
				array(
					'post' => 0,
					'page' => 3,
				)
			)
		)
			->answer( 'lamp', '', 1 )
			->value();

		$this->assertSame( '{"page":3}', json_encode( $body['counts'] ) );
	}

	public function test_multi_valued_field_shows_its_first_value(): void {
		$hit = new SearchHit(
			new DocumentRef( 1, 'post', 4 ),
			1.0,
			array( DocumentFields::TITLE => array( 'First', 'Second' ) )
		);

		$body = $this->instant( $this->results( array( $hit ) ) )->answer( 'lamp', 'post', 1 )->value();

		$this->assertSame( 'First', $body['results'][0]['title'] );
	}

	public function test_long_excerpt_is_cut_at_a_word(): void {
		$excerpt = str_repeat( 'calm design ', 40 );
		$hit     = $this->hit( 4, 'post', 'Calm', array( DocumentFields::EXCERPT => $excerpt ) );
		$body    = $this->instant( $this->results( array( $hit ) ) )->answer( 'calm', 'post', 1 )->value();
		$short   = $body['results'][0]['excerpt'];

		$this->assertLessThanOrEqual( 181, mb_strlen( $short ) );
		$this->assertStringEndsWith( '…', $short );
		$this->assertStringNotContainsString( 'desig…', $short );
	}

	// -- Prices --------------------------------------------------------------

	public function test_documents_without_a_price_carry_no_price_key(): void {
		$body = $this->instant( $this->results( array( $this->hit( 4, 'page', 'About' ) ) ) )
			->answer( 'about', 'page', 1 )
			->value();

		$this->assertArrayNotHasKey( 'price', $body['results'][0] );
	}

	public function test_sale_price_carries_the_regular_price_to_strike_through(): void {
		$price = $this->price_of(
			array(
				ProductFields::PRICE         => 89.0,
				ProductFields::REGULAR_PRICE => 110.0,
				ProductFields::PRICE_MIN     => 89.0,
				ProductFields::PRICE_MAX     => 89.0,
			)
		);

		$this->assertSame(
			array(
				'current' => 89.0,
				'regular' => 110.0,
			),
			$price
		);
	}

	public function test_regular_price_equal_to_current_is_not_a_sale(): void {
		// WooCommerce can flag a product on sale with no sale price set. A
		// struck-through price identical to the real one reads as a bug.
		$price = $this->price_of(
			array(
				ProductFields::PRICE         => 36.82,
				ProductFields::REGULAR_PRICE => 36.82,
			)
		);

		$this->assertSame( array( 'current' => 36.82 ), $price );
	}

	public function test_variable_product_carries_its_range(): void {
		$price = $this->price_of(
			array(
				ProductFields::PRICE     => 10.0,
				ProductFields::PRICE_MIN => 10.0,
				ProductFields::PRICE_MAX => 25.5,
			)
		);

		$this->assertSame(
			array(
				'current' => 10.0,
				'min'     => 10.0,
				'max'     => 25.5,
			),
			$price
		);
	}

	public function test_tax_inclusive_price_is_passed_on_when_indexed(): void {
		$price = $this->price_of(
			array(
				ProductFields::PRICE       => 100.0,
				ProductFields::PRICE_TAXED => 119.0,
			)
		);

		$this->assertSame(
			array(
				'current'          => 100.0,
				'current_incl_tax' => 119.0,
			),
			$price
		);
	}

	public function test_nonsense_prices_are_dropped(): void {
		$this->assertNull( $this->price_of( array( ProductFields::PRICE => 'free' ) ) );
		$this->assertNull( $this->price_of( array( ProductFields::PRICE => -5 ) ) );
	}

	public function test_body_encodes_as_json(): void {
		$body = $this->instant(
			$this->results(
				array(
					$this->hit(
						12,
						'product',
						'Arc lounge chair — “oak”',
						array( ProductFields::PRICE => 349 )
					),
				),
				array( 'product' => 1 )
			)
		)->answer( 'chair', '', 1 )->value();

		$this->assertNotFalse( json_encode( $body ) );
		$this->assertSame( 'Arc lounge chair — “oak”', $body['sections'][0]['results'][0]['title'] );
	}

	// -- Helpers -------------------------------------------------------------

	/**
	 * The price block a hit with these fields produces.
	 *
	 * @param array<string,mixed> $fields Product fields.
	 * @return array<string,float>|null
	 */
	private function price_of( array $fields ): ?array {
		$body = $this->instant( $this->results( array( $this->hit( 7, 'product', 'Lamp', $fields ) ) ) )
			->answer( 'lamp', 'product', 1 )
			->value();

		return $body['results'][0]['price'] ?? null;
	}

	/**
	 * A hit.
	 *
	 * @param int                 $id        Object id.
	 * @param string              $post_type Post type.
	 * @param string              $title     Title.
	 * @param array<string,mixed> $extra     More fields.
	 * @return SearchHit
	 */
	private function hit( int $id, string $post_type, string $title, array $extra = array() ): SearchHit {
		return new SearchHit(
			new DocumentRef( 1, 'post', $id ),
			1.0,
			array(
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::POST_TYPE   => $post_type,
				DocumentFields::TITLE       => $title,
				DocumentFields::URL         => 'https://example.com/?p=' . $id,
			) + $extra
		);
	}

	/**
	 * A backend responder for the All tab: the counts query gets these
	 * counts; a section query gets a full section of hits of its type.
	 *
	 * @param array<string,int> $counts post_type bucket counts.
	 * @return callable
	 */
	private function sections( array $counts ): callable {
		return function ( SearchQuery $query ) use ( $counts ): Result {
			$type = $query->filters()[ InstantResults::TAB_KEY ][0] ?? '';

			if ( '' === $type || ! $query->wants_documents() ) {
				return $this->results( array(), $counts, array_sum( $counts ) );
			}

			$hits  = array();
			$shown = min( $query->per_page(), $counts[ $type ] ?? 0 );

			for ( $i = 1; $i <= $shown; $i++ ) {
				$hits[] = $this->hit( $i, $type, ucfirst( $type ) . ' ' . $i );
			}

			return $this->results( $hits, $counts, $counts[ $type ] ?? 0 );
		};
	}

	/**
	 * A successful search result.
	 *
	 * @param SearchHit[]       $hits   Hits.
	 * @param array<string,int> $counts post_type bucket counts.
	 * @param int|null          $total  Matches; the number of hits when null.
	 * @return Result
	 */
	private function results( array $hits, array $counts = array(), ?int $total = null ): Result {
		$buckets = array();

		foreach ( $counts as $type => $count ) {
			$buckets[] = new FacetBucket( (string) $type, $count );
		}

		$results = ( new SearchResults( $hits, $total ?? count( $hits ), new SearchQuery( 'x', 1 ), 4 ) )
			->with_facets(
				array( InstantResults::TAB_KEY => new FacetResult( InstantResults::TAB_KEY, 'Content type', 'terms', $buckets ) )
			);

		return Result::success( $results );
	}

	/**
	 * A backend answering search with a fixed result, or a callable's answer
	 * for each query, recording every query.
	 *
	 * @param Result|callable $result What search() returns.
	 * @return SearchBackend
	 */
	private function backend( $result ): SearchBackend {
		return new class( $result ) implements SearchBackend {
			/**
			 * @var Result|callable
			 */
			private $result;

			/**
			 * @var int
			 */
			public $calls = 0;

			/**
			 * @var SearchQuery|null
			 */
			public $last;

			/**
			 * @var SearchQuery[]
			 */
			public $queries = array();

			/**
			 * @param Result|callable $result Result to return.
			 */
			public function __construct( $result ) {
				$this->result = $result;
			}

			/**
			 * @return string
			 */
			public function id(): string {
				return 'fake';
			}

			/**
			 * @return Result
			 */
			public function ping(): Result {
				return Result::success();
			}

			/**
			 * @return Capabilities
			 */
			public function capabilities(): Capabilities {
				return new Capabilities();
			}

			/**
			 * @param SearchQuery $query Query.
			 * @return Result
			 */
			public function search( SearchQuery $query ): Result {
				++$this->calls;
				$this->last      = $query;
				$this->queries[] = $query;

				return $this->result instanceof Result ? $this->result : ( $this->result )( $query );
			}

			/**
			 * @param SuggestQuery $query Query.
			 * @return Result
			 */
			public function suggest( SuggestQuery $query ): Result {
				return Result::failure( 'unused', 'Not used here.' );
			}
		};
	}

	/**
	 * An anonymous viewer.
	 *
	 * @return Viewer
	 */
	private function viewer(): Viewer {
		return new class() implements Viewer {
			/**
			 * @return bool
			 */
			public function is_authenticated(): bool {
				return false;
			}
		};
	}

	/**
	 * A cache that holds nothing.
	 *
	 * @return Cache
	 */
	private function cache(): Cache {
		return new class() implements Cache {
			/**
			 * @param string $key Key.
			 * @return mixed
			 */
			public function get( string $key ) {
				return null;
			}

			/**
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 * @param int    $ttl   TTL.
			 * @return void
			 */
			public function set( string $key, $value, int $ttl ): void {
			}
		};
	}

	/**
	 * A closed breaker.
	 *
	 * @return Breaker
	 */
	private function breaker(): Breaker {
		return new class() implements Breaker {
			/**
			 * @return bool
			 */
			public function is_open(): bool {
				return false;
			}

			/**
			 * @param string $code Code.
			 * @return void
			 */
			public function record_failure( string $code ): void {
			}

			/**
			 * @return void
			 */
			public function record_success(): void {
			}
		};
	}
}
