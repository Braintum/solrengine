<?php
/**
 * Interface for searcher classes.
 *
 * @package SolrEngine\Interfaces
 */

namespace SolrEngine\Interfaces;

/**
 * Interface for searcher classes.
 */
interface SearcherInterface {
	/**
	 * Execute a search and return structured results.
	 *
	 * @param string $query   The search query.
	 * @param array  $options Optional search options.
	 * @return array Structured search results.
	 */
	public function search( string $query, array $options = array() ): array;
}
