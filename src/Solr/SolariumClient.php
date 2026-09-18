<?php
/**
 * SolariumClient class for interacting with Apache Solr using Solarium library.
 *
 * @package SolrEngine\Solr
 */

namespace SolrEngine\Solr;

use SolrEngine\Interfaces\SolrClientInterface;
use Solarium\Client;
use Solarium\Core\Client\Adapter\Curl;
use Solarium\Exception\HttpException;

/**
 * Class SolariumClient
 *
 * Solarium-based implementation for Solr communication.
 */
class SolariumClient implements SolrClientInterface {

	/**
	 * Solarium client instance.
	 *
	 * @var Client
	 */
	protected Client $client;

	/**
	 * Solr endpoint URL.
	 *
	 * @var string
	 */
	protected string $endpoint;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int
	 */
	protected int $timeout;

	/**
	 * Prefixed core name.
	 *
	 * @var string
	 */
	protected string $prefixed_core;

	/**
	 * Constructor.
	 *
	 * @param string $endpoint Solr endpoint URL.
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( string $endpoint, int $timeout = 5 ) {
		$this->endpoint = $endpoint;
		$this->timeout  = $timeout;
	}

	/**
	 * Create and configure the Solarium client.
	 *
	 * @param string $core Solr core/collection name.
	 */
	public function create( $core ) {

		$this->prefixed_core = $this->prefix_core( $core );

		$parsed_url = wp_parse_url( $this->endpoint );
		$config     = array(
			'endpoint' => array(
				'localhost' => array(
					'host'    => $parsed_url['host'] ?? 'localhost',
					'port'    => $parsed_url['port'] ?? 8983,
					'path'    => $parsed_url['path'] ?? '/',
					'scheme'  => $parsed_url['scheme'] ?? 'http',
					'timeout' => $this->timeout,
					'core'    => $this->prefixed_core,
				),
			),
		);

		// Create client with configuration.
		$adapter = new Curl();
		// Create a minimal event dispatcher implementation.
		$event_dispatcher = new class() implements \Psr\EventDispatcher\EventDispatcherInterface {
			/**
			 * Dispatches the given event to all relevant listeners.
			 *
			 * @param object $event The event to dispatch.
			 * @return object The event object.
			 */
			public function dispatch( object $event ) {
				return $event;
			}
		};

		$this->client = new Client( $adapter, $event_dispatcher );
		$this->client->setOptions( $config );

		$this->add_core_if_not_existing();
	}

	/**
	 * Prefix the Solr core name with the blog name.
	 *
	 * @param string $core Core name.
	 * @return string Prefixed core name.
	 */
	private function prefix_core( $core ) {
		$prefix = get_bloginfo( 'name' );

		$prefix = strtolower( $prefix );
		$prefix = str_replace( ' - ', ' ', $prefix );
		$prefix = str_replace( ' ', '_', $prefix );

		return $prefix . '_' . $core;
	}

	/**
	 * Add Solr core if it does not exist.
	 */
	public function add_core_if_not_existing() {
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( 'LOOP Search – Checking if solr core(index) "' . $this->prefixed_core . '" exists' );
		}

		// Create a core admin query.
		$core_admin_query = $this->client->createCoreAdmin();

		// Build a Status action.
		$status_action = $core_admin_query->createStatus();
		$status_action->setCore( $this->prefixed_core );
		$core_admin_query->setAction( $status_action );

		$response      = $this->client->coreAdmin( $core_admin_query );
		$status_result = $response->getStatusResult();

