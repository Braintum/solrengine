<?php
/**
 * Shop queries against a real Solr.
 *
 * The WooCommerce mapping needs WooCommerce; what needs a live Solr is
 * everything the mapping feeds — that a negative context filter narrows
 * products without deleting the blog from the results, that requiring two
 * attribute values returns the products having both, and that a price band's
 * count matches the page a shopper lands on after clicking it.
 *
 * The documents here are written by hand rather than mapped from products,
 * deliberately: it keeps this suite free of WooCommerce and makes the exact
 * field values visible in the test.
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
use SolrEngine\Integration\WooCommerce\CatalogContext;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Integration\WooCommerce\ShopQuery;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\ResponseParser;

/**
 * @covers \SolrEngine\Integration\WooCommerce\ProductFields
 * @covers \SolrEngine\Search\FilterRegistry
 */
final class ProductQueryTest extends TestCase {

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
	 * Site this suite owns. See the table in docs/development.md.
	 *
	 * @var int
	 */
	private const SITE = 801;

	/**
	 * A neighbouring site, which must never appear in these results.
	 *
	 * @var int
	 */
	private const OTHER_SITE = 802;

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

	// -- Visibility ----------------------------------------------------------

	public function test_a_product_hidden_from_search_stays_out_of_search(): void {
		$titles = $this->titles( $this->query()->with_filter( ShopQuery::CONTEXT_KEY, array( CatalogContext::SEARCH ) ) );

		$this->assertNotContains( 'Catalogue only clogs', $titles );
		$this->assertContains( 'Search only slippers', $titles );
	}

	public function test_a_product_hidden_from_the_catalogue_stays_out_of_the_catalogue(): void {
		$titles = $this->titles( $this->query()->with_filter( ShopQuery::CONTEXT_KEY, array( CatalogContext::CATALOG ) ) );

		$this->assertContains( 'Catalogue only clogs', $titles );
		$this->assertNotContains( 'Search only slippers', $titles );
	}

	public function test_a_context_filter_does_not_remove_the_rest_of_the_site(): void {
		// The failure this guards against would be invisible in a shop-only
		// test and catastrophic on a real site: a positive "may appear here"
		// filter deletes every post and page from search, because none of them
		// carries the field at all.
		$titles = $this->titles(
			( new SearchQuery( '', self::SITE ) )->with_filter( ShopQuery::CONTEXT_KEY, array( CatalogContext::SEARCH ) )
		);

		$this->assertContains( 'A blog post about boots', $titles );
	}

	public function test_a_store_hiding_out_of_stock_products_keeps_its_blog(): void {
		$titles = $this->titles(
			( new SearchQuery( '', self::SITE ) )->with_filter( ShopQuery::STOCK_EXCLUSION_KEY, array( 'outofstock' ) )
		);

		$this->assertNotContains( 'Summer sandals', $titles );
		$this->assertContains( 'A blog post about boots', $titles );
	}

	public function test_another_sites_products_never_appear(): void {
		$this->assertNotContains( 'Boots from another shop', $this->titles( $this->query() ) );
	}

	// -- Attributes ----------------------------------------------------------

	public function test_requiring_two_attribute_values_returns_products_having_both(): void {
		$query = $this->query()->with_filter( 'pa_color', array( 21, 22 ), 'and' );

		$this->assertSame( array( 'Winter boots' ), $this->titles( $query ) );
	}

	public function test_allowing_either_attribute_value_returns_products_having_one(): void {
		$titles = $this->titles( $this->query()->with_filter( 'pa_color', array( 21, 22 ), 'or' ) );

		sort( $titles );

		$this->assertSame( array( 'Summer sandals', 'Trail boots', 'Winter boots' ), $titles );
	}

	// -- Price ---------------------------------------------------------------

	public function test_a_price_range_narrows_to_that_range(): void {
		$titles = $this->titles( $this->query()->with_filter( 'price_range', array( '50:100' ) ) );

		sort( $titles );

		// Both products priced inside the range, and nothing else. The
		// catalogue-only one belongs here: this query carries no context, so
		// nothing has asked for it to be left out.
		$this->assertSame( array( 'Catalogue only clogs', 'Trail boots' ), $titles );
	}

