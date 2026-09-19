<?php
/**
 * Adds WooCommerce data to a product's document.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Contracts\Result;
use SolrEngine\Documents\DocumentFields;
use WC_Product;
use WC_Product_Attribute;
use WC_Product_Variable;
use WP_Post;

/**
 * Turns a product into the fields a shop needs to search and filter by.
 *
 * Attached to `solrengine_document`, the extension point the core builder
 * documents as the primary customisation seam. Nothing in core has to know
 * what a variation is, and a site not running WooCommerce pays nothing.
 *
 * Two rules govern everything here.
 *
 * **Every value is read through WooCommerce's CRUD getters, never from post
 * meta.** Reading `_price` directly is faster today and empty after the next
 * storage migration: that is not a hypothetical, it is precisely what
 * High-Performance Order Storage already did to code that read order meta.
 * See ADR-006 and plan section 15.1.
 *
 * **The document is the parent product, with variation data folded into it.**
 * A store with 40,000 products and 300,000 variations gets 40,000 documents,
 * and a shopper filtering "16 GB" and "in stock" gets products that have such a
 * variation — which is what they meant, and what WooCommerce's own attribute
 * lookup table was built to deliver.
 *
 * @since 0.7.0
 */
final class ProductDocument {

	/**
	 * Post type holding products.
	 *
	 * @var string
	 */
	public const POST_TYPE = 'product';

	/**
	 * Whether the exact stock quantity may be indexed.
	 *
	 * @var bool
	 */
	private $index_stock;

	/**
	 * Quantity at or below which stock counts as low.
	 *
	 * @var int
	 */
	private $low_stock;

	/**
	 * Most variations read per product when collecting SKUs.
	 *
	 * @var int
	 */
	private $variation_limit;

	/**
	 * Constructor.
	 *
	 * @param bool $index_stock     Whether to index exact stock quantities.
	 * @param int  $low_stock       Low-stock threshold.
	 * @param int  $variation_limit Most variations read per product.
	 */
	public function __construct( bool $index_stock = false, int $low_stock = 2, int $variation_limit = 100 ) {
		$this->index_stock     = $index_stock;
		$this->low_stock       = max( 1, $low_stock );
		$this->variation_limit = max( 0, $variation_limit );
	}

	/**
	 * Attach to the core builder's extension points.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'solrengine_should_index_object', array( $this, 'assess' ), 10, 2 );
		add_filter( 'solrengine_document', array( $this, 'enrich' ), 10, 2 );
	}

	/**
	 * Veto products that must never be indexed.
	 *
	 * @since 0.7.0
	 *
	 * @param Result  $verdict Whether to index, so far.
	 * @param WP_Post $post    The post being considered.
	 * @return Result
	 */
	public function assess( $verdict, $post ) {
		if ( ! $verdict instanceof Result || ! $post instanceof WP_Post ) {
			return $verdict;
		}

		if ( 'product_variation' === $post->post_type ) {
			// Variations are folded into their parent. Indexing them as well
			// would double every facet count and show a shopper the same
			// product several times.
			return Result::failure( 'variation', 'Variations are indexed as part of their parent product.' );
		}

		if ( self::POST_TYPE !== $post->post_type || $verdict->is_failure() ) {
			return $verdict;
		}

		$product = $this->product_for( $post );

		if ( null === $product ) {
			return Result::failure( 'product_unreadable', 'WooCommerce could not load this product.' );
		}

		if ( CatalogContext::is_hidden( (string) $product->get_catalog_visibility() ) ) {
			// A merchant who hid a product hid it from both the shop and
			// search. Not indexing it is a stronger guarantee than filtering it
			// out later, because no future query can forget it.
			return Result::failure( 'catalog_hidden', 'The product is hidden from both the catalogue and search.' );
		}

		return $verdict;
	}

