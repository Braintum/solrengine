<?php
/**
 * The facets and filters a shop offers.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;

/**
 * Registers product facets, and the filters that pair with them.
 *
 * Every facet here has a filter with the same key, because that pairing is what
 * lets multi-select exclude a facet's own selection from its own counts. A
 * facet without its filter counts things nobody can select; a filter without
 * its facet narrows by something nobody can see.
 *
 * Categories, tags and brands are deliberately absent: they are public
 * taxonomies, so the core registries already offer them, and registering them
 * again here would mean two definitions of one thing.
 *
 * @since 0.7.0
 */
final class ProductFacets {

	/**
	 * Transient holding the catalogue's highest price.
	 *
	 * @var string
	 */
	private const PRICE_CEILING = 'solrengine_wc_price_ceiling';

	/**
	 * How long a usable ceiling is trusted, in seconds.
	 *
	 * @var int
	 */
	private const CEILING_TTL = 12 * HOUR_IN_SECONDS;

	/**
	 * How long a ceiling of zero is trusted, in seconds.
	 *
	 * Far shorter, because zero means either "no products priced yet" or "the
	 * lookup did not work", and both are conditions that fix themselves. A
	 * merchant who prices their first product should not wait half a day for a
	 * price filter, and a store whose product query is broken by something else
	 * entirely should recover the moment it is fixed.
	 *
	 * @var int
	 */
	private const EMPTY_CEILING_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Whether a ceiling lookup is already queued for this request.
	 *
	 * @var bool
	 */
	private static $refresh_scheduled = false;

	/**
	 * Attach to the registries.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Late, so a shop's attribute facets replace the generic taxonomy ones
		// the core registry may already have registered for the same keys.
		add_filter( 'solrengine_facet_definitions', array( $this, 'add_facets' ), 20 );
		add_action( 'solrengine_register_filters', array( $this, 'add_filters' ), 20 );
	}

	/**
	 * Add the product facets.
	 *
	 * @since 0.7.0
	 *
	 * @param FacetRegistry $registry The registry.
	 * @return FacetRegistry
	 */
	public function add_facets( $registry ) {
		if ( ! $registry instanceof FacetRegistry ) {
			return $registry;
		}

		$bands = $this->price_bands();

		if ( $bands['gap'] > 0.0 ) {
			$registry->register(
				FacetDefinition::range(
					'price',
					ProductFields::PRICE_MIN,
					__( 'Price', 'solrengine' ),
					$bands['start'],
					$bands['end'],
					$bands['gap']
				)
			);
		}

		$registry->register(
			FacetDefinition::terms(
				'stock_status',
				ProductFields::STOCK_STATUS,
				__( 'Availability', 'solrengine' ),
				FieldType::STRING
			)->with_sort( 'index' )
		);

		$registry->register(
			FacetDefinition::terms(
				'on_sale',
				ProductFields::ON_SALE,
				__( 'On sale', 'solrengine' ),
				FieldType::BOOL
			)
		);

		foreach ( $this->attribute_taxonomies() as $taxonomy => $label ) {
			$registry->register(
				FacetDefinition::terms(
					$taxonomy,
					ProductFields::attribute( $taxonomy ),
					$label,
					FieldType::INT
				)->with_taxonomy( $taxonomy )
			);
		}

		return $registry;
	}

	/**
	 * Add the filters those facets select by.
	 *
	 * @since 0.7.0
	 *
	 * @param FilterRegistry $registry The registry.
	 * @return void
	 */
	public function add_filters( $registry ): void {
		if ( ! $registry instanceof FilterRegistry ) {
			return;
		}

		$bands = $this->price_bands();

		if ( $bands['gap'] > 0.0 ) {
			$registry->register_bands( 'price', ProductFields::PRICE_MIN, $bands['gap'], __( 'Price', 'solrengine' ) );
		}

		// A second key on the same field, for the slider WooCommerce's own
		// widgets and blocks produce: they send two numbers, not a band the
		// server declared, and refusing to answer that query would mean the
		// merchant's existing filter UI silently stopped working.
		$registry->register_range( 'price_range', ProductFields::PRICE_MIN, __( 'Price range', 'solrengine' ) );

		$registry->register( 'stock_status', ProductFields::STOCK_STATUS, FieldType::STRING, __( 'Availability', 'solrengine' ) );
		$registry->register( 'on_sale', ProductFields::ON_SALE, FieldType::BOOL, __( 'On sale', 'solrengine' ) );
		$registry->register( 'featured', ProductFields::FEATURED, FieldType::BOOL, __( 'Featured', 'solrengine' ) );
		$registry->register( 'product_type', ProductFields::TYPE, FieldType::STRING, __( 'Product type', 'solrengine' ) );
		$registry->register( 'sku', ProductFields::SKU, FieldType::STRING, __( 'SKU', 'solrengine' ) );

		// The two rules a shopper never gets to set. Both are exclusions, so
		// they narrow products without touching posts or pages sharing the
		// index, and both are applied by the integration after any caller has
		// had their say.
		$registry->register_excluding(
			ShopQuery::CONTEXT_KEY,
			ProductFields::EXCLUDED,
			FieldType::STRING,
			__( 'Catalogue context', 'solrengine' )
		);

		$registry->register_excluding(
			ShopQuery::STOCK_EXCLUSION_KEY,
			ProductFields::STOCK_STATUS,
			FieldType::STRING,
			__( 'Hidden stock statuses', 'solrengine' )
		);

		foreach ( array_keys( $this->attribute_taxonomies() ) as $taxonomy ) {
			$registry->register(
				$taxonomy,
				ProductFields::attribute( $taxonomy ),
				FieldType::INT,
				$this->attribute_taxonomies()[ $taxonomy ]
			);
		}
	}

