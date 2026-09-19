<?php
/**
 * Public search endpoint.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\Config;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes search over REST, as a product rather than as a proxy.
 *
 * The rule this endpoint exists to enforce: **a caller may name things, never
 * describe them**. It can ask for the `category` filter; it cannot send a
 * filter query. It can ask to sort by `date_desc`; it cannot name a field. It
 * can ask for `q=laptops`; that text is bound as a parameter and never becomes
 * syntax.
 *
 * Unknown filter keys are rejected rather than ignored. Silently dropping one
 * hides a client bug and makes probing for accepted keys free.
 *
 * @since 0.4.0
 */
final class SearchController {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	public const NAMESPACE = 'solrengine/v1';

	/**
	 * Search service.
	 *
	 * @var SearchService
	 */
	private $search;

	/**
	 * Registered filters.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

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
	 * @param SearchService  $search  Search service.
	 * @param FilterRegistry $filters Registered filters.
	 * @param Config         $config  Configuration.
	 * @param RateLimiter    $limiter Rate limiter.
	 * @param int            $site_id Site identifier.
	 */
	public function __construct(
		SearchService $search,
		FilterRegistry $filters,
		Config $config,
		RateLimiter $limiter,
		int $site_id
	) {
		$this->search  = $search;
		$this->filters = $filters;
		$this->config  = $config;
		$this->limiter = $limiter;
		$this->site_id = $site_id;
	}

	/**
	 * Register the routes.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		register_rest_route(
			self::NAMESPACE,
			'/search',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle' ),
				// Public by design: this serves the same content a visitor can
				// already read. Everything that could widen that is refused by
				// the argument schema below.
				'permission_callback' => '__return_true',
				'args'                => $this->args(),
			)
		);
	}

	/**
	 * The argument schema.
	 *
	 * Declarative, so validation is reviewable in one place rather than
	 * scattered through the handler.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function args(): array {
		return array(
			'q'         => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && strlen( $value ) <= 512;
				},
			),
			'page'      => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				// Hard ceiling. Without it a caller can ask for ten thousand
				// documents per request and make the server do the work.
				'maximum'           => 50,
				'sanitize_callback' => 'absint',
			),
			'sort'      => array(
				'type'    => 'string',
				'default' => 'relevance',
				// An enum, not a field name. A caller naming a field would
				// leak the schema and could sort on fields without docValues.
				'enum'    => QueryTranslator::sort_keys(),
			),
			'post_type' => array(
				'type'              => 'array',
				'default'           => array(),
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => static function ( $value ): array {
					return array_map( 'sanitize_key', (array) $value );
				},
			),
			'filters'   => array(
				'type'    => 'object',
				'default' => array(),
			),
			'highlight' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Handle a search request.
	 *
	 * @since 0.4.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle( WP_REST_Request $request ) {
		$allowed = $this->limiter->check( 'search' );

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

		$query = new SearchQuery( (string) $request->get_param( 'q' ), $this->site_id );

		$post_types = $this->allowed_post_types( (array) $request->get_param( 'post_type' ) );

		if ( array() !== $post_types ) {
			$query = $query->with_post_types( $post_types );
		}

		$filters = $this->validate_filters( (array) $request->get_param( 'filters' ) );

		if ( $filters instanceof WP_Error ) {
			return $filters;
		}

		foreach ( $filters as $key => $values ) {
			$query = $query->with_filter( $key, $values );
		}

		$query = $query
			->with_sort( (string) $request->get_param( 'sort' ) )
			->with_pagination( (int) $request->get_param( 'page' ), (int) $request->get_param( 'per_page' ) )
			->with_highlight( (bool) $request->get_param( 'highlight' ) );

		$result = $this->search->search( $query );

		if ( $result->is_failure() ) {
			// Deliberately not a silent fallback to WordPress results. A client
			// calling this API expects the search API's behaviour; quietly
			// serving something else would make the outage invisible to it.
			return new WP_Error(
				'solrengine_backend_unavailable',
				__( 'Search is temporarily unavailable.', 'solrengine' ),
				array(
					'status'      => 503,
					'retry_after' => 30,
				)
			);
		}

		return new WP_REST_Response( $this->to_payload( $result->value() ) );
	}

	/**
	 * Validate the requested filters.
	 *
	 * @param array<string,mixed> $filters Raw filters.
	 * @return array<string,array<int,scalar>>|WP_Error
	 */
	private function validate_filters( array $filters ) {
		$clean = array();

		foreach ( $filters as $key => $values ) {
			$key = sanitize_key( (string) $key );

			if ( ! $this->filters->has( $key ) ) {
				// Rejected, not ignored. Ignoring an unknown key hides a
				// client bug and makes probing for accepted keys free.
				return new WP_Error(
					'solrengine_invalid_filter',
					sprintf(
						/* translators: %s: the filter key that was rejected. */
						__( '"%s" is not a filter this site accepts.', 'solrengine' ),
						$key
					),
					array(
						'status'    => 400,
						'available' => array_keys( $this->filters->available() ),
					)
				);
			}

			$list = array();

			foreach ( (array) $values as $value ) {
				if ( is_scalar( $value ) ) {
					$list[] = $value;
				}
			}

			// A cap per filter, so a request cannot carry ten thousand values
			// and turn one filter into an enormous disjunction.
			$clean[ $key ] = array_slice( $list, 0, 50 );
		}

		return $clean;
	}

