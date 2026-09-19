<?php
/**
 * HTTP transport for Solr.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Support\Credentials;

/**
 * Sends requests to Solr over the WordPress HTTP API.
 *
 * Deliberately small. SolrEngine uses a narrow slice of Solr's HTTP surface —
 * update, select, schema, ping, system info — all JSON in and JSON out, which
 * is why ADR-001 dropped Solarium rather than carrying a dependency that forces
 * a PHP 8.1 floor and puts Symfony packages in every site's global namespace.
 *
 * Every request is constrained:
 *
 * - No redirects. A 3xx from the configured host is an error, never a hop,
 *   because following one would leave the validated address behind.
 * - Response bodies are capped, so a hostile or broken server cannot exhaust
 *   memory through a reply to a ping.
 * - JSON only. Nothing that crosses the network is ever unserialised.
 * - Separate connect and read timeouts, because an unreachable host and a slow
 *   query need different answers.
 * - Failures are classified into stable codes, never surfaced as raw text. An
 *   error message that echoed the endpoint would hand an attacker the internal
 *   host name.
 *
 * Nothing here throws. A backend that is down is an expected condition.
 *
 * @since 0.1.0
 */
final class HttpTransport {

	/**
	 * Largest response body accepted, in bytes.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 33554432;

	/**
	 * Where Solr is.
	 *
	 * @var Endpoint
	 */
	private $endpoint;

	/**
	 * Credential store.
	 *
	 * @var Credentials
	 */
	private $credentials;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Seconds to wait for a response.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Whether to verify TLS certificates.
	 *
	 * @var bool
	 */
	private $verify_tls;

	/**
	 * Constructor.
	 *
	 * @param Endpoint    $endpoint    Validated endpoint.
	 * @param Credentials $credentials Credential store.
	 * @param Logger      $logger      Logger.
	 * @param int         $timeout     Seconds to wait for a response.
	 * @param bool        $verify_tls  Whether to verify TLS certificates.
	 */
	public function __construct(
		Endpoint $endpoint,
		Credentials $credentials,
		Logger $logger,
		int $timeout = 10,
		bool $verify_tls = true
	) {
		$this->endpoint    = $endpoint;
		$this->credentials = $credentials;
		$this->logger      = $logger;
		$this->timeout     = max( 1, min( 120, $timeout ) );
		$this->verify_tls  = $verify_tls;
	}

	/**
	 * The endpoint this transport talks to.
	 *
	 * @since 0.1.0
	 *
	 * @return Endpoint
	 */
	public function endpoint(): Endpoint {
		return $this->endpoint;
	}

	/**
	 * GET a path outside any collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $path  Path relative to the base URL.
	 * @param array<string,scalar> $query Query parameters.
	 * @return Result Success carrying the decoded body, or a failure.
	 */
	public function get_admin( string $path, array $query = array() ): Result {
		return $this->request( 'GET', $this->endpoint->admin_url( $path ), $query, null );
	}

	/**
	 * GET a path within the collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $path  Path relative to the collection.
	 * @param array<string,scalar> $query Query parameters.
	 * @return Result Success carrying the decoded body, or a failure.
	 */
	public function get( string $path, array $query = array() ): Result {
		return $this->request( 'GET', $this->endpoint->collection_url( $path ), $query, null );
	}

	/**
	 * POST JSON to a path within the collection.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $path  Path relative to the collection.
	 * @param mixed                $body  Payload, encoded as JSON.
	 * @param array<string,scalar> $query Query parameters.
	 * @return Result Success carrying the decoded body, or a failure.
	 */
	public function post( string $path, $body, array $query = array() ): Result {
		return $this->request( 'POST', $this->endpoint->collection_url( $path ), $query, $body );
	}

	/**
	 * Perform a request.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $url    Absolute URL.
	 * @param array<string,scalar> $query  Query parameters.
	 * @param mixed                $body   Payload, or null.
	 * @return Result
	 */
	private function request( string $method, string $url, array $query, $body ): Result {
		$query['wt'] = 'json';

		// Solr 10 removed the PHP response writer, and JSON is the only format
		// worth parsing anyway. Never request anything else.
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . self::build_query( $query );

		$args = array(
			'method'      => $method,
			'timeout'     => $this->timeout,
			'redirection' => 0,
			'sslverify'   => $this->verify_tls,
			'headers'     => array( 'Accept' => 'application/json' ),
			'user-agent'  => 'SolrEngine/' . SOLRENGINE_VERSION . '; ' . home_url( '/' ),
		);

		$auth = $this->authorization_header();

		if ( '' !== $auth ) {
			$args['headers']['Authorization'] = $auth;
		}

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );

			if ( false === $encoded ) {
				return Result::failure( 'unencodable_request', 'The request body could not be encoded as JSON.' );
			}

