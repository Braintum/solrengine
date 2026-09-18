<?php
/**
 * Class SolrIndexer
 *
 * Handles indexing and deleting posts in Solr.
 *
 * @package SolrEngine\Solr
 */

namespace SolrEngine\Solr;

use SolrEngine\Interfaces\IndexerInterface;
use SolrEngine\Interfaces\MapperInterface;
use SolrEngine\Interfaces\SolrClientInterface;

/**
 * Class SolrIndexer
 *
 * Handles indexing and deleting posts in Solr.
 */
class SolrIndexer implements IndexerInterface {

	/**
	 * Solr client instance.
	 *
	 * @var SolrClientInterface
	 */
	protected SolrClientInterface $client;

	/**
	 * Mapper instance for converting posts to Solr documents.
	 *
	 * @var MapperInterface
	 */
	protected MapperInterface $mapper;

	/**
	 * SolrIndexer constructor.
	 *
	 * @param SolrClientInterface $client Solr client.
	 * @param MapperInterface     $mapper Mapper for posts.
	 */
	public function __construct( SolrClientInterface $client, MapperInterface $mapper ) {
		$this->client = $client;
		$this->mapper = $mapper;
	}

	/**
	 * Index a single post by ID.
	 *
	 * @param int $id Post ID.
	 * @return bool True on success, false on failure.
	 */
	public function index( int $id ): bool {

		$this->client->create( get_post_type( $id ) );

		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}
		$doc  = $this->mapper->mapPost( $post );
		$resp = $this->client->update( array( $doc ) );
		return ( $resp['status'] >= 200 && $resp['status'] < 300 );
	}

	/**
	 * Delete a post from Solr by ID.
	 *
	 * @param int $id Post ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete( int $id ): bool {
		$resp = $this->client->deleteById( array( $id ) );
		return ( $resp['status'] >= 200 && $resp['status'] < 300 );
	}

	/**
	 * Bulk index posts.
	 *
	 * @param array $ids  Array of post IDs. If empty, uses $args to fetch posts.
	 * @param array $args WP_Query arguments.
	 * @return array Indexed count and response.
	 */
	public function bulkIndex( array $ids = array(), array $args = array() ): array {
		if ( empty( $ids ) ) {
			// Use WP_Query with $args to fetch posts.
			$query = new \WP_Query( $args );
			$ids   = wp_list_pluck( $query->posts, 'ID' );
		}
		$docs = array();
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$docs[] = $this->mapper->mapPost( $post );
			// Batch size commit could be implemented.
		}
		if ( empty( $docs ) ) {
			return array( 'indexed' => 0 );
		}
		$resp = $this->client->update( $docs );
		return array(
			'indexed'  => count( $docs ),
			'response' => $resp,
		);
	}
}
