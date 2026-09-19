<?php
/**
 * The decisions a product document makes, without WooCommerce.
 *
 * The mapping itself needs WooCommerce and a database, and is covered by the
 * integration suite. What lives here is the reasoning behind it: which
 * contexts a visibility allows, what an availability means, and where a price
 * facet's bands fall — all of which are arguments worth settling in a test
 * rather than in a shop.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Integration\WooCommerce\CatalogContext;
use SolrEngine\Integration\WooCommerce\PriceBands;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Integration\WooCommerce\StockLevel;

/**
 * @covers \SolrEngine\Integration\WooCommerce\CatalogContext
 * @covers \SolrEngine\Integration\WooCommerce\PriceBands
 * @covers \SolrEngine\Integration\WooCommerce\StockLevel
 * @covers \SolrEngine\Integration\WooCommerce\ProductFields
 */
final class ProductMappingTest extends TestCase {

	// -- Catalogue visibility ------------------------------------------------

	/**
	 * @dataProvider visibilities
	 *
	 * @param string   $visibility WooCommerce catalogue visibility.
	 * @param string[] $allowed    Contexts the product may appear in.
	 */
	public function test_visibility_decides_where_a_product_appears( string $visibility, array $allowed ): void {
		$this->assertSame( $allowed, CatalogContext::for_visibility( $visibility ) );
	}

	/**
	 * @return array<string,array{0:string,1:array<int,string>}>
	 */
	public function visibilities(): array {
		return array(
			'visible'      => array( 'visible', array( CatalogContext::CATALOG, CatalogContext::SEARCH ) ),
			'catalog'      => array( 'catalog', array( CatalogContext::CATALOG ) ),
			'search'       => array( 'search', array( CatalogContext::SEARCH ) ),
			'hidden'       => array( 'hidden', array() ),
			// A value WooCommerce does not know falls back to visible, and so
			// does this. Failing the other way would empty a merchant's shop
			// on a plugin conflict.
			'unrecognised' => array( 'something-else', array( CatalogContext::CATALOG, CatalogContext::SEARCH ) ),
		);
	}

	public function test_exclusions_are_the_inverse_of_what_is_allowed(): void {
		// The index stores exclusions, so a document with nothing to hide
		// carries no field and passes every context filter — including on
		// sites that have never run WooCommerce.
		$this->assertSame( array(), CatalogContext::exclusions_for( 'visible' ) );
		$this->assertSame( array( CatalogContext::SEARCH ), CatalogContext::exclusions_for( 'catalog' ) );
		$this->assertSame( array( CatalogContext::CATALOG ), CatalogContext::exclusions_for( 'search' ) );
		$this->assertSame(
			array( CatalogContext::SEARCH, CatalogContext::CATALOG ),
			CatalogContext::exclusions_for( 'hidden' )
		);
	}

	public function test_a_hidden_product_is_not_indexed_at_all(): void {
		$this->assertTrue( CatalogContext::is_hidden( 'hidden' ) );
		$this->assertFalse( CatalogContext::is_hidden( 'catalog' ) );
	}

	// -- Availability --------------------------------------------------------

	/**
	 * @dataProvider stock
	 *
	 * @param string   $status   Stock status.
	 * @param int|null $quantity Managed quantity, or null when unmanaged.
	 * @param string   $expected Bucket.
	 */
	public function test_availability_is_bucketed( string $status, ?int $quantity, string $expected ): void {
		$this->assertSame( $expected, StockLevel::bucket( $status, $quantity, 2 ) );
	}

	/**
	 * @return array<string,array{0:string,1:int|null,2:string}>
	 */
	public function stock(): array {
		return array(
			'out of stock'          => array( 'outofstock', null, StockLevel::OUT ),
			'on backorder'          => array( 'onbackorder', 0, StockLevel::BACKORDER ),
			// No quantity means stock is not managed, which means "always
			// available" — not zero.
			'unmanaged'             => array( 'instock', null, StockLevel::IN ),
			'plenty'                => array( 'instock', 40, StockLevel::IN ),
			'nearly gone'           => array( 'instock', 2, StockLevel::LOW ),
			'one left'              => array( 'instock', 1, StockLevel::LOW ),
			// In stock by status, nothing left by count. WooCommerce permits
			// this; the honest answer is the count.
			'in stock but empty'    => array( 'instock', 0, StockLevel::OUT ),
			'in stock but negative' => array( 'instock', -3, StockLevel::OUT ),
		);
	}