	public function test_a_price_band_count_matches_the_page_it_leads_to(): void {
		// The property a filter panel lives or dies by. A band offering
		// "3 products" that leads to a page of two is worse than no panel.
		$facets = $this->facets( $this->query()->with_facets( array( 'price' ) ) );

		foreach ( $facets['price']->buckets() as $bucket ) {
			if ( $bucket->count() < 1 ) {
				continue;
			}

			$selected = $this->query()->with_filter( 'price', array( $bucket->value() ) );

			$this->assertSame(
				$bucket->count(),
				count( $this->titles( $selected ) ),
				'Band ' . $bucket->value() . ' disagrees with its own results'
			);
		}
	}

	public function test_products_sort_by_price(): void {
		$cheapest = $this->titles( $this->query()->with_sort( 'price_asc' ) );
		$dearest  = $this->titles( $this->query()->with_sort( 'price_desc' ) );

		$this->assertSame( 'Search only slippers', $cheapest[0] );
		$this->assertSame( 'Winter boots', $dearest[0] );
	}

	public function test_products_sort_by_popularity_and_rating(): void {
		$this->assertSame( 'Winter boots', $this->titles( $this->query()->with_sort( 'popularity' ) )[0] );
		$this->assertSame( 'Trail boots', $this->titles( $this->query()->with_sort( 'rating' ) )[0] );
	}

	// -- Search --------------------------------------------------------------

	public function test_a_variation_sku_finds_its_parent_product(): void {
		// The reason every SKU, parent and child, lands in one analysed field:
		// somebody typing a variation's code expects the product that sells it.
		$titles = $this->titles( new SearchQuery( 'WB-42-RED', self::SITE ) );

		$this->assertSame( array( 'Winter boots' ), $titles );
	}

	public function test_an_attribute_value_is_searchable_as_text(): void {
		$this->assertContains( 'Winter boots', $this->titles( new SearchQuery( 'Sapphire', self::SITE ) ) );
	}

	// -- Plumbing ------------------------------------------------------------

	private function query(): SearchQuery {
		return ( new SearchQuery( '', self::SITE ) )->with_post_types( array( 'product' ) );
	}

	private function filters(): FilterRegistry {
		$filters = new FilterRegistry();

		$filters->register( 'pa_color', ProductFields::attribute( 'pa_color' ), FieldType::INT, 'Colour' );
		$filters->register( 'stock_status', ProductFields::STOCK_STATUS, FieldType::STRING, 'Availability' );
		$filters->register_bands( 'price', ProductFields::PRICE_MIN, 50.0, 'Price' );
		$filters->register_range( 'price_range', ProductFields::PRICE_MIN, 'Price range' );
		$filters->register_excluding( ShopQuery::CONTEXT_KEY, ProductFields::EXCLUDED, FieldType::STRING, 'Context' );
		$filters->register_excluding( ShopQuery::STOCK_EXCLUSION_KEY, ProductFields::STOCK_STATUS, FieldType::STRING, 'Hidden stock' );

		return $filters;
	}

	private function facet_registry(): FacetRegistry {
		$registry = new FacetRegistry();

		$registry->register(
			FacetDefinition::range( 'price', ProductFields::PRICE_MIN, 'Price', 0.0, 200.0, 50.0 )
		);

		return $registry;
	}

	/**
	 * Titles a query returns.
	 *
	 * @param SearchQuery $query Query to run.
	 * @return string[]
	 */
	private function titles( SearchQuery $query ): array {
		$results = $this->execute( $query );
		$titles  = array();

		foreach ( $results->hits() as $hit ) {
			$titles[] = (string) $hit->field( DocumentFields::TITLE, '' );
		}

		return $titles;
	}

	/**
	 * Facet results a query returns.
	 *
	 * @param SearchQuery $query Query to run.
	 * @return array<string,\SolrEngine\Contracts\FacetResult>
	 */
	private function facets( SearchQuery $query ): array {
		return $this->execute( $query )->facets();
	}

	/**
	 * Run a query.
	 *
	 * Not named run(): PHPUnit's TestCase already has one, and overriding it
	 * with a different signature is a fatal error.
	 *
	 * @param SearchQuery $query Query to run.
	 * @return \SolrEngine\Contracts\SearchResults
	 */
	private function execute( SearchQuery $query ) {
		$registry = $this->facet_registry();
		// Built the way the composition root builds it on a shop: the default
		// profile plus the fields WooCommerce contributes. Without them a SKU
		// is indexed and unsearchable, which is the kind of gap that only
		// shows up against a real server.
		$relevance  = RelevanceProfile::from_config( array(), ProductFields::relevance_boosts() );
		$translator = new QueryTranslator( $relevance, $this->filters(), array(), $registry );
		$response   = self::call( 'GET', '/select', null, $translator->translate( $query ) );

		return ( new ResponseParser( $registry ) )->parse( $response, $query );
	}

