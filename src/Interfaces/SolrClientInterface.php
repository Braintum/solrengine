<?php
/**
 * SolrClientInterface interface for Solr client implementations.
 *
 * @package SolrEngine\Interfaces
 */

namespace SolrEngine\Interfaces;

/**
 * Interface SolrClientInterface
 *
 * Defines the contract for Solr client implementations.
 */
interface SolrClientInterface {

	/**
	 * Update documents in Solr.
	 *
	 * @param array $docs    Documents to update.
	 * @param array $options Additional options.
	 * @return array Response data.
	 */
	public function update( array $docs, array $options = array() );

	/**
	 * Delete documents by ID.
	 *
	 * @param array $ids Document IDs to delete.
	 * @return array Response data.
	 */
	public function deleteById( array $ids );

	/**
	 * Search Solr.
	 *
	 * @param string $q      Query string.
	 * @param array  $params Additional search parameters.
	 * @return array Response data.
	 */
	public function search( string $q, array $params = array() );

	/**
	 * Ping the Solr server to check if it's reachable.
	 *
	 * @return array Response data.
	 */
	public function ping();

	/**
	 * Create and configure the Solr client for a specific core/collection.
	 *
	 * @param string $core Solr core/collection name.
	 */
	public function create( $core );
}
