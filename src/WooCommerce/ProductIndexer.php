<?php
/**
 * Class ProductIndexer
 *
 * Indexes WooCommerce products into Solr.
 *
 * @package SolrEngine\WooCommerce
 */

namespace SolrEngine\WooCommerce;

use SolrEngine\Interfaces\IndexerInterface;
use SolrEngine\Interfaces\MapperInterface;

/**
 * Class ProductIndexer
 *
 * Indexes WooCommerce products into Solr.
 */
class ProductIndexer implements IndexerInterface {

	/**
	 * Underlying indexer instance.
	 *
	 * @var IndexerInterface
	 */
	protected $indexer;

	/**
	 * Mapper instance for product-specific mapping.
	 *
	 * @var MapperInterface
	 */
	protected MapperInterface $mapper;

	/**
	 * ProductIndexer constructor.
	 *
	 * @param IndexerInterface $indexer Underlying indexer (e.g., SolrIndexer).
	 * @param MapperInterface  $mapper  Mapper for products.
	 */
	public function __construct( IndexerInterface $indexer, MapperInterface $mapper ) {
		$this->indexer = $indexer; // Could be SolrIndexer.
		$this->mapper  = $mapper;
	}

	/**
	 * Index a single product by ID.
	 *
	 * @param int $id Product ID.
	 * @return bool True on success, false on failure.
	 */
	public function index( int $id ): bool {
		$post = get_post( $id );
		if ( ! $post || 'product' !== $post->post_type ) {
			return false;
		}

		// Extend map to include price, SKU, stock, variations.
		$doc = $this->mapper->mapPost( $post );

		// Enrich with WC data.
		if ( class_exists( '\WC_Product_Factory' ) ) {
			$prod = wc_get_product( $id );
			if ( $prod ) {
				$doc['price']        = (float) $prod->get_price();
				$doc['sku']          = (string) $prod->get_sku();
				$doc['stock_status'] = $prod->get_stock_status();
			}
		}

		// Send directly to Solr client via inner indexer.
		if ( method_exists( $this->indexer, 'client' ) ) { //phpcs:ignore
			// If the underlying implementation exposes client, you can call it.
		}

		// Fallback: let underlying indexer handle an array input by ID.
		return $this->indexer->index( $id );
	}

	/**
	 * Delete a product from Solr by ID.
	 *
	 * @param int $id Product ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $id ): bool {
		return $this->indexer->delete( $id );
	}

	/**
	 * Bulk index products.
	 *
	 * @param array $ids  Array of product IDs.
	 * @param array $args WP_Query arguments.
	 * @return array Indexing statistics.
	 */
	public function bulkIndex( array $ids = array(), array $args = array() ): array {
		return $this->indexer->bulkIndex( $ids, $args );
	}
}
