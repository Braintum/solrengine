<?php
/**
 * The WooCommerce integration, as one switch.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Indexing\DirtySet;
use SolrEngine\Support\Config;

/**
 * Detects WooCommerce and wires the pieces that depend on it.
 *
 * One place that knows the integration exists, so every other part of the
 * plugin stays free of it. A site not running WooCommerce constructs nothing
 * here beyond this object's own detection check.
 *
 * @since 0.7.0
 */
final class WooCommerce {

	/**
	 * Whether WooCommerce is present and usable.
	 *
	 * Checked through its own API rather than through a class name, because a
	 * class can exist while the plugin is half-loaded — during an upgrade, or
	 * when another plugin has autoloaded a stub.
	 *
	 * @since 0.7.0
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return function_exists( 'wc_get_product' ) && function_exists( 'wc_get_attribute_taxonomies' );
	}

	/**
	 * Whether WooCommerce is switched on for this site, from its options alone.
	 *
	 * For a process that loads no plugins — the instant-search fast path — and
	 * so cannot ask {@see self::is_active()}. It answers a narrower question:
	 * the plugin is activated, not that it is loaded and working. That is
	 * enough to know the index holds product fields worth searching.
	 *
	 * @since 0.11.0
	 *
	 * @return bool
	 */
	public static function is_switched_on(): bool {
		$plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$plugins = array_merge( $plugins, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		foreach ( $plugins as $plugin ) {
			if ( is_string( $plugin ) && 'woocommerce.php' === basename( $plugin ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Attach everything that depends on WooCommerce.
	 *
	 * @since 0.7.0
	 *
	 * @param Config   $config          Configuration.
	 * @param DirtySet $dirty           Dirty set.
	 * @param callable $search_factory  Returns the search service.
	 * @param callable $filters_factory Returns the filter registry.
	 * @param int      $site_id         Site identifier.
	 * @return void
	 */
	public static function attach(
		Config $config,
		DirtySet $dirty,
		callable $search_factory,
		callable $filters_factory,
		int $site_id
	): void {
		if ( ! self::is_active() || ! $config->get_bool( 'index.products', true ) ) {
			return;
		}

		// Declared through the documented hook rather than by editing anybody's
		// configuration, so the save listeners and a full sync reach the same
		// answer: a shop that installs a search plugin means its products.
		add_filter(
			'solrengine_indexable_post_types',
			static function ( $types ): array {
				$types = array_map( 'strval', (array) $types );

				$types[] = ProductDocument::POST_TYPE;

				return array_values( array_unique( $types ) );
			}
		);

		( new ProductDocument(
			$config->get_bool( 'index.stock_quantity', false ),
			$config->get_int( 'index.low_stock_threshold', 2 ),
			$config->get_int( 'index.variation_limit', 100 )
		) )->register();

		( new ProductEvents( $dirty, $site_id ) )->register();

		( new ShopIntegration( $search_factory, $filters_factory, $config, $site_id ) )->register();
	}

	/**
	 * Attach the parts the registries need.
	 *
	 * Separate from attach(), and called earlier, because the facet and filter
	 * registries are built the first time anything searches — which can be
	 * before, or instead of, the rest of the integration being needed.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public static function attach_registries(): void {
		if ( ! self::is_active() ) {
			return;
		}

		( new ProductFacets() )->register();
	}
}