	/**
	 * Restrict requested post types to those actually indexed.
	 *
	 * @param array<int,string> $requested Requested types.
	 * @return string[]
	 */
	private function allowed_post_types( array $requested ): array {
		$indexed = array_map( 'strval', $this->config->get_array( 'index.post_types', array( 'post', 'page' ) ) );

		if ( array() === $requested ) {
			return $indexed;
		}

		return array_values( array_intersect( array_map( 'strval', $requested ), $indexed ) );
	}

	/**
	 * Render results as the public response.
	 *
	 * Only fields flagged returnable appear. A field can be searchable and
	 * filterable without being readable, and echoing everything would publish
	 * indexed meta an administrator never meant to expose.
	 *
	 * @param SearchResults $results Results.
	 * @return array<string,mixed>
	 */
	private function to_payload( SearchResults $results ): array {
		$items = array();

		foreach ( $results->hits() as $hit ) {
			$items[] = $this->hit_to_payload( $hit );
		}

		return array(
			'results'  => $items,
			'total'    => $results->total(),
			'page'     => $results->query()->page(),
			'per_page' => $results->query()->per_page(),
			'pages'    => $results->total_pages(),
			'took_ms'  => $results->took_ms(),
			'strategy' => $results->strategy(),
		);
	}

	/**
	 * Render one hit.
	 *
	 * @param SearchHit $hit The hit.
	 * @return array<string,mixed>
	 */
	private function hit_to_payload( SearchHit $hit ): array {
		$payload = array(
			'id'        => $hit->object_id(),
			'type'      => (string) $hit->field( DocumentFields::OBJECT_TYPE, 'post' ),
			'post_type' => (string) $hit->field( DocumentFields::POST_TYPE, '' ),
			'title'     => (string) $hit->field( DocumentFields::TITLE, '' ),
			'excerpt'   => (string) $hit->field( DocumentFields::EXCERPT, '' ),
			'url'       => (string) $hit->field( DocumentFields::URL, '' ),
			'date'      => (string) $hit->field( DocumentFields::DATE, '' ),
			'author'    => (string) $hit->field( DocumentFields::AUTHOR_NAME, '' ),
			'thumbnail' => (string) $hit->field( DocumentFields::THUMBNAIL, '' ),
			'score'     => round( $hit->score(), 4 ),
		);

		$highlights = $hit->highlights();

		if ( array() !== $highlights ) {
			$payload['highlights'] = $this->safe_highlights( $highlights );
		}

		return $payload;
	}

	/**
	 * Strip everything but the highlight tag from snippets.
	 *
	 * Snippets are assembled by Solr from indexed content. The content was
	 * stripped of markup at index time, but this is the last point before it
	 * reaches a browser, so it is filtered here too rather than trusted.
	 *
	 * @param array<string,string[]> $highlights Raw snippets.
	 * @return array<string,string[]>
	 */
	private function safe_highlights( array $highlights ): array {
		$allowed = array( 'mark' => array() );
		$clean   = array();

		foreach ( $highlights as $field => $snippets ) {
			$safe = array();

			foreach ( $snippets as $snippet ) {
				$safe[] = wp_kses( $snippet, $allowed );
			}

			$clean[ (string) $field ] = $safe;
		}

		return $clean;
	}
}
