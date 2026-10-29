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
 * Sends requests to Solr.
 *
 * This is the single place that decides what a Solr request looks like and what
 * a reply means. Putting the bytes on the wire is delegated to a {@see Sender},
 * which is the only part that varies by environment: {@see WpHttpSender} uses
 * the WordPress HTTP API and is the default, and tests inject their own.
 * Senders hold no policy, so there is no second copy of any of the rules below
 * to keep in step.
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
	 * Puts requests on the wire.
	 *
	 * @var Sender
	 */
	private $sender;

	/**
	 * Constructor.
	 *
	 * @param Endpoint    $endpoint    Validated endpoint.
	 * @param Credentials $credentials Credential store.
	 * @param Logger      $logger      Logger.
	 * @param int         $timeout     Seconds to wait for a response.
	 * @param bool        $verify_tls  Whether to verify TLS certificates.
	 * @param Sender|null $sender      Wire transport. Defaults to the WordPress
	 *                                 HTTP API, falling back to cURL in a
	 *                                 process that has none.
	 */
	public function __construct(
		Endpoint $endpoint,
		Credentials $credentials,
		Logger $logger,
		int $timeout = 10,
		bool $verify_tls = true,
		?Sender $sender = null
	) {
		$this->endpoint    = $endpoint;
		$this->credentials = $credentials;
		$this->logger      = $logger;
		$this->timeout     = max( 1, min( 120, $timeout ) );
		$this->verify_tls  = $verify_tls;
		$this->sender      = $sender ?? self::default_sender();
	}

	/**
	 * The sender to use when the caller does not name one.
	 *
	 * @return Sender
	 */
	private static function default_sender(): Sender {
		return new WpHttpSender();
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
	 * GET a path within a named collection other than the configured one.
	 *
	 * Needed by the rebuild lifecycle, which has to count the documents in a
	 * replacement index before that index is the live one. The name is checked
	 * rather than trusted: it becomes a path segment, and a name carrying a
	 * slash would address a different handler entirely. Names reaching here come
	 * from configuration and from Solr's own listings, so the check is guarding
	 * against a future caller rather than against today's.
	 *
	 * @since 0.8.0
	 *
	 * @param string               $collection Collection or core name.
	 * @param string               $path       Path relative to the collection.
	 * @param array<string,scalar> $query      Query parameters.
	 * @return Result Success carrying the decoded body, or a failure.
	 */
	public function get_in( string $collection, string $path, array $query = array() ): Result {
		if ( ! CollectionName::is_valid( $collection ) ) {
			return Result::failure( 'invalid_collection', 'That is not a usable collection name.' );
		}

		return $this->request(
			'GET',
			$this->endpoint->with_collection( $collection )->collection_url( $path ),
			$query,
			null
		);
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

		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => self::user_agent(),
		);

		$auth = $this->authorization_header();

		if ( '' !== $auth ) {
			$headers['Authorization'] = $auth;
		}

		$encoded = null;

		if ( null !== $body ) {
			$encoded = wp_json_encode( $body );

			if ( false === $encoded ) {
				return Result::failure( 'unencodable_request', 'The request body could not be encoded as JSON.' );
			}

			$headers['Content-Type'] = 'application/json';
		}

		$started = microtime( true );

		$sent = $this->sender->send(
			$method,
			$url,
			$headers,
			$encoded,
			$this->timeout,
			$this->verify_tls,
			self::MAX_RESPONSE_BYTES
		);

		$elapsed = (int) round( ( microtime( true ) - $started ) * 1000 );

		return $this->interpret( $sent, $method, $url, $elapsed );
	}

	/**
	 * Turn what the sender brought back into a Result.
	 *
	 * @param Result $sent    The sender's result.
	 * @param string $method  HTTP method, for logging.
	 * @param string $url     URL, for logging.
	 * @param int    $elapsed Milliseconds taken.
	 * @return Result
	 */
	private function interpret( Result $sent, string $method, string $url, int $elapsed ): Result {
		if ( $sent->is_failure() ) {
			$this->logger->warning(
				'Solr request failed',
				array(
					'method' => $method,
					'url'    => $url,
					'error'  => $sent->message(),
					'ms'     => $elapsed,
				)
			);

			return Result::failure(
				$sent->code(),
				$sent->message(),
				array( 'ms' => $elapsed )
			);
		}

		$received = (array) $sent->value( array() );
		$status   = (int) ( $received['status'] ?? 0 );
		$body     = (string) ( $received['body'] ?? '' );

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
	 * The User-Agent Solr sees.
	 *
	 * Identifies the site so an administrator reading Solr's access log can tell
	 * which one is querying. Falls back to the stored option when `home_url()`
	 * is not loaded, which gives the same answer.
	 *
	 * @return string
	 */
	private static function user_agent(): string {
		if ( function_exists( 'home_url' ) ) {
			$site = home_url( '/' );
		} else {
			$site = (string) get_option( 'siteurl', '' );
		}

		return 'SolrEngine/' . SOLRENGINE_VERSION . ( '' === $site ? '' : '; ' . $site );
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
