<?php
/**
 * What the shop integration will and will not answer.
 *
 * The most important assertions here are the refusals. A shop query can carry
 * constraints from WooCommerce, an extension or a theme, and answering one that
 * is only partly understood shows a shopper a page that looks filtered and is
 * not. Every refusal below is a query that goes back to the database instead.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Integration\WooCommerce\CatalogContext;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Integration\WooCommerce\ShopQuery;
use SolrEngine\Search\FilterRegistry;

/**
 * @covers \SolrEngine\Integration\WooCommerce\ShopQuery
 */
final class ShopQueryTest extends TestCase {

	/**
	 * A registry holding the filters a shop uses.
	 */
	private function filters(): FilterRegistry {
		$filters = new FilterRegistry();

		$filters->register( 'product_cat', 'se_ss_taxpath_product_cat', FieldType::STRING, 'Categories' );
		$filters->register( 'product_tag', 'se_is_taxid_product_tag', FieldType::INT, 'Tags' );
		$filters->register( 'pa_color', ProductFields::attribute( 'pa_color' ), FieldType::INT, 'Colour' );
		$filters->register( 'stock_status', ProductFields::STOCK_STATUS, FieldType::STRING, 'Availability' );
		$filters->register( 'on_sale', ProductFields::ON_SALE, FieldType::BOOL, 'On sale' );
		$filters->register_range( 'price_range', ProductFields::PRICE_MIN, 'Price' );
		$filters->register_excluding( ShopQuery::CONTEXT_KEY, ProductFields::EXCLUDED, FieldType::STRING, 'Context' );
		$filters->register_excluding( ShopQuery::STOCK_EXCLUSION_KEY, ProductFields::STOCK_STATUS, FieldType::STRING, 'Hidden stock' );

		return $filters;
	}

	/**
	 * A resolver that pretends every term exists.
	 *
	 * Hierarchical categories resolve to a path, everything else to an
	 * identifier, which is what the two registries expect.
	 */
	private function resolver(): callable {
		return static function ( string $taxonomy, $term ) {
			$known = array(
				'shoes' => 11,
				'boots' => 12,
				'red'   => 21,
				'blue'  => 22,
			);

			$id = is_numeric( $term ) ? (int) $term : ( $known[ (string) $term ] ?? 0 );

			if ( 0 === $id ) {
				return null;
			}

			return 'product_cat' === $taxonomy ? '0/' . $id : (string) $id;
		};
	}

	private function shop( bool $hide_out_of_stock = false ): ShopQuery {
		return new ShopQuery( $this->filters(), 1, $this->resolver(), $hide_out_of_stock );
	}

	// -- Refusals ------------------------------------------------------------

	public function test_a_hand_picked_list_of_products_is_refused(): void {
		// Something knows which products belong here and the index does not.
		$query = $this->shop()->for_query(
			array( 'post__in' => array( 4, 5, 6 ) ),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNull( $query );
	}

	public function test_a_meta_query_is_refused(): void {
		$query = $this->shop()->for_query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- A fixture, not a query.
				'meta_query' => array(
					array(
						'key'   => '_custom',
						'value' => 'x',
					),
				),
			),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNull( $query );
	}

	public function test_a_rating_filter_is_refused(): void {
		// WooCommerce's rating filter is not registered here. Answering the
		// query anyway would ignore what the shopper asked for.
		$query = $this->shop()->for_query( array(), array( 'rating_filter' => '4' ), CatalogContext::CATALOG );

		$this->assertNull( $query );
	}

	public function test_a_random_ordering_is_refused(): void {
		$query = $this->shop()->for_query( array( 'orderby' => 'rand' ), array(), CatalogContext::CATALOG );

		$this->assertNull( $query );
	}

	public function test_an_ordering_nobody_recognises_is_refused(): void {
		$query = $this->shop()->for_query( array( 'orderby' => 'stock_wizardry' ), array(), CatalogContext::CATALOG );

		$this->assertNull( $query );
	}

	public function test_a_term_that_does_not_resolve_is_refused(): void {
		// Silently dropping it would widen the query, which on a shop means
		// showing products the shopper filtered out.
		$query = $this->shop()->for_query(
			array( 'product_cat' => 'a-category-that-went-away' ),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNull( $query );
	}

	public function test_a_taxonomy_with_no_registered_filter_is_refused(): void {
		$query = $this->shop()->for_query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A fixture, not a query.
				'tax_query' => array(
					array(
						'taxonomy' => 'private_thing',
						'terms'    => array( 5 ),
					),
				),
			),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNull( $query );
	}

	// -- Translation ---------------------------------------------------------