	/**
	 * Add the product fields.
	 *
	 * @since 0.7.0
	 *
	 * @param Document $document The document so far.
	 * @param WP_Post  $post     The post it was built from.
	 * @return Document
	 */
	public function enrich( $document, $post ) {
		if ( ! $document instanceof Document || ! $post instanceof WP_Post ) {
			return $document;
		}

		if ( self::POST_TYPE !== $post->post_type ) {
			return $document;
		}

		$product = $this->product_for( $post );

		if ( null === $product ) {
			return $document;
		}

		$fields = array_merge( $document->fields(), $this->fields_for( $product ) );

		return new Document( $document->ref(), $fields );
	}

	/**
	 * Every field a product contributes.
	 *
	 * @since 0.7.0
	 *
	 * @param WC_Product $product Product to map.
	 * @return array<string,mixed>
	 */
	public function fields_for( WC_Product $product ): array {
		$fields = array();

		$this->set( $fields, ProductFields::TYPE, FieldType::STRING, $product->get_type() );
		$this->set( $fields, ProductFields::FEATURED, FieldType::BOOL, $product->is_featured() );
		$this->set( $fields, ProductFields::RATING, FieldType::FLOAT, $product->get_average_rating() );
		$this->set( $fields, ProductFields::REVIEWS, FieldType::INT, $product->get_review_count() );
		$this->set( $fields, ProductFields::SALES, FieldType::INT, $product->get_total_sales() );

		$this->set(
			$fields,
			ProductFields::EXCLUDED,
			FieldType::STRINGS,
			CatalogContext::exclusions_for( (string) $product->get_catalog_visibility() )
		);

		$this->add_identity( $fields, $product );
		$this->add_prices( $fields, $product );
		$this->add_stock( $fields, $product );
		$this->add_attributes( $fields, $product );

		return $fields;
	}

	/**
	 * SKUs, product type and variation count.
	 *
	 * @param array<string,mixed> $fields  Fields being built.
	 * @param WC_Product          $product Product.
	 * @return void
	 */
	private function add_identity( array &$fields, WC_Product $product ): void {
		$sku  = (string) $product->get_sku();
		$skus = '' !== $sku ? array( $sku ) : array();

		$this->set( $fields, ProductFields::SKU, FieldType::STRING, $sku );

		$children = $this->variations_of( $product );

		$this->set( $fields, ProductFields::VARIATIONS, FieldType::INT, count( $this->child_ids( $product ) ) );

		$variation_skus = array();

		foreach ( $children as $variation ) {
			$child_sku = (string) $variation->get_sku();

			if ( '' !== $child_sku ) {
				$variation_skus[] = $child_sku;
				$skus[]           = $child_sku;
			}
		}

		$this->set( $fields, ProductFields::VARIATION_SKU, FieldType::STRINGS, $variation_skus );

		// Every SKU, parent and children, in one analysed field. A shopper or a
		// shop assistant typing a variation's SKU expects to land on the
		// product that sells it.
		$this->set( $fields, ProductFields::SKU_TEXT, FieldType::TEXT, implode( ' ', array_unique( $skus ) ) );
	}

