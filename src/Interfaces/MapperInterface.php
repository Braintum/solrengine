<?php
/**
 * MapperInterface.php
 *
 * Interface for mapping WordPress objects to indexable arrays for search engines.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Interfaces;

/**
 * Interface MapperInterface
 *
 * Responsible for converting WordPress objects into indexable arrays
 * that the Indexer (e.g. SolrIndexer) can send to the search engine.
 */
interface MapperInterface {

	/**
	 * Map a WP_Post into an indexable document array.
	 *
	 * @param \WP_Post $post WP_Post object.
	 * @return array Associative array representing the document to index.
	 */
	public function mapPost( \WP_Post $post ): array;

	/**
	 * Map a product-like object into an indexable document array.
	 *
	 * Keep the signature generic (object) so this interface doesn't fatal
	 * when WooCommerce isn't available. Implementations can accept WC_Product.
	 *
	 * @param object $product A product object (e.g. \WC_Product).
	 * @return array
	 */
	public function mapProduct( object $product ): array;
}
