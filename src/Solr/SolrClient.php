<?php
/**
 * SolrClient class for interacting with Apache Solr.
 *
 * @package SolrEngine\Solr
 */

namespace SolrEngine\Solr;

use SolrEngine\Interfaces\SolrClientInterface;

/**
 * Class SolrClient
 */
class SolrClient implements SolrClientInterface {

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
	 * Constructor.
	 *
	 * @param string $endpoint Solr endpoint URL.
	 * @param int    $timeout  Request timeout in seconds.
	 */
	public function __construct( string $endpoint, int $timeout = 5 ) {
		$this->endpoint = rtrim( $endpoint, '/' );
		$this->timeout  = $timeout;
	}

	/**
	 * Make a request to Solr.
	 *
	 * @param string       $path    Request path.
	 * @param string       $method  HTTP method.
	 * @param string|array $body    Request body.
	 * @param array        $headers Request headers.
	 * @return array Response data.
	 * @throws \RuntimeException On request failure.
	 */
	protected function request( string $path, string $method = 'GET', $body = null, array $headers = array() ) {
		$url = $this->endpoint . $path;
		$args = array(
			'method'  => $method,
			'timeout' => $this->timeout,
			'headers' => $headers,
		);

		if ( null !== $body ) {
			$args['body']    = is_string( $body ) ? $body : wp_json_encode( $body );
			$args['headers']['Content-Type'] = 'application/json';
		}

		$response = wp_remote_request( $url, $args );
		$resp     = wp_remote_retrieve_body( $response );
		$code     = wp_remote_retrieve_response_code( $response );
		$err      = is_wp_error( $response ) ? $response->get_error_message() : '';

		if ( false === $resp ) {
			throw new \RuntimeException( 'Solr request failed: ' . esc_html( $err ) );
		}

		$data = json_decode( $resp, true );

		return array(
			'status' => $code,
			'body'   => $data ? $data : $resp,
		);
	}

	/**
	 * Update documents in Solr.
	 *
	 * @param array $docs    Documents to update.
	 * @param array $options Additional options.
	 * @return array Response data.
	 */
	public function update( array $docs, array $options = array() ) {
		$path = '/update?commit=true';
		return $this->request( $path, 'POST', $docs );
	}

	/**
	 * Delete documents by ID.
	 *
	 * @param array $ids Document IDs to delete.
	 * @return array Response data.
	 */
	public function deleteById( array $ids ) {
		$body = array( 'delete' => $ids );
		return $this->request( '/update?commit=true', 'POST', $body );
	}

	/**
	 * Search Solr.
	 *
	 * @param string $q      Query string.
	 * @param array  $params Additional search parameters.
	 * @return array Response data.
	 */
	public function search( string $q, array $params = array() ) {
		$params = array_merge(
			array(
				'q'  => $q,
				'wt' => 'json',
			),
			$params
		);
		$qp     = http_build_query( $params );
		return $this->request( '/select?' . $qp, 'GET' );
	}

	/**
	 * Ping the Solr server to check if it's reachable.
	 *
	 * @return array Response data.
	 */
	public function ping() {
		return $this->request( '/admin/ping', 'GET' );
	}

	/**
	 * Create and configure the Solr client for a specific core/collection.
	 *
	 * @param string $core Solr core/collection name.
	 */
	public function create( $core ) {
		$this->endpoint = rtrim( $this->endpoint, '/' ) . '/' . ltrim( $core, '/' );
	}
}