	public function test_a_plain_shop_page_is_answered(): void {
		$query = $this->shop()->for_query(
			array(
				'orderby'        => 'menu_order title',
				'posts_per_page' => 12,
				'paged'          => 2,
			),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNotNull( $query );
		$this->assertSame( 'menu_order', $query->sort() );
		$this->assertSame( 2, $query->page() );
		$this->assertSame( 12, $query->per_page() );
		$this->assertSame( array( 'product' ), $query->post_types() );
	}

	public function test_every_product_query_carries_its_catalogue_context(): void {
		$catalog = $this->shop()->for_query( array(), array(), CatalogContext::CATALOG );
		$search  = $this->shop()->for_query( array( 's' => 'boots' ), array(), CatalogContext::SEARCH );

		$this->assertSame( array( CatalogContext::CATALOG ), $catalog->filters()[ ShopQuery::CONTEXT_KEY ] );
		$this->assertSame( array( CatalogContext::SEARCH ), $search->filters()[ ShopQuery::CONTEXT_KEY ] );
	}

	public function test_a_store_hiding_out_of_stock_products_excludes_them(): void {
		$query = $this->shop( true )->for_query( array(), array(), CatalogContext::CATALOG );

		$this->assertSame( array( 'outofstock' ), $query->filters()[ ShopQuery::STOCK_EXCLUSION_KEY ] );
	}

	public function test_a_category_archive_filters_by_path(): void {
		$query = $this->shop()->for_query( array( 'product_cat' => 'shoes' ), array(), CatalogContext::CATALOG );

		// A path, so that choosing a parent category includes everything filed
		// beneath it — which is what the count beside it counted.
		$this->assertSame( array( '0/11' ), $query->filters()['product_cat'] );
	}

	public function test_layered_navigation_defaults_to_requiring_every_value(): void {
		// WooCommerce's own default is "and". A disjunction here would hand a
		// shopper products they had just excluded.
		$query = $this->shop()->for_query(
			array(),
			array( 'filter_color' => 'red,blue' ),
			CatalogContext::CATALOG
		);

		$this->assertSame( array( '21', '22' ), $query->filters()['pa_color'] );
		$this->assertSame( 'and', $query->filter_mode( 'pa_color' ) );
	}

	public function test_layered_navigation_honours_an_explicit_or(): void {
		$query = $this->shop()->for_query(
			array(),
			array(
				'filter_color'     => 'red,blue',
				'query_type_color' => 'or',
			),
			CatalogContext::CATALOG
		);

		$this->assertSame( 'or', $query->filter_mode( 'pa_color' ) );
	}

	public function test_a_price_slider_becomes_a_bounded_range(): void {
		$query = $this->shop()->for_query(
			array(),
			array(
				'min_price' => '10',
				'max_price' => '250',
			),
			CatalogContext::CATALOG
		);

		$this->assertSame( array( '10:250' ), $query->filters()['price_range'] );
	}

	public function test_a_half_open_price_range_still_works(): void {
		$query = $this->shop()->for_query( array(), array( 'min_price' => '10' ), CatalogContext::CATALOG );

		$this->assertSame( array( '10:' ), $query->filters()['price_range'] );
	}

	public function test_a_price_that_is_not_a_number_is_ignored(): void {
		$query = $this->shop()->for_query(
			array(),
			array( 'min_price' => '10 OR se_s_visibility:private' ),
			CatalogContext::CATALOG
		);

		$this->assertArrayNotHasKey( 'price_range', $query->filters() );
	}

	/**
	 * @dataProvider orderings
	 *
	 * @param string $orderby  WooCommerce ordering.
	 * @param string $order    Direction.
	 * @param string $expected SolrEngine sort key.
	 */
	public function test_shop_orderings_map_to_sort_keys( string $orderby, string $order, string $expected ): void {
		$query = $this->shop()->for_query(
			array(
				'orderby' => $orderby,
				'order'   => $order,
			),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNotNull( $query, $orderby . ' should be expressible' );
		$this->assertSame( $expected, $query->sort() );
	}

	/**
	 * @return array<string,array{0:string,1:string,2:string}>
	 */
	public function orderings(): array {
		return array(
			'default'    => array( 'menu_order title', 'ASC', 'menu_order' ),
			'price up'   => array( 'price', 'ASC', 'price_asc' ),
			'price down' => array( 'price', 'DESC', 'price_desc' ),
			'popularity' => array( 'popularity', 'DESC', 'popularity' ),
			'rating'     => array( 'rating', 'DESC', 'rating' ),
			'newest'     => array( 'date id', 'DESC', 'date_desc' ),
			'title'      => array( 'title', 'ASC', 'title_asc' ),
			'relevance'  => array( 'relevance', 'DESC', 'relevance' ),
		);
	}

	public function test_a_search_with_no_ordering_sorts_by_relevance(): void {
		$query = $this->shop()->for_query( array( 's' => 'boots' ), array(), CatalogContext::SEARCH );

		$this->assertSame( 'relevance', $query->sort() );
		$this->assertSame( 'boots', $query->terms() );
	}

	public function test_woocommerces_own_visibility_terms_are_recognised_not_refused(): void {
		// WooCommerce adds these to every product query. They are SolrEngine's
		// rules expressed in taxonomy terms, so they are dropped rather than
		// translated — and must not cause a refusal.
		$query = $this->shop()->for_query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- A fixture, not a query.
				'tax_query' => array(
					array(
						'taxonomy' => 'product_visibility',
						'terms'    => array( 7 ),
						'operator' => 'NOT IN',
					),
				),
			),
			array(),
			CatalogContext::CATALOG
		);

		$this->assertNotNull( $query );
		$this->assertArrayNotHasKey( 'product_visibility', $query->filters() );
	}
}
