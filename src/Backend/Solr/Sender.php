<?php
/**
 * Contract for putting a request on the wire.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

use SolrEngine\Contracts\Result;

/**
 * Carries one HTTP request and brings back the status and body.
 *
 * This is the only part of talking to Solr that differs by environment, so it
 * is the only part with more than one implementation. Everything that decides
 * *what* to send and *what a reply means* — the URL, the query string encoding,
 * the timeouts, the body cap, the status mapping, the error codes — lives in
 * {@see HttpTransport}, once.
 *
 * A sender therefore holds no policy. It is handed a fully-formed request, and
 * its only judgement is classifying a transport failure into one of the stable
 * codes, because a WordPress HTTP error and a cURL error number carry that
 * information differently.
 *
 * Implementations must not throw, must not follow redirects, and must not
 * retry.
 *
 * @since 0.10.2
 */
interface Sender {

	/**
	 * Send a request.
	 *
	 * @since 0.10.2
	 *
	 * @param string               $method     HTTP method.
	 * @param string               $url        Absolute URL, query string included.
	 * @param array<string,string> $headers    Headers as name => value.
	 * @param string|null          $body       Request body, or null.
	 * @param int                  $timeout    Seconds to wait for the response.
	 * @param bool                 $verify_tls Whether to verify TLS certificates.
	 * @param int                  $max_bytes  Largest response body to accept.
	 * @return Result Success carrying `array{status:int,body:string}`, or a
	 *                failure whose code is one of `timeout`, `unresolvable`,
	 *                `tls_failed`, `unreachable` or `no_transport`.
	 */
	public function send(
		string $method,
		string $url,
		array $headers,
		?string $body,
		int $timeout,
		bool $verify_tls,
		int $max_bytes
	): Result;
}
