<?php
/**
 * Minimal Solr transport for the instant-search fast path.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Result;

/**
 * Talks to Solr over cURL, with no WordPress HTTP API underneath.
 *
 * {@see HttpTransport} is the transport everything else uses, and stays the one
 * to reach for: it goes through `wp_remote_request()`, so a site's HTTP filters,
 * proxy configuration and transport overrides all apply to it.
 *
 * This one exists for a single caller — the instant-search entry point, which
 * boots WordPress with `SHORTINIT` and therefore has no HTTP API to call. The
 * whole point of that endpoint is that the plugin stack it runs on is small
 * enough to answer a keystroke, and `wp_remote_request()` is not in it.
 *
 * The constraints {@see HttpTransport} documents are kept, because they are
 * security properties rather than conveniences: no redirects, a capped body,
 * JSON only, split connect and read timeouts, and failures classified into
 * stable codes rather than surfaced as text that would name the internal host.
 *
 * Nothing here throws.
 *
 * @since 0.10.2
 */
final class CurlTransport {

	/**
	 * Largest response body accepted, in bytes.
	 *
	 * Smaller than the general transport's cap. This transport answers one
	 * request shape — a suggest query for at most twenty short rows — so a
	 * reply beyond this is a misconfiguration, not a big result set.
	 *
	 * @var int
	 */
	private const MAX_RESPONSE_BYTES = 1048576;

	/**
	 * Where Solr is.
	 *
	 * @var Endpoint
	 */
	private $endpoint;

	/**
	 * Authorization header value, or an empty string.
	 *
	 * Resolved by the caller rather than read here, so this class never touches
	 * the credential store and cannot log a password it does not hold.
	 *
	 * @var string
	 */
	private $authorization;

	/**
	 * Seconds to wait for the response.
	 *
	 * @var int
	 */
	private $timeout;

	/**
	 * Seconds to wait for the connection.
	 *
	 * @var int
	 */
	private $connect_timeout;

	/**
	 * Whether to verify TLS certificates.
	 *
	 * @var bool
	 */
	private $verify_tls;

	/**
	 * Constructor.
	 *
	 * @param Endpoint $endpoint      Validated endpoint.
	 * @param string   $authorization Authorization header value, or ''.
	 * @param int      $timeout       Seconds to wait for a response.
	 * @param bool     $verify_tls    Whether to verify TLS certificates.
	 */
	public function __construct(
		Endpoint $endpoint,
		string $authorization = '',
		int $timeout = 2,
		bool $verify_tls = true
	) {
		$this->endpoint        = $endpoint;
		$this->authorization   = $authorization;
		$this->timeout         = max( 1, min( 30, $timeout ) );
		$this->connect_timeout = 1;
		$this->verify_tls      = $verify_tls;
	}

