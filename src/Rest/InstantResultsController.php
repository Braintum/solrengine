<?php
/**
 * The search popup's REST route.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Search\InstantResults;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * `GET /solrengine/v1/instant` — results and tab counts for the search popup.
 *
 * Calls {@see InstantResults} and returns its body unchanged.
 *
 * Narrower than `/search` on purpose. It takes text, one content-type tab and
 * a page, nothing else: the popup has no use for sorting or arbitrary filters,
 * and every parameter is one a lighter surface could honour identically.
 *
 * @since 1.0.0
 */
final class InstantResultsController {

	/**
	 * Rate limiter bucket.
	 *
	 * @var string
	 */
	public const ROUTE = 'instant';

	/**
	 * Builds the answering service.
	 *
	 * A factory, for the reason {@see SearchController} takes one: routes are
	 * registered on every REST request, and building the search service
	 * validates the configured endpoint.
	 *
	 * @var callable
	 */
	private $instant_factory;

	/**
	 * Rate limiter.
	 *
	 * @var RateLimiter
	 */
	private $limiter;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Constructor.
	 *
	 * @param callable    $instant Returns the InstantResults service.
	 * @param RateLimiter $limiter Rate limiter.
	 * @param int         $site_id Site identifier.
	 */
	public function __construct( callable $instant, RateLimiter $limiter, int $site_id ) {
		$this->instant_factory = $instant;
		$this->limiter         = $limiter;
		$this->site_id         = $site_id;
	}

	/**
	 * Register the route.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			SearchController::NAMESPACE,
			'/' . self::ROUTE,
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				// Public: it serves what a visitor can already read, through
				// the same site scope and visibility filter as /search.
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && mb_strlen( $value ) <= InstantResults::MAX_LENGTH;
						},
					),
					'post_type' => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
					),
					// @since 1.0.0. A page of a type tab. InstantResults clamps
					// it.
					'page'      => array(
						'type'              => 'integer',
						'default'           => 1,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Answer the popup.
	 *
	 * @since 1.0.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$allowed = $this->limiter->check( self::ROUTE );

		if ( $allowed->is_failure() ) {
			return new WP_Error(
				'solrengine_rate_limited',
				__( 'Too many searches. Please wait a moment.', 'solrengine' ),
				array(
					'status'      => 429,
					'retry_after' => (int) ( $allowed->context()['retry_after'] ?? 10 ),
				)
			);
		}

		$instant = ( $this->instant_factory )();

		if ( ! $instant instanceof InstantResults ) {
			return $this->unavailable();
		}

		$result = $instant->answer(
			(string) $request->get_param( 'q' ),
			(string) $request->get_param( 'post_type' ),
			$this->site_id,
			(int) $request->get_param( 'page' )
		);

		if ( $result->is_failure() ) {
			// A 503 rather than an empty list. The popup tells the visitor
			// search is unavailable and offers the site's own search page,
			// which falls back to WordPress — an honest answer, where an empty
			// panel would claim nothing matched.
			return $this->unavailable();
		}

		$response = new WP_REST_Response( $result->value() );

		// A short private cache. Never shared: a proxy holding one would
		// outlive an unpublish.
		if ( ! is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'private, max-age=30' );
		}

		return $response;
	}

	/**
	 * The backend-unavailable error.
	 *
	 * @return WP_Error
	 */
	private function unavailable(): WP_Error {
		return new WP_Error(
			'solrengine_backend_unavailable',
			__( 'Search is temporarily unavailable.', 'solrengine' ),
			array(
				'status'      => 503,
				'retry_after' => 30,
			)
		);
	}
}
