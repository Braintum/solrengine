<?php
/**
 * Sends Solr requests through the WordPress HTTP API.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Result;

/**
 * The default sender, and the one to prefer wherever it is available.
 *
 * Going through `wp_remote_request()` means a site's HTTP filters, proxy
 * settings and transport overrides all apply to Solr traffic, which is what an
 * administrator expects of a plugin's outbound requests.
 *
 * @since 1.0.0
 */
final class WpHttpSender implements Sender {

	/**
	 * Whether this sender can run in the current process.
	 *
	 * False in a process that has not loaded the HTTP API, such as a
	 * `SHORTINIT` boot. Requests then fail with a stable code rather than a
	 * fatal error.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return function_exists( 'wp_remote_request' );
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
			return Result::failure( 'no_transport', 'The WordPress HTTP API is unavailable.' );
		}

		$args = array(
			'method'      => $method,
			'timeout'     => $timeout,
			'redirection' => 0,
			'sslverify'   => $verify_tls,
			'headers'     => $headers,
		);

		if ( null !== $body ) {
			$args['body'] = $body;
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			// The prototype checked whether the body was false, which it never
			// is, and so reported every network failure as a success.
			$message = $response->get_error_message();

			return Result::failure(
				self::classify( $message ),
				$message
			);
		}

		return Result::success(
			array(
				'status' => (int) wp_remote_retrieve_response_code( $response ),
				'body'   => (string) wp_remote_retrieve_body( $response ),
			)
		);
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
	private static function classify( string $message ): string {
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
}