	/**
	 * GET a path within the collection.
	 *
	 * @since 0.10.2
	 *
	 * @param string              $path  Path relative to the collection.
	 * @param array<string,mixed> $query Query parameters.
	 * @return Result Success carrying the decoded body, or a failure.
	 */
	public function get( string $path, array $query = array() ): Result {
		if ( ! function_exists( 'curl_init' ) ) {
			return Result::failure( 'no_transport', 'The cURL extension is unavailable.' );
		}

		$query['wt'] = 'json';

		$url  = $this->endpoint->collection_url( $path );
		$url .= ( false === strpos( $url, '?' ) ? '?' : '&' ) . self::build_query( $query );

		$headers = array( 'Accept: application/json' );

		if ( '' !== $this->authorization ) {
			$headers[] = 'Authorization: ' . $this->authorization;
		}

		$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

		if ( false === $handle ) {
			return Result::failure( 'no_transport', 'A cURL handle could not be created.' );
		}

		// Read the status from the response's own status line rather than from
		// curl_getinfo(). Same value, and it keeps the handle out of a second
		// function signature.
		$status = 0;

		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$handle,
			array(
				CURLOPT_URL              => $url,
				CURLOPT_RETURNTRANSFER   => true,
				CURLOPT_HTTPHEADER       => $headers,
				CURLOPT_TIMEOUT          => $this->timeout,
				CURLOPT_CONNECTTIMEOUT   => $this->connect_timeout,
				// A 3xx from the configured host is an error, never a hop.
				// Following one would leave the validated address behind.
				CURLOPT_FOLLOWLOCATION   => false,
				CURLOPT_SSL_VERIFYPEER   => $this->verify_tls,
				CURLOPT_SSL_VERIFYHOST   => $this->verify_tls ? 2 : 0,
				CURLOPT_USERAGENT        => 'SolrEngine/' . SOLRENGINE_VERSION,
				// Stop reading once the reply is larger than this endpoint has
				// any business receiving, rather than buffering it all first.
				CURLOPT_HEADERFUNCTION   => static function ( $curl, $line ) use ( &$status ) {
					// The first header line of a response is its status line.
					// Later ones are ignored: nothing here reads response
					// headers, and a proxy may send more than one status line.
					if ( 0 === $status && 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches ) ) {
						$status = (int) $matches[1];
					}

					return strlen( (string) $line );
				},
				CURLOPT_BUFFERSIZE       => 16384,
				CURLOPT_NOPROGRESS       => false,
				CURLOPT_PROGRESSFUNCTION => static function ( $curl, $expected_down, $down ) {
					// Returning non-zero aborts the transfer, so an oversized
					// reply is refused while it streams rather than after it has
					// already been buffered into memory.
					return $down > self::MAX_RESPONSE_BYTES ? 1 : 0;
				},
			)
		);

		$body  = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno = curl_errno( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno

		curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( 0 !== $errno || ! is_string( $body ) ) {
			return Result::failure( self::classify( $errno ), 'The search server could not be reached.' );
		}

		if ( strlen( $body ) > self::MAX_RESPONSE_BYTES ) {
			return Result::failure( 'response_too_large', 'The server returned more data than SolrEngine will read.' );
		}

		if ( $status >= 300 && $status < 400 ) {
			return Result::failure( 'unexpected_redirect', 'The server redirected the request.' );
		}

		if ( 401 === $status || 403 === $status ) {
			return Result::failure( 'auth_failed', 'Solr rejected the credentials.' );
		}

		if ( 404 === $status ) {
			return Result::failure( 'not_found', 'Solr has no such collection or handler.' );
		}

		$decoded = json_decode( $body, true );

		if ( null === $decoded && '' !== trim( $body ) ) {
			return Result::failure( 'malformed_response', 'The server replied with something that is not JSON.' );
		}

		if ( $status >= 400 ) {
			return Result::failure( 'solr_error', sprintf( 'Solr returned HTTP %d.', $status ) );
		}

		return Result::success( is_array( $decoded ) ? $decoded : array() );
	}

	/**
	 * Map a cURL error number to one of the transport's stable codes.
	 *
	 * The codes match {@see HttpTransport} so a caller can branch on them
	 * without knowing which transport answered.
	 *
	 * @param int $errno cURL error number.
	 * @return string
	 */
	private static function classify( int $errno ): string {
		if ( CURLE_OPERATION_TIMEDOUT === $errno ) {
			return 'timeout';
		}

		if ( CURLE_COULDNT_RESOLVE_HOST === $errno ) {
			return 'unresolvable';
		}

		if ( CURLE_SSL_CONNECT_ERROR === $errno || CURLE_SSL_PEER_CERTIFICATE === $errno ) {
			return 'tls_failed';
		}

		return 'unreachable';
	}

	/**
	 * Build a query string Solr understands.
	 *
	 * Repeated parameters repeat the key, as Solr requires. See
	 * {@see HttpTransport::build_query()} for why `http_build_query()` is
	 * unusable here: it renders filter queries as `fq[0]=`, which Solr does not
	 * read as `fq` at all, silently dropping the site scope and the
	 * visibility restriction.
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
