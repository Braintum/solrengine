<?php
/**
 * Mapping real WooCommerce products.
 *
 * Everything here needs WooCommerce and a database: a variable product's price
 * range comes from WooCommerce's own cached computation, an attribute's terms
 * come from a taxonomy, and a hidden product's refusal depends on a CRUD getter.
 * None of it can be faked convincingly, and faking it would only test the fake.
 *
 * Skips unless WooCommerce is loaded. To run it, install WooCommerce into the
 * WordPress test library and load it from tests/bootstrap.php — see
 * docs/development.md.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Documents\MetaAllowlist;
use SolrEngine\Documents\PostDocumentBuilder;
use SolrEngine\Integration\WooCommerce\CatalogContext;
use SolrEngine\Integration\WooCommerce\ProductDocument;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Integration\WooCommerce\StockLevel;
use WC_Product_Attribute;
use WC_Product_Simple;
use WC_Product_Variable;
use WC_Product_Variation;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Integration\WooCommerce\ProductDocument
 */
final class ProductDocumentTest extends WP_UnitTestCase {

	/**
	 * Identifiers of everything this test created.
	 *
	 * @var int[]
	 */
	private $created = array();

	/**
	 * The attribute taxonomy terms, keyed by slug.
	 *
	 * @var array<string,int>
	 */
	private $colours = array();

