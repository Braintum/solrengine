<?php
/**
 * IndexerInterface defines methods for indexing and deleting objects in Solr.
 *
 * @package SolrEngine\Interfaces
 */

namespace SolrEngine\Interfaces;

interface IndexerInterface {
	/**
	 * Index a single object (post/product).
	 *
	 * @param int $id Object ID.
	 * @return bool Success status.
	 */
	public function index( int $id ): bool;

	/**
	 * Remove an object from index.
	 *
	 * @param int $id Object ID.
	 * @return bool Success status.
	 */
	public function delete( int $id ): bool;

	/**
	 * Bulk index — accepts array of ids or WP_Query args. Return stats.
	 *
	 * @param array $ids  Array of object IDs.
	 * @param array $args WP_Query arguments.
	 * @return array Indexing statistics.
	 */
	public function bulkIndex( array $ids = array(), array $args = array() ): array;
}
