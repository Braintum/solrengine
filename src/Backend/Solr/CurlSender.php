<?php
/**
 * Sends Solr requests through cURL.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Result;

/**
 * The fallback sender, for processes with no WordPress HTTP API.
 *
 * Used by the instant-search entry point, which boots with `SHORTINIT` and so
 * has no `wp_remote_request()` to call. {@see WpHttpSender} stays the default
 * everywhere else, because the HTTP API's filters are worth having wherever
 * they exist.
 *
 * @since 0.10.2
 */
final class CurlSender implements Sender {

	/**
	 * Whether this sender can run in the current process.
	 *
	 * @since 0.10.2
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'curl_init' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string               $method     HTTP method.
	 * @param string               $url        Absolute URL.
	 * @param array<string,string> $headers    Headers as name => value.
	 * @param string|null          $body       Request body, or null.
	 * @param int                  $timeout    Seconds to wait.
	 * @param bool                 $verify_tls Whether to verify TLS.
	 * @param int                  $max_bytes  Largest body to accept.
	 * @return Result
	 */
	public function send(
		string $method,
		string $url,
		array $headers,
		?string $body,
		int $timeout,
		bool $verify_tls,
		int $max_bytes
	): Result {
		if ( ! self::is_available() ) {
			return Result::failure( 'no_transport', 'The cURL extension is unavailable.' );
		}

		$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init

		if ( false === $handle ) {
			return Result::failure( 'no_transport', 'A cURL handle could not be created.' );
		}

		// Read the status from the response's own status line. curl_getinfo()
		// would also give it, at the cost of passing the handle to a second
		// function.
		$status = 0;

		$lines = array();

		foreach ( $headers as $name => $value ) {
			$lines[] = $name . ': ' . $value;
		}

		$options = array(
			CURLOPT_URL              => $url,
			CURLOPT_CUSTOMREQUEST    => $method,
			CURLOPT_RETURNTRANSFER   => true,
			CURLOPT_HTTPHEADER       => $lines,
			CURLOPT_TIMEOUT          => $timeout,
			// Separate from the read timeout, because an unreachable host and a
			// slow query need different answers.
			CURLOPT_CONNECTTIMEOUT   => 1,
			// A 3xx from the configured host is an error, never a hop. Following
			// one would leave the validated address behind.
			CURLOPT_FOLLOWLOCATION   => false,
			CURLOPT_SSL_VERIFYPEER   => $verify_tls,
			CURLOPT_SSL_VERIFYHOST   => $verify_tls ? 2 : 0,
			CURLOPT_HEADERFUNCTION   => static function ( $curl, $line ) use ( &$status ) {
				// The first header line of a response is its status line. Later
				// ones are ignored: nothing here reads response headers, and a
				// proxy may send more than one status line.
				if ( 0 === $status && 1 === preg_match( '#^HTTP/\S+\s+(\d{3})#', (string) $line, $matches ) ) {
					$status = (int) $matches[1];
				}

				return strlen( (string) $line );
			},
			CURLOPT_NOPROGRESS       => false,
			CURLOPT_PROGRESSFUNCTION => static function ( $curl, $expected_down, $down ) use ( $max_bytes ) {
				// Returning non-zero aborts the transfer, so an oversized reply
				// is refused while it streams rather than after it has already
				// been buffered into memory.
				return $down > $max_bytes ? 1 : 0;
			},
		);

		if ( null !== $body ) {
			$options[ CURLOPT_POSTFIELDS ] = $body;
		}

		curl_setopt_array( $handle, $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array

		$received = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$errno    = curl_errno( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno

		curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		if ( 0 !== $errno || ! is_string( $received ) ) {
			return Result::failure( self::classify( $errno ), 'The search server could not be reached.' );
		}

		return Result::success(
			array(
				'status' => $status,
				'body'   => $received,
			)
		);
	}

	/**
	 * Map a cURL error number to a stable code.
	 *
	 * The codes match {@see WpHttpSender}, so a caller branches on them without
	 * knowing which sender answered.
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
}