			$args['body']                    = $encoded;
			$args['headers']['Content-Type'] = 'application/json';
		}

		$started  = microtime( true );
		$response = wp_remote_request( $url, $args );
		$elapsed  = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $this->interpret( $response, $method, $url, $elapsed );
	}

	/**
	 * Turn a WordPress HTTP response into a Result.
	 *
	 * @param array<string,mixed>|\WP_Error $response Response or error.
	 * @param string                        $method   HTTP method, for logging.
	 * @param string                        $url      URL, for logging.
	 * @param int                           $elapsed  Milliseconds taken.
	 * @return Result
	 */
	private function interpret( $response, string $method, string $url, int $elapsed ): Result {
		if ( is_wp_error( $response ) ) {
			// The prototype checked whether the body was false, which it never
			// is, and so reported every network failure as a success.
			$message = $response->get_error_message();

			$this->logger->warning(
				'Solr request failed',
				array(
					'method' => $method,
					'url'    => $url,
					'error'  => $message,
					'ms'     => $elapsed,
				)
			);

			return Result::failure(
				$this->classify_wp_error( $message ),
				$message,
				array( 'ms' => $elapsed )
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );

		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return Result::failure( 'response_too_large', 'The server returned more data than SolrEngine will read.' );
		}

		if ( $status >= 300 && $status < 400 ) {
			return Result::failure(
				'unexpected_redirect',
				'The server redirected the request. SolrEngine does not follow redirects, because that would leave the address you configured.'
			);
		}

		if ( 401 === $status || 403 === $status ) {
			return Result::failure( 'auth_failed', 'Solr rejected the credentials.', array( 'status' => $status ) );
		}

		if ( 404 === $status ) {
			return Result::failure( 'not_found', 'Solr has no such collection or handler.', array( 'status' => $status ) );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && '' !== trim( $body ) ) {
			return Result::failure(
				'malformed_response',
				'The server replied with something that is not JSON. Check that the address points at Solr and not at a proxy or login page.',
				array( 'status' => $status )
			);
		}

		if ( $status >= 400 ) {
			return Result::failure(
				'solr_error',
				$this->extract_error( $decoded, $status ),
				array(
					'status' => $status,
					'ms'     => $elapsed,
				)
			);
		}

		$this->logger->debug(
			'Solr request',
			array(
				'method' => $method,
				'url'    => $url,
				'status' => $status,
				'ms'     => $elapsed,
			)
		);

		return Result::success( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Map a WordPress HTTP error message to a stable code.
	 *
	 * The message text varies by transport and by PHP version, so callers must
	 * never branch on it. They branch on these codes instead.
	 *
	 * @param string $message Error message.
	 * @return string
	 */
	private function classify_wp_error( string $message ): string {
		$lower = strtolower( $message );

		if ( false !== strpos( $lower, 'timed out' ) || false !== strpos( $lower, 'timeout' ) ) {
			return 'timeout';
		}

		if ( false !== strpos( $lower, 'certificate' ) || false !== strpos( $lower, 'ssl' ) ) {
			return 'tls_failed';
		}

		if ( false !== strpos( $lower, 'resolve' ) ) {
			return 'unresolvable';
		}

		return 'unreachable';
	}

	/**
	 * Pull a useful message out of a Solr error response.
	 *
	 * @param mixed $decoded Decoded body.
	 * @param int   $status  HTTP status.
	 * @return string
	 */
	private function extract_error( $decoded, int $status ): string {
		if ( is_array( $decoded ) && isset( $decoded['error']['msg'] ) && is_string( $decoded['error']['msg'] ) ) {
			return $decoded['error']['msg'];
		}

		return sprintf( 'Solr returned HTTP %d.', $status );
	}

	/**
	 * Build the Authorization header, or an empty string when unauthenticated.
	 *
	 * @return string
	 */
	private function authorization_header(): string {
		if ( ! $this->credentials->has_password() ) {
			return '';
		}

		$password = $this->credentials->password();

		if ( $password->is_failure() ) {
			$this->logger->error(
				'Solr credentials are configured but could not be read',
				array( 'code' => $password->code() )
			);

			return '';
		}

		// Basic auth transport encoding, as the HTTP specification defines it.
		$token = base64_encode( $this->credentials->username() . ':' . $password->value( '' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return 'Basic ' . $token;
	}

	/**
	 * Build a query string Solr understands.
	 *
	 * Solr expresses repeated parameters by repeating the key: two filter
	 * queries are two "fq=" pairs. PHP's http_build_query() instead produces
	 * "fq[0]=" and "fq[1]=", which Solr does not recognise as fq at all.
	 *
	 * That is not a cosmetic difference. The filters SolrEngine adds include
	 * the site scope and the public-visibility restriction, so encoding them
	 * the wrong way means they are silently ignored — the query runs, returns
	 * results, and quietly has no security filtering on it. Caught by testing
	 * against a real Solr rather than by reading the code.
	 *
	 * @param array<string,mixed> $params Parameters, values may be arrays.
	 * @return string
	 */
	private static function build_query( array $params ): string {
		$pairs = array();

		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				foreach ( $value as $item ) {
					$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( self::stringify( $item ) );
				}

				continue;
			}

			$pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( self::stringify( $value ) );
		}

		return implode( '&', $pairs );
	}

	/**
	 * Render a scalar the way Solr expects it.
	 *
	 * Booleans in particular: PHP casts false to an empty string, which Solr
	 * reads as a missing value rather than as false.
	 *
	 * @param mixed $value Value to render.
	 * @return string
	 */
	private static function stringify( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