		if ( $status_result->getUptime() === 0 ) {
			if ( defined( 'WP_CLI' ) && WP_CLI ) {
				\WP_CLI::log( 'LOOP Search – Creating solr core(index) "' . $this->prefixed_core . '"' );
			}
			$this->create_core();
		}
	}

	/**
	 * Create Solr core.
	 */
	private function create_core() {
		// Create a core admin query.
		$core_admin_query = $this->client->createCoreAdmin();

		// Build a Create action.
		$create_action = $core_admin_query->createCreate();
		$create_action->setCore( $this->prefixed_core );
		$create_action->setConfigSet( 'solrengine' );

		$core_admin_query->setAction( $create_action );

		$response = $this->client->coreAdmin( $core_admin_query );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::log( 'LOOP Search – Solr core(index) "' . $this->prefixed_core . '" created' );
		}
	}

	/**
	 * Update documents in Solr.
	 *
	 * @param array $docs    Documents to update.
	 * @param array $options Additional options.
	 * @return array Response data.
	 * @throws \RuntimeException On update failure.
	 */
	public function update( array $docs, array $options = array() ) {
		try {
			// Create an update query instance.
			$update = $this->client->createUpdate();

			// Add documents.
			foreach ( $docs as $doc ) {
				$document = $update->createDocument();
				foreach ( $doc as $field => $value ) {
					$document[ $field ] = $value;
				}
				$update->addDocument( $document );
			}

			// Add a commit command to the update query.
			$update->addCommit();

			// Execute the query and return the response.
			$result = $this->client->update( $update );

			return array(
				'status' => $result->getStatus(),
				'body'   => array(
					'responseHeader' => array(
						'status' => $result->getStatus(),
						'QTime'  => $result->getQueryTime(),
					),
				),
			);

		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Solarium update failed: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Delete documents by ID.
	 *
	 * @param array $ids Document IDs to delete.
	 * @return array Response data.
	 * @throws \RuntimeException On delete failure.
	 */
	public function deleteById( array $ids ) {
		try {
			// Create an update query instance.
			$update = $this->client->createUpdate();

			// Add delete by ID commands.
			foreach ( $ids as $id ) {
				$update->addDeleteById( $id );
			}

			// Add a commit command.
			$update->addCommit();

			// Execute the query.
			$result = $this->client->update( $update );

			return array(
				'status' => $result->getStatus(),
				'body'   => array(
					'responseHeader' => array(
						'status' => $result->getStatus(),
						'QTime'  => $result->getQueryTime(),
					),
				),
			);

		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Solarium delete failed: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Search Solr.
	 *
	 * @param string $q      Query string.
	 * @param array  $params Additional search parameters.
	 * @return array Response data.
	 * @throws \RuntimeException On search failure.
	 */
	public function search( string $q, array $params = array() ) {
		try {
			// Create a select query instance.
			$query = $this->client->createSelect();

			// Set the query string.
			$query->setQuery( $q );

			// Set additional parameters.
			foreach ( $params as $key => $value ) {
				switch ( $key ) {
					case 'rows':
						$query->setRows( (int) $value );
						break;
					case 'start':
						$query->setStart( (int) $value );
						break;
					case 'fl':
						$query->setFields( $value );
						break;
					case 'fq':
						if ( is_array( $value ) ) {
							foreach ( $value as $filter ) {
								$query->createFilterQuery( uniqid() )->setQuery( $filter );
							}
						} else {
							$query->createFilterQuery( uniqid() )->setQuery( $value );
						}
						break;
					case 'sort':
						$query->addSort( $value, isset( $params['sort_order'] ) ? $params['sort_order'] : 'asc' );
						break;
					default:
						// For other parameters, use addParam.
						$query->addParam( $key, $value );
						break;
				}
			}

			// Execute the query.
			$result = $this->client->select( $query );

			// Convert Solarium result to array format matching SolrClient.
			$docs = array();
			foreach ( $result as $document ) {
				$docs[] = $document->getFields();
			}

			return array(
				'status' => 200, // Solarium doesn't expose HTTP status directly.
				'body'   => array(
					'responseHeader' => array(
						'status' => 0,
						'QTime'  => $result->getQueryTime(),
					),
					'response'       => array(
						'numFound' => $result->getNumFound(),
						'start'    => isset( $params['start'] ) ? (int) $params['start'] : 0,
						'docs'     => $docs,
					),
				),
			);

		} catch ( \Exception $e ) {
			throw new \RuntimeException( 'Solarium search failed: ' . esc_html( $e->getMessage() ) );
		}
	}

	/**
	 * Ping Solr to verify connection.
	 *
	 * @return bool True if Solr is reachable, false otherwise.
	 */
	public function ping(): bool {
		try {
			$ping   = $this->client->createPing();
			$result = $this->client->ping( $ping );
			return $result->getStatus() === 0;
		} catch ( \Exception $e ) {
			error_log( '[WP Solr Search] Ping failed: ' . $e->getMessage() );
			return false;
		}
	}
}
