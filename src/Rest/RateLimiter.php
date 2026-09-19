<?php
/**
 * Best-effort request throttling.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Contracts\Result;

/**
 * Limits how often one client may call a public endpoint.
 *
 * **This is not denial-of-service protection, and the documentation says so.**
 * It discourages casual abuse and accidental request loops. Real protection
 * belongs at the CDN or the web server, where a request can be refused before
 * PHP starts.
 *
 * Two honest limitations:
 *
 * - Without a persistent object cache the counters live only for the request,
 *   so the limiter does nothing at all. `is_effective()` reports that, and the
 *   admin dashboard shows it rather than implying protection that is absent.
 * - The client is identified by IP, which a proxy or a shared network makes
 *   approximate in both directions.
 *
 * @since 0.4.0
 */
final class RateLimiter {

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private const GROUP = 'solrengine_rate';

	/**
	 * Requests allowed per window.
	 *
	 * @var int
	 */
	private $limit;

	/**
	 * Window length in seconds.
	 *
	 * @var int
	 */
	private $window;

	/**
	 * Constructor.
	 *
	 * @param int $limit  Requests allowed per window.
	 * @param int $window Window length in seconds.
	 */
	public function __construct( int $limit = 60, int $window = 60 ) {
		$this->limit  = max( 1, $limit );
		$this->window = max( 1, $window );
	}

	/**
	 * Whether throttling can actually work here.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function is_effective(): bool {
		return (bool) wp_using_ext_object_cache();
	}

	/**
	 * Record a request and say whether it is allowed.
	 *
	 * @since 0.4.0
	 *
	 * @param string $route Route being called.
	 * @return Result Success when allowed; a failure carrying retry_after when not.
	 */
	public function check( string $route ): Result {
		/**
		 * Filters whether a request is subject to rate limiting.
		 *
		 * Returning false skips the check, which is how a site exempts its own
		 * front end or a trusted integration.
		 *
		 * @since 0.4.0
		 *
		 * @param bool   $limited Whether to apply the limit.
		 * @param string $route   Route being called.
		 */
		if ( ! apply_filters( 'solrengine_rate_limit_request', true, $route ) ) {
			return Result::success();
		}

		// Logged-in users are not throttled. They are authenticated, and an
		// editor testing search should not be locked out of their own site.
		if ( is_user_logged_in() ) {
			return Result::success();
		}

		$key   = $route . '_' . md5( $this->client_id() );
		$count = wp_cache_get( $key, self::GROUP );

		if ( ! is_numeric( $count ) ) {
			wp_cache_set( $key, 1, self::GROUP, $this->window );

			return Result::success();
		}

		$count = (int) $count;

		if ( $count >= $this->limit ) {
			return Result::failure(
				'rate_limited',
				'Too many requests.',
				array( 'retry_after' => $this->window )
			);
		}

		wp_cache_set( $key, $count + 1, self::GROUP, $this->window );

		return Result::success();
	}

	/**
	 * Identify the client.
	 *
	 * REMOTE_ADDR only. Forwarded-for headers are trivially spoofed, so
	 * honouring one would let a client reset its own counter by changing a
	 * header. Sites behind a proxy should set REMOTE_ADDR correctly at the
	 * proxy, which is the normal arrangement.
	 *
	 * @return string
	 */
	private function client_id(): string {
		$address = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		return '' !== $address ? $address : 'unknown';
	}
}