	/**
	 * Prices, including the range across variations.
	 *
	 * Every price here is a base price. What a given customer is shown depends
	 * on their tax jurisdiction, their customer role, and any coupon they hold,
	 * none of which exist at index time. These fields are for filtering and
	 * sorting; the price on the page is always rendered by WooCommerce. See
	 * plan section 15.4.
	 *
	 * @param array<string,mixed> $fields  Fields being built.
	 * @param WC_Product          $product Product.
	 * @return void
	 */
	private function add_prices( array &$fields, WC_Product $product ): void {
		$price = $this->to_float( $product->get_price() );
		$min   = $price;
		$max   = $price;

		if ( $product instanceof WC_Product_Variable ) {
			// WooCommerce caches this, so it costs one computation per product
			// rather than one per variation.
			$prices = $product->get_variation_prices( false );
			$values = array();

			foreach ( (array) ( $prices['price'] ?? array() ) as $value ) {
				$value = $this->to_float( $value );

				if ( null !== $value ) {
					$values[] = $value;
				}
			}

			if ( array() !== $values ) {
				$min   = min( $values );
				$max   = max( $values );
				$price = $min;
			}
		}

		$this->set( $fields, ProductFields::PRICE, FieldType::FLOAT, $price );
		$this->set( $fields, ProductFields::PRICE_MIN, FieldType::FLOAT, $min );
		$this->set( $fields, ProductFields::PRICE_MAX, FieldType::FLOAT, $max );
		$this->set( $fields, ProductFields::REGULAR_PRICE, FieldType::FLOAT, $this->to_float( $product->get_regular_price() ) );
		$this->set( $fields, ProductFields::SALE_PRICE, FieldType::FLOAT, $this->to_float( $product->get_sale_price() ) );
		$this->set( $fields, ProductFields::ON_SALE, FieldType::BOOL, $product->is_on_sale() );

		$from = $product->get_date_on_sale_from();
		$to   = $product->get_date_on_sale_to();

		if ( null !== $from ) {
			$this->set( $fields, ProductFields::SALE_FROM, FieldType::DATE, $from->getTimestamp() );
		}

		if ( null !== $to ) {
			$this->set( $fields, ProductFields::SALE_TO, FieldType::DATE, $to->getTimestamp() );
		}

		if ( null !== $price && $this->store_shows_tax() && function_exists( 'wc_get_price_including_tax' ) ) {
			// A second field rather than a replacement, so a store that changes
			// its tax display setting does not invalidate every stored filter
			// URL, and so the base price stays available for sorting.
			$this->set(
				$fields,
				ProductFields::PRICE_TAXED,
				FieldType::FLOAT,
				$this->to_float( wc_get_price_including_tax( $product, array( 'price' => $price ) ) )
			);
		}
	}

	/**
	 * Stock status, availability bucket and backorder policy.
	 *
	 * @param array<string,mixed> $fields  Fields being built.
	 * @param WC_Product          $product Product.
	 * @return void
	 */
	private function add_stock( array &$fields, WC_Product $product ): void {
		$status   = (string) $product->get_stock_status();
		$quantity = $product->get_stock_quantity();
		$quantity = null === $quantity ? null : (int) $quantity;

		if ( $product instanceof WC_Product_Variable && 'outofstock' === $status ) {
			// A variable product is in stock if any variation is. WooCommerce
			// syncs this onto the parent, so the parent's status is already the
			// answer; this only guards the case where a sync has not run yet.
			foreach ( $this->variations_of( $product ) as $variation ) {
				if ( 'outofstock' !== (string) $variation->get_stock_status() ) {
					$status = (string) $variation->get_stock_status();

					break;
				}
			}
		}

		$this->set( $fields, ProductFields::STOCK_STATUS, FieldType::STRING, $status );
		$this->set( $fields, ProductFields::AVAILABILITY, FieldType::STRING, StockLevel::bucket( $status, $quantity, $this->low_stock ) );
		$this->set( $fields, ProductFields::BACKORDERS, FieldType::BOOL, 'no' !== (string) $product->get_backorders() );

		if ( $this->index_stock && null !== $quantity ) {
			// Opt-in only. See StockLevel for why this is not the default.
			$this->set( $fields, ProductFields::STOCK, FieldType::INT, $quantity );
		}
	}