	/**
	 * Attribute taxonomies, keyed by taxonomy name.
	 *
	 * @return array<string,string>
	 */
	private function attribute_taxonomies(): array {
		if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
			return array();
		}

		$out = array();

		foreach ( wc_get_attribute_taxonomies() as $attribute ) {
			$name = 'pa_' . $attribute->attribute_name;

			if ( ! taxonomy_exists( $name ) ) {
				continue;
			}

			$out[ $name ] = '' !== $attribute->attribute_label ? $attribute->attribute_label : $attribute->attribute_name;
		}

		return $out;
	}

	/**
	 * Price bands covering the catalogue.
	 *
	 * The ceiling is cached rather than computed per request: it moves when a
	 * merchant adds an unusually expensive product, which is rare, and the
	 * query that finds it is not one to run on every page load.
	 *
	 * @return array{start:float,end:float,gap:float}
	 */
	private function price_bands(): array {
		$ceiling = get_transient( self::PRICE_CEILING );

		if ( ! is_numeric( $ceiling ) ) {
			// Deliberately not computed here. This runs while the filter and
			// facet registries are being built, and those are built lazily —
			// sometimes from inside a query. Running a product query at that
			// moment re-enters the container through pre_get_posts and
			// deadlocks it, which is exactly what happened the first time this
			// was tried on a real shop.
			//
			// So the lookup is deferred to the end of the request and the price
			// facet is absent until the next one. That is a facet appearing a
			// page load late, once every twelve hours, against a deadlock.
			$this->refresh_ceiling_later();

			$ceiling = 0.0;
		}

		/**
		 * Filters the price bands a shop's price facet offers.
		 *
		 * Return start, end and gap. A gap of zero removes the facet, which is
		 * what a catalogue with no priced products gets.
		 *
		 * @since 0.7.0
		 *
		 * @param array{start:float,end:float,gap:float} $bands   The derived bands.
		 * @param float                                  $ceiling The catalogue's highest price.
		 */
		return (array) apply_filters(
			'solrengine_wc_price_bands',
			PriceBands::for_max( (float) $ceiling ),
			(float) $ceiling
		);
	}

	/**
	 * Arrange for the price ceiling to be looked up after the response.
	 *
	 * @return void
	 */
	private function refresh_ceiling_later(): void {
		if ( self::$refresh_scheduled ) {
			return;
		}

		self::$refresh_scheduled = true;

		add_action(
			'shutdown',
			function (): void {
				if ( is_numeric( get_transient( self::PRICE_CEILING ) ) ) {
					return;
				}

				$ceiling = $this->highest_price();

				set_transient(
					self::PRICE_CEILING,
					$ceiling,
					$ceiling > 0.0 ? self::CEILING_TTL : self::EMPTY_CEILING_TTL
				);
			},
			1
		);
	}

	/**
	 * The highest price in the catalogue.
	 *
	 * @return float
	 */
	private function highest_price(): float {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return 0.0;
		}

		$products = wc_get_products(
			array(
				'limit'    => 1,
				'status'   => 'publish',
				'orderby'  => 'meta_value_num',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One row, at most twice a day, to decide where a price facet's bands fall.
				'meta_key' => '_price',
				'order'    => 'DESC',
				'return'   => 'objects',
			)
		);

		foreach ( (array) $products as $product ) {
			$price = $product->get_price();

			if ( is_numeric( $price ) ) {
				return (float) $price;
			}
		}

		return 0.0;
	}

	/**
	 * Forget the cached ceiling.
	 *
	 * Called when a product is written, so a merchant adding their first
	 * expensive product does not wait half a day for the bands to cover it.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public static function forget_price_ceiling(): void {
		delete_transient( self::PRICE_CEILING );
	}
}