	public function set_up(): void {
		parent::set_up();

		if ( ! function_exists( 'wc_get_product' ) || ! class_exists( 'WC_Product_Simple' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded in this test environment.' );
		}

		( new ProductDocument( false, 2, 100 ) )->register();

		$this->colours = $this->attribute_terms();
	}

	public function tear_down(): void {
		foreach ( $this->created as $id ) {
			wp_delete_post( $id, true );
		}

		parent::tear_down();
	}

	public function test_a_simple_product_carries_its_price_sku_and_attributes(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Trail boots' );
		$product->set_sku( 'TB-40' );
		$product->set_regular_price( '80' );
		$product->set_stock_status( 'instock' );
		$product->set_attributes( array( $this->colour_attribute( array( $this->colours['crimson'] ) ) ) );

		$fields = $this->fields_for( $product );

		$this->assertSame( 80.0, $fields[ ProductFields::PRICE ] );
		$this->assertSame( 'TB-40', $fields[ ProductFields::SKU ] );
		$this->assertSame( 'simple', $fields[ ProductFields::TYPE ] );
		$this->assertSame( StockLevel::IN, $fields[ ProductFields::AVAILABILITY ] );
		$this->assertSame(
			array( $this->colours['crimson'] ),
			$fields[ ProductFields::attribute( 'pa_color' ) ]
		);
	}

	public function test_a_variable_product_denormalises_its_variations(): void {
		$product = $this->variable_product();
		$fields  = $this->fields_for( $product );

		// The claim ADR-006 rests on: one document per product, carrying the
		// range its variations span.
		$this->assertSame( 120.0, $fields[ ProductFields::PRICE_MIN ] );
		$this->assertSame( 180.0, $fields[ ProductFields::PRICE_MAX ] );
		$this->assertSame( 2, $fields[ ProductFields::VARIATIONS ] );

		// Every SKU, so somebody typing a variation's code finds the product
		// that sells it.
		$this->assertStringContainsString( 'WB-42-CRIMSON', $fields[ ProductFields::SKU_TEXT ] );
		$this->assertContains( 'WB-42-SAPPHIRE', $fields[ ProductFields::VARIATION_SKU ] );

		// The union of attribute values, which is what makes "has a variation
		// in sapphire" expressible without indexing variations.
		$this->assertContains( $this->colours['sapphire'], $fields[ ProductFields::attribute( 'pa_color' ) ] );
	}

	public function test_a_sale_price_is_the_indexed_price(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Sale sneakers' );
		$product->set_regular_price( '100' );
		$product->set_sale_price( '65' );

		$fields = $this->fields_for( $product );

		$this->assertSame( 65.0, $fields[ ProductFields::PRICE ] );
		$this->assertSame( 100.0, $fields[ ProductFields::REGULAR_PRICE ] );
		$this->assertTrue( $fields[ ProductFields::ON_SALE ] );
	}

	public function test_low_stock_is_bucketed_and_the_quantity_is_not_indexed(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Nearly gone' );
		$product->set_regular_price( '10' );
		$product->set_manage_stock( true );
		$product->set_stock_quantity( 2 );
		$product->set_stock_status( 'instock' );

		$fields = $this->fields_for( $product );

		$this->assertSame( StockLevel::LOW, $fields[ ProductFields::AVAILABILITY ] );

		// Off by default: commercially sensitive, and rewritten by every order.
		$this->assertArrayNotHasKey( ProductFields::STOCK, $fields );
	}

	public function test_a_product_hidden_from_search_records_that_exclusion(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Catalogue clogs' );
		$product->set_regular_price( '60' );
		$product->set_catalog_visibility( 'catalog' );

		$fields = $this->fields_for( $product );

		$this->assertSame( array( CatalogContext::SEARCH ), $fields[ ProductFields::EXCLUDED ] );
	}

	public function test_a_fully_hidden_product_is_never_indexed(): void {
		$product = new WC_Product_Simple();
		$product->set_name( 'Hidden prototype' );
		$product->set_regular_price( '999' );
		$product->set_catalog_visibility( 'hidden' );

		$id              = $product->save();
		$this->created[] = $id;

		$result = $this->builder()->build( get_post( $id ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'catalog_hidden', $result->code() );
	}

	public function test_a_variation_is_never_a_document_of_its_own(): void {
		$parent = $this->variable_product();
		$child  = (int) current( $parent->get_children() );

		$result = $this->builder()->build( get_post( $child ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'variation', $result->code() );
	}

	// -- Plumbing ------------------------------------------------------------

	private function builder(): PostDocumentBuilder {
		return new PostDocumentBuilder( MetaAllowlist::from_config( array() ), 1 );
	}

	/**
	 * Build a product's document fields through the whole builder.
	 *
	 * Deliberately not by calling the mapper directly: what is being asserted
	 * is that the integration reaches the document through the extension point
	 * core publishes, which is the arrangement that keeps WooCommerce out of
	 * the core builder.
	 *
	 * @param \WC_Product $product Product to save and map.
	 * @return array<string,mixed>
	 */
	private function fields_for( $product ): array {
		$id              = $product->save();
		$this->created[] = $id;

		$result = $this->builder()->build( get_post( $id ) );

		$this->assertTrue( $result->is_ok(), 'The product should be indexable' );

		return $result->value()->fields();
	}

	/**
	 * A variable product with two priced variations.
	 *
	 * @return WC_Product_Variable
	 */
	private function variable_product(): WC_Product_Variable {
		$product = new WC_Product_Variable();
		$product->set_name( 'Winter boots' );
		$product->set_sku( 'WB-42' );

		$attribute = $this->colour_attribute( array_values( $this->colours ) );
		$attribute->set_variation( true );

		$product->set_attributes( array( $attribute ) );

		$id              = $product->save();
		$this->created[] = $id;

		foreach ( array(
			'crimson'  => '120',
			'sapphire' => '180',
		) as $slug => $price ) {
			$variation = new WC_Product_Variation();
			$variation->set_parent_id( $id );
			$variation->set_attributes( array( 'pa_color' => $slug ) );
			$variation->set_regular_price( $price );
			$variation->set_sku( 'WB-42-' . strtoupper( $slug ) );
			$variation->set_stock_status( 'instock' );
			$variation->save();
		}

		WC_Product_Variable::sync( $id );

		return new WC_Product_Variable( $id );
	}

	/**
	 * The colour attribute, as a product attribute.
	 *
	 * @param int[] $term_ids Term identifiers.
	 * @return WC_Product_Attribute
	 */
	private function colour_attribute( array $term_ids ): WC_Product_Attribute {
		$attribute = new WC_Product_Attribute();
		$attribute->set_id( wc_attribute_taxonomy_id_by_name( 'color' ) );
		$attribute->set_name( 'pa_color' );
		$attribute->set_options( $term_ids );
		$attribute->set_visible( true );

		return $attribute;
	}

	/**
	 * Create the colour attribute and its terms.
	 *
	 * @return array<string,int>
	 */
	private function attribute_terms(): array {
		if ( ! taxonomy_exists( 'pa_color' ) ) {
			wc_create_attribute(
				array(
					'name' => 'Color',
					'slug' => 'color',
					'type' => 'select',
				)
			);

			register_taxonomy( 'pa_color', array( 'product' ), array( 'hierarchical' => false ) );
		}

		$terms = array();

		foreach ( array(
			'crimson'  => 'Crimson',
			'sapphire' => 'Sapphire',
		) as $slug => $name ) {
			$existing = get_term_by( 'slug', $slug, 'pa_color' );

			if ( $existing ) {
				$terms[ $slug ] = (int) $existing->term_id;

				continue;
			}

			$created = wp_insert_term( $name, 'pa_color', array( 'slug' => $slug ) );

			$terms[ $slug ] = is_array( $created ) ? (int) $created['term_id'] : 0;
		}

		return $terms;
	}
}