	/**
	 * Attribute terms, per attribute and as searchable text.
	 *
	 * The parent's attributes are the union of what its variations offer, which
	 * is what makes "has a variation in 16 GB" expressible without indexing the
	 * variations themselves. It is also what WooCommerce's own layered
	 * navigation filters on.
	 *
	 * @param array<string,mixed> $fields  Fields being built.
	 * @param WC_Product          $product Product.
	 * @return void
	 */
	private function add_attributes( array &$fields, WC_Product $product ): void {
		$searchable = array();

		foreach ( $product->get_attributes() as $attribute ) {
			if ( ! $attribute instanceof WC_Product_Attribute ) {
				continue;
			}

			if ( ! $attribute->is_taxonomy() ) {
				// A custom, per-product attribute. Its values are free text
				// stored on the product, so they are searchable but not
				// facetable: there is no term to count, and no stable
				// identifier to filter by.
				foreach ( $attribute->get_options() as $option ) {
					if ( is_scalar( $option ) ) {
						$searchable[] = (string) $option;
					}
				}

				continue;
			}

			$taxonomy = (string) $attribute->get_name();
			$ids      = array();

			foreach ( $attribute->get_terms() as $term ) {
				$ids[]        = (int) $term->term_id;
				$searchable[] = (string) $term->name;
			}

			$this->set( $fields, ProductFields::attribute( $taxonomy ), FieldType::INTS, $ids );
		}

		$this->set( $fields, ProductFields::ATTRIBUTE_TEXT, FieldType::TEXT_MULTI, array_values( array_unique( $searchable ) ) );
	}

	/**
	 * The identifiers of a product's variations.
	 *
	 * @param WC_Product $product Product.
	 * @return int[]
	 */
	private function child_ids( WC_Product $product ): array {
		if ( ! $product instanceof WC_Product_Variable ) {
			return array();
		}

		return array_map( 'intval', (array) $product->get_children() );
	}

	/**
	 * A product's variations, as objects, up to the configured limit.
	 *
	 * Caches are primed in one go before the objects are built. Without that,
	 * a product with fifty variations costs fifty pairs of queries during a
	 * full sync — the single largest indexing cost a catalogue has.
	 *
	 * @param WC_Product $product Product.
	 * @return WC_Product[]
	 */
	private function variations_of( WC_Product $product ): array {
		$ids = $this->child_ids( $product );

		if ( array() === $ids || 0 === $this->variation_limit ) {
			return array();
		}

		$ids = array_slice( $ids, 0, $this->variation_limit );

		_prime_post_caches( $ids, false, true );

		$variations = array();

		foreach ( $ids as $id ) {
			$variation = wc_get_product( $id );

			if ( $variation instanceof WC_Product ) {
				$variations[] = $variation;
			}
		}

		return $variations;
	}

	/**
	 * The product behind a post, or null when WooCommerce cannot load it.
	 *
	 * @param WP_Post $post Post.
	 * @return WC_Product|null
	 */
	private function product_for( WP_Post $post ): ?WC_Product {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return null;
		}

		$product = wc_get_product( $post->ID );

		return $product instanceof WC_Product ? $product : null;
	}

	/**
	 * Whether the store displays prices with tax included.
	 *
	 * @return bool
	 */
	private function store_shows_tax(): bool {
		return 'yes' === get_option( 'woocommerce_calc_taxes', 'no' )
			&& 'incl' === get_option( 'woocommerce_tax_display_shop', 'excl' );
	}

	/**
	 * Read a WooCommerce price as a number.
	 *
	 * An empty string is WooCommerce's way of saying "no price set", and it is
	 * not the same as zero: a product with no price must not appear in a filter
	 * for free products.
	 *
	 * @param mixed $value Raw value.
	 * @return float|null
	 */
	private function to_float( $value ): ?float {
		if ( ! is_scalar( $value ) || '' === $value || ! is_numeric( $value ) ) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * Record a field, coercing it and dropping anything unusable.
	 *
	 * @param array<string,mixed> $fields Fields being built.
	 * @param string              $field  Field name.
	 * @param string              $type   Type prefix.
	 * @param mixed               $value  Raw value.
	 * @return void
	 */
	private function set( array &$fields, string $field, string $type, $value ): void {
		$coerced = DocumentFields::coerce( $type, $value );

		if ( null !== $coerced ) {
			$fields[ $field ] = $coerced;
		}
	}
}