	// -- Price bands ---------------------------------------------------------

	/**
	 * @dataProvider catalogues
	 *
	 * @param float $max      Highest price in the catalogue.
	 * @param float $expected Expected band width.
	 */
	public function test_bands_are_round_numbers( float $max, float $expected ): void {
		$this->assertSame( $expected, PriceBands::for_max( $max )['gap'] );
	}

	/**
	 * @return array<string,array{0:float,1:float}>
	 */
	public function catalogues(): array {
		return array(
			'cheap'    => array( 9.99, 2.0 ),
			'ordinary' => array( 45.0, 10.0 ),
			'mid'      => array( 120.0, 20.0 ),
			'pricey'   => array( 999.0, 200.0 ),
			'tractors' => array( 25000.0, 5000.0 ),
		);
	}

	public function test_the_most_expensive_product_falls_inside_a_band(): void {
		// A band's upper bound is exclusive, so ending at the maximum itself
		// would drop the one product a shopper is most likely to look for.
		foreach ( array( 9.99, 45.0, 120.0, 999.0, 1200.0, 25000.0 ) as $max ) {
			$bands = PriceBands::for_max( $max );

			$this->assertGreaterThan( $max, $bands['end'], 'Band end must exceed ' . $max );
		}
	}

	public function test_a_catalogue_with_no_prices_gets_no_bands(): void {
		$this->assertSame( 0.0, PriceBands::for_max( 0.0 )['gap'] );
		$this->assertSame( 0.0, PriceBands::for_max( -5.0 )['gap'] );
	}

	public function test_bands_never_go_below_a_whole_unit(): void {
		// Ten-cent bands are not a filter anybody uses, and there would be
		// hundreds of them.
		$this->assertGreaterThanOrEqual( 1.0, PriceBands::for_max( 3.0 )['gap'] );
	}

	// -- Field names ---------------------------------------------------------

	public function test_attribute_fields_follow_the_plugin_convention(): void {
		// The type marker comes first, so the name lands in a dynamic field
		// that already exists. This is why Phase 6 needs no schema change.
		$this->assertSame( 'se_is_wc_attr_pa_color', ProductFields::attribute( 'pa_color' ) );
		$this->assertSame( 'se_is_wc_attr_pa_screen_size', ProductFields::attribute( 'pa_screen-size' ) );
	}

	public function test_every_product_field_lands_in_a_dynamic_pattern(): void {
		$patterns = array( 'se_s_', 'se_ss_', 'se_i_', 'se_is_', 'se_f_', 'se_b_', 'se_dt_', 'se_txt_', 'se_txts_' );

		$fields = array(
			ProductFields::SKU,
			ProductFields::SKU_TEXT,
			ProductFields::VARIATION_SKU,
			ProductFields::TYPE,
			ProductFields::VARIATIONS,
			ProductFields::PRICE,
			ProductFields::PRICE_MIN,
			ProductFields::PRICE_MAX,
			ProductFields::REGULAR_PRICE,
			ProductFields::SALE_PRICE,
			ProductFields::PRICE_TAXED,
			ProductFields::ON_SALE,
			ProductFields::SALE_FROM,
			ProductFields::SALE_TO,
			ProductFields::STOCK_STATUS,
			ProductFields::AVAILABILITY,
			ProductFields::BACKORDERS,
			ProductFields::STOCK,
			ProductFields::EXCLUDED,
			ProductFields::FEATURED,
			ProductFields::RATING,
			ProductFields::REVIEWS,
			ProductFields::SALES,
			ProductFields::ATTRIBUTE_TEXT,
			ProductFields::attribute( 'pa_size' ),
		);

		foreach ( $fields as $field ) {
			$matched = false;

			foreach ( $patterns as $pattern ) {
				if ( 0 === strpos( $field, $pattern ) ) {
					$matched = true;

					break;
				}
			}

			$this->assertTrue( $matched, $field . ' matches no dynamic field pattern' );
		}
	}
}
