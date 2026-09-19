<?php
/**
 * The WooCommerce events that make a product stale.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Indexing\DirtySet;
use WC_Product;

/**
 * Marks products for reindexing when WooCommerce changes them.
 *
 * A product changes in more ways than a post does, and most of them never touch
 * `wp_after_insert_post`: a variation is saved, an order drops the stock, a
 * scheduled sale starts, WooCommerce syncs a variable product's prices. Each
 * one changes a field somebody filters or sorts by, and a shop whose index
 * lags is a shop selling things it says are out of stock.
 *
 * Every event resolves to the **parent** product, because that is what the
 * index holds. A variation saved on its own marks the product it belongs to.
 *
 * Nothing here indexes anything: each listener adds one reference to an
 * in-memory set, which is coalesced and queued at the end of the request. A
 * bulk price change that touches a thousand products therefore costs a thousand
 * array writes and one queued batch, not a thousand round trips (ADR-004).
 *
 * @since 0.7.0
 */
final class ProductEvents {

	/**
	 * The set to mark.
	 *
	 * @var DirtySet
	 */
	private $dirty;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Constructor.
	 *
	 * @param DirtySet $dirty   Dirty set.
	 * @param int      $site_id Site identifier.
	 */
	public function __construct( DirtySet $dirty, int $site_id ) {
		$this->dirty   = $dirty;
		$this->site_id = $site_id;
	}

	/**
	 * Attach the listeners.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_new_product', array( $this, 'on_product_saved' ) );
		add_action( 'woocommerce_update_product', array( $this, 'on_product_saved' ) );

		// A variation is not a document. Saving, trashing or deleting one
		// changes the parent's price range, stock and SKU list, so the parent
		// is what goes stale.
		add_action( 'woocommerce_save_product_variation', array( $this, 'on_variation_changed' ) );
		add_action( 'woocommerce_delete_product_variation', array( $this, 'on_variation_changed' ) );
		add_action( 'woocommerce_trash_product_variation', array( $this, 'on_variation_changed' ) );

		// Stock moves on every order. These fire without a product save, and
		// they are the highest-frequency events in a busy shop.
		add_action( 'woocommerce_product_set_stock', array( $this, 'on_stock_changed' ) );
		add_action( 'woocommerce_variation_set_stock', array( $this, 'on_stock_changed' ) );

		// After WooCommerce recomputes a variable product's price range.
		add_action( 'woocommerce_variable_product_sync_data', array( $this, 'on_stock_changed' ) );
	}

	/**
	 * A product was created or updated.
	 *
	 * @since 0.7.0
	 *
	 * @param int $product_id Product identifier.
	 * @return void
	 */
	public function on_product_saved( $product_id ): void {
		$this->mark( (int) $product_id, 'product_saved' );

		// A new or edited product may be the most expensive one in the shop,
		// and the price facet's bands are derived from that number.
		ProductFacets::forget_price_ceiling();
	}

	/**
	 * A variation changed.
	 *
	 * @since 0.7.0
	 *
	 * @param int $variation_id Variation identifier.
	 * @return void
	 */
	public function on_variation_changed( $variation_id ): void {
		$parent = (int) wp_get_post_parent_id( (int) $variation_id );

		$this->mark( $parent, 'variation_changed' );

		ProductFacets::forget_price_ceiling();
	}

	/**
	 * Stock changed, or a variable product was synced.
	 *
	 * @since 0.7.0
	 *
	 * @param WC_Product|int $product Product or identifier.
	 * @return void
	 */
	public function on_stock_changed( $product ): void {
		if ( $product instanceof WC_Product ) {
			$parent = (int) $product->get_parent_id();

			$this->mark( $parent > 0 ? $parent : (int) $product->get_id(), 'stock_changed' );

			return;
		}

		$id     = (int) $product;
		$parent = (int) wp_get_post_parent_id( $id );

		$this->mark( $parent > 0 ? $parent : $id, 'stock_changed' );
	}

	/**
	 * Mark one product stale.
	 *
	 * @param int    $product_id Product identifier.
	 * @param string $reason     Why it is stale.
	 * @return void
	 */
	private function mark( int $product_id, string $reason ): void {
		if ( $product_id <= 0 ) {
			return;
		}

		$this->dirty->mark_for_index( new DocumentRef( $this->site_id, 'post', $product_id ), $reason );
	}
}
