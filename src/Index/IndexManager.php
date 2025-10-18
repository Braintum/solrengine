<?php
/**
 * Class IndexManager
 *
 * Manages multiple indexers for different content types.
 *
 * @package SolrEngine\Index
 */

namespace SolrEngine\Index;

use SolrEngine\Interfaces\IndexerInterface;

/**
 * Class IndexManager
 *
 * Manages multiple indexers for different content types.
 *
 * @package SolrEngine\Index
 */
class IndexManager {

	/**
	 * Registered indexers.
	 *
	 * @var IndexerInterface[]
	 */
	protected $indexers = array();

	/**
	 * Register an indexer.
	 *
	 * @param string           $key     Indexer key.
	 * @param IndexerInterface $indexer Indexer instance.
	 */
	public function registerIndexer( string $key, IndexerInterface $indexer ) {
		$this->indexers[ $key ] = $indexer;
	}

	/**
	 * Get an indexer by key.
	 *
	 * @param string $key Indexer key.
	 * @return IndexerInterface|null
	 */
	public function getIndexer( string $key ): ?IndexerInterface {
		return $this->indexers[ $key ] ?? null;
	}

	/**
	 * Index a single item.
	 *
	 * @param string $key Indexer key.
	 * @param int    $id  Item ID.
	 * @return bool
	 */
	public function index( string $key, int $id ): bool {
		$indexer = $this->getIndexer( $key );
		if ( ! $indexer ) {
			return false;
		}
		return $indexer->index( $id );
	}

	/**
	 * Bulk index all registered indexers.
	 *
	 * @param array $args Arguments for each indexer.
	 * @return array
	 */
	public function bulkIndexAll( array $args = array() ) {
		$result = array();
		foreach ( $this->indexers as $key => $indexer ) {
			$result[ $key ] = $indexer->bulkIndex( array(), isset( $args[ $key ] ) ? $args[ $key ] : array() );
		}
		return $result;
	}
}
