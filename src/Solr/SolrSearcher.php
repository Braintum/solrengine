<?php
/**
 * Class SolrSearcher
 *
 * Implements the SearcherInterface to perform searches using a SolrClient.
 *
 * @package SolrEngine\Solr
 */

namespace SolrEngine\Solr;

use SolrEngine\Interfaces\SearcherInterface;
use SolrEngine\Interfaces\SolrClientInterface;

/**
 * Class SolrSearcher
 *
 * Implements the SearcherInterface to perform searches using a SolrClient.
 */
class SolrSearcher implements SearcherInterface {

	/**
	 * Solr client instance.
	 *
	 * @var SolrClientInterface
	 */
	protected SolrClientInterface $client;

	/**
	 * SolrSearcher constructor.
	 *
	 * @param SolrClientInterface $client Instance of SolrClient to interact with Solr server.
	 */
	public function __construct( SolrClientInterface $client ) {
		$this->client = $client;
	}

	/**
	 * Executes a search query against the Solr server.
	 *
	 * @param string $query   The search query string.
	 * @param array  $options Optional parameters for the search.
	 *
	 * @return array Search results in a WordPress-friendly format.
	 */
	public function search( string $query, array $options = array() ): array {
		$params      = $options['params'] ?? array();
		$params['q'] = '' === $query ? '*:*' : $query;
		$resp        = $this->client->search( $params['q'], $params );

		// Convert solr response to WP-friendly format and allow filters.
		$docs = $resp['body']['response']['docs'] ?? array();
		return apply_filters( 'solrengine_search_results', $docs, $query, $options );
	}
}