	/**
	 * Documents the assertions depend on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function fixtures(): array {
		$product = static function ( int $id, string $title, array $fields, int $site = self::SITE ): array {
			return array_merge(
				array(
					'id'                        => sprintf( 'se:%d:post:%d', $site, $id ),
					DocumentFields::SITE        => $site,
					DocumentFields::OBJECT_TYPE => 'post',
					DocumentFields::OBJECT_ID   => $id,
					DocumentFields::POST_TYPE   => 'product',
					DocumentFields::VISIBILITY  => 'public',
					DocumentFields::TITLE       => $title,
					DocumentFields::URL         => 'https://shop.test/' . $id,
					ProductFields::TYPE         => 'simple',
					ProductFields::STOCK_STATUS => 'instock',
				),
				$fields
			);
		};

		return array(
			$product(
				9001,
				'Trail boots',
				array(
					ProductFields::PRICE_MIN               => 80.0,
					ProductFields::PRICE_MAX               => 80.0,
					ProductFields::attribute( 'pa_color' ) => array( 21 ),
					ProductFields::ATTRIBUTE_TEXT          => array( 'Crimson' ),
					ProductFields::RATING                  => 4.8,
					ProductFields::SALES                   => 12,
					ProductFields::SKU_TEXT                => 'TB-40',
				)
			),
			$product(
				9002,
				'Winter boots',
				array(
					ProductFields::TYPE                    => 'variable',
					ProductFields::PRICE_MIN               => 120.0,
					ProductFields::PRICE_MAX               => 180.0,
					ProductFields::attribute( 'pa_color' ) => array( 21, 22 ),
					ProductFields::ATTRIBUTE_TEXT          => array( 'Crimson', 'Sapphire' ),
					ProductFields::RATING                  => 4.1,
					ProductFields::SALES                   => 90,
					// Every SKU, parent and children, in one analysed field.
					ProductFields::SKU_TEXT                => 'WB-42 WB-42-RED WB-42-BLUE',
					ProductFields::VARIATION_SKU           => array( 'WB-42-RED', 'WB-42-BLUE' ),
					ProductFields::VARIATIONS              => 2,
				)
			),
			$product(
				9003,
				'Summer sandals',
				array(
					ProductFields::PRICE_MIN               => 40.0,
					ProductFields::PRICE_MAX               => 40.0,
					ProductFields::attribute( 'pa_color' ) => array( 22 ),
					ProductFields::ATTRIBUTE_TEXT          => array( 'Sapphire' ),
					ProductFields::STOCK_STATUS            => 'outofstock',
					ProductFields::SALES                   => 3,
				)
			),
			$product(
				9004,
				'Catalogue only clogs',
				array(
					ProductFields::PRICE_MIN => 60.0,
					ProductFields::PRICE_MAX => 60.0,
					ProductFields::EXCLUDED  => array( CatalogContext::SEARCH ),
				)
			),
			$product(
				9005,
				'Search only slippers',
				array(
					ProductFields::PRICE_MIN => 30.0,
					ProductFields::PRICE_MAX => 30.0,
					ProductFields::EXCLUDED  => array( CatalogContext::CATALOG ),
				)
			),
			$product(
				9006,
				'Boots from another shop',
				array(
					ProductFields::PRICE_MIN => 99.0,
					ProductFields::PRICE_MAX => 99.0,
				),
				self::OTHER_SITE
			),
			// Not a product. Nothing a shop rule does may remove it.
			array(
				'id'                        => sprintf( 'se:%d:post:9100', self::SITE ),
				DocumentFields::SITE        => self::SITE,
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::OBJECT_ID   => 9100,
				DocumentFields::POST_TYPE   => 'post',
				DocumentFields::VISIBILITY  => 'public',
				DocumentFields::TITLE       => 'A blog post about boots',
				DocumentFields::URL         => 'https://shop.test/blog',
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
