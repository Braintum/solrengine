<?php
/**
 * Public search endpoint.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Rest;

use SolrEngine\Contracts\FacetResult;
use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\FacetLabeller;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FacetSelection;
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
	 * Registered facets.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Interprets selected facet values.
	 *
	 * @var FacetSelection
	 */
	private $selection;

	/**
	 * Labels facet buckets.
	 *
	 * @var FacetLabeller
	 */
	private $labeller;

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
	 * @param FacetRegistry  $facets  Registered facets.
	 * @param Config         $config  Configuration.
	 * @param RateLimiter    $limiter Rate limiter.
	 * @param int            $site_id Site identifier.
	 */
	public function __construct(
		SearchService $search,
		FilterRegistry $filters,
		FacetRegistry $facets,
		Config $config,
		RateLimiter $limiter,
		int $site_id
	) {
		$this->search    = $search;
		$this->filters   = $filters;
		$this->facets    = $facets;
		$this->selection = new FacetSelection( $facets );
		$this->labeller  = new FacetLabeller( $facets );
		$this->config    = $config;
		$this->limiter   = $limiter;
		$this->site_id   = $site_id;
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

		register_rest_route(
			self::NAMESPACE,
			'/facets',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_facets' ),
				// Public for the same reason as search, and narrower: it
				// returns counts of content a visitor can already read, and no
				// documents at all.
				'permission_callback' => '__return_true',
				'args'                => $this->facet_args(),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/suggest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'handle_suggest' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'q'         => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $value ): bool {
							return is_string( $value ) && strlen( $value ) <= 128;
						},
					),
					'limit'     => array(
						'type'              => 'integer',
						'default'           => 8,
						'minimum'           => 1,
						// Deliberately lower than the search endpoint. A list
						// longer than a screenful is not a list anybody reads,
						// and every row costs latency on a request that fires
						// while someone is still typing.
						'maximum'           => 20,
						'sanitize_callback' => 'absint',
					),
					'post_type' => array(
						'type'              => 'array',
						'default'           => array(),
						'items'             => array( 'type' => 'string' ),
						'sanitize_callback' => static function ( $value ): array {
							return array_map( 'sanitize_key', (array) $value );
						},
					),
				),
			)
		);
	}

	/**
	 * Handle an autocomplete request.
	 *
	 * @since 0.5.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_suggest( WP_REST_Request $request ) {
		$allowed = $this->limiter->check( 'suggest' );

		if ( $allowed->is_failure() ) {
			return new WP_Error(
				'solrengine_rate_limited',
				__( 'Too many requests. Please wait a moment.', 'solrengine' ),
				array(
					'status'      => 429,
					'retry_after' => (int) ( $allowed->context()['retry_after'] ?? 10 ),
				)
			);
		}

		$query = new SuggestQuery(
			(string) $request->get_param( 'q' ),
			$this->site_id,
			(int) $request->get_param( 'limit' )
		);

		$post_types = $this->allowed_post_types( (array) $request->get_param( 'post_type' ) );

		if ( array() !== $post_types ) {
			$query = $query->with_post_types( $post_types );
		}

		$result = $this->search->suggest( $query );

		if ( $result->is_failure() ) {
			// An empty list rather than an error. Autocomplete is an
			// enhancement: a visitor whose suggestions stop appearing can
			// still press enter and get a results page, and an error banner
			// under a search box on every keystroke would be worse than
			// showing nothing.
			return $this->suggest_response( new Suggestions( array(), $query->prefix() ) );
		}

		return $this->suggest_response( $result->value() );
	}

	/**
	 * Render suggestions.
	 *
	 * @param Suggestions $suggestions The suggestions.
	 * @return WP_REST_Response
	 */
	private function suggest_response( Suggestions $suggestions ): WP_REST_Response {
		$items = array();

		foreach ( $suggestions->items() as $item ) {
			$items[] = array(
				'text'      => $item->text(),
				'url'       => $item->url(),
				'id'        => null === $item->ref() ? 0 : $item->ref()->object_id(),
				'post_type' => $item->post_type(),
			);
		}

		$response = new WP_REST_Response(
			array(
				'suggestions' => $items,
				'prefix'      => $suggestions->prefix(),
				'took_ms'     => $suggestions->took_ms(),
			)
		);

		// A short browser cache. Several visitors type the same prefixes, and
		// the same visitor backspaces into one they just sent. Private, so a
		// shared proxy never serves one visitor's response to another.
		if ( ! is_user_logged_in() ) {
			$response->header( 'Cache-Control', 'private, max-age=30' );
		}

		return $response;
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
		) + $this->facet_params();
	}

	/**
	 * The arguments the facets endpoint accepts.
	 *
	 * The search schema without pagination, sorting or highlighting: none of
	 * them changes a count, and accepting a parameter that cannot affect the
	 * answer invites a client to build on behaviour that is not there.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function facet_args(): array {
		return array(
			'q'         => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => static function ( $value ): bool {
					return is_string( $value ) && strlen( $value ) <= 512;
				},
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
		) + $this->facet_params();
	}

	/**
	 * The two parameters that name facets.
	 *
	 * Keys only, never definitions. A caller says "category"; what a category
	 * facet is — which field, how many buckets, how they sort — is decided by
	 * the registry on this side of the wire, and that is the single constraint
	 * that keeps this endpoint from being a Solr proxy (ADR-008).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function facet_params(): array {
		return array(
			'facets'       => array(
				'type'              => 'array',
				'default'           => array(),
				'items'             => array( 'type' => 'string' ),
				'sanitize_callback' => static function ( $value ): array {
					return array_map( 'sanitize_key', (array) $value );
				},
			),
			'facet_parent' => array(
				'type'    => 'object',
				'default' => array(),
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

		$facets = $this->validate_facets( (array) $request->get_param( 'facets' ) );

		if ( $facets instanceof WP_Error ) {
			return $facets;
		}

		$query = $this->selection->apply( $query, $facets, $this->parents( $request ) );

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
			$values = $this->selection->values_for( $key, array_slice( $list, 0, 50 ) );

			if ( array() === $values && array() !== $list ) {
				// The key is real and every value it carried is not. Answering
				// with unfiltered results would be the worst outcome: the
				// caller would believe a filter was applied, and the counts
				// beside the results would disagree with the results.
				return new WP_Error(
					'solrengine_invalid_filter_value',
					sprintf(
						/* translators: %s: the filter key whose values were rejected. */
						__( 'The values sent for "%s" do not name anything this site can filter by.', 'solrengine' ),
						$key
					),
					array( 'status' => 400 )
				);
			}

			$clean[ $key ] = $values;
		}

		return $clean;
	}

	/**
	 * Validate the requested facet keys.
	 *
	 * Rejected rather than ignored, for the same reason unknown filters are: a
	 * client that asked for a facet and got results without it has no way to
	 * tell whether the facet is empty or misspelled.
	 *
	 * @param array<int,mixed> $requested Raw facet keys.
	 * @return string[]|WP_Error
	 */
	private function validate_facets( array $requested ) {
		$keys = array();

		foreach ( $requested as $key ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key ) {
				continue;
			}

			if ( ! $this->facets->has( $key ) ) {
				return new WP_Error(
					'solrengine_invalid_facet',
					sprintf(
						/* translators: %s: the facet key that was rejected. */
						__( '"%s" is not a facet this site offers.', 'solrengine' ),
						$key
					),
					array(
						'status'    => 400,
						'available' => array_keys( $this->facets->available() ),
					)
				);
			}

			$keys[] = $key;
		}

		// Capped. Each facet is work Solr does over the whole result set, and a
		// request naming twenty of them is a cheap way to make one query
		// expensive.
		return array_slice( array_values( array_unique( $keys ) ), 0, 10 );
	}

	/**
	 * The drill-down position requested for each hierarchical facet.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return array<string,string>
	 */
	private function parents( WP_REST_Request $request ): array {
		$out = array();

		foreach ( (array) $request->get_param( 'facet_parent' ) as $key => $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$out[ sanitize_key( (string) $key ) ] = (string) $value;
		}

		return $out;
	}

	/**
	 * Handle a facet-counts request.
	 *
	 * Runs the same query as a search and asks for no documents. A filter panel
	 * repainting as a visitor works through it is the request this answers, and
	 * fetching ten documents each time in order to throw them away would be
	 * most of the cost of the endpoint.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_facets( WP_REST_Request $request ) {
		$allowed = $this->limiter->check( 'facets' );

		if ( $allowed->is_failure() ) {
			return new WP_Error(
				'solrengine_rate_limited',
				__( 'Too many requests. Please wait a moment.', 'solrengine' ),
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

		$facets = $this->validate_facets( (array) $request->get_param( 'facets' ) );

		if ( $facets instanceof WP_Error ) {
			return $facets;
		}

		if ( array() === $facets ) {
			// Nothing was named, so every registered facet is counted. A client
			// asking this endpoint for nothing in particular wants the panel.
			$facets = $this->facets->keys();
		}

		$query = $this->selection
			->apply( $query, $facets, $this->parents( $request ) )
			->without_documents();

		$result = $this->search->search( $query );

		if ( $result->is_failure() ) {
			return new WP_Error(
				'solrengine_backend_unavailable',
				__( 'Search is temporarily unavailable.', 'solrengine' ),
				array(
					'status'      => 503,
					'retry_after' => 30,
				)
			);
		}

		$results = $result->value();

		if ( ! $results instanceof SearchResults ) {
			return new WP_Error(
				'solrengine_backend_unavailable',
				__( 'Search is temporarily unavailable.', 'solrengine' ),
				array( 'status' => 503 )
			);
		}

		return new WP_REST_Response(
			array(
				'facets'  => $this->facets_to_payload( $results ),
				'total'   => $results->total(),
				'took_ms' => $results->took_ms(),
			)
		);
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

		$payload = array(
			'results'  => $items,
			'total'    => $results->total(),
			'page'     => $results->query()->page(),
			'per_page' => $results->query()->per_page(),
			'pages'    => $results->total_pages(),
			'took_ms'  => $results->took_ms(),
			'strategy' => $results->strategy(),
		);

		if ( $results->query()->wants_facets() ) {
			// Present whenever facets were asked for, even when every one of
			// them came back empty. A client that has to distinguish "no
			// counts" from "the key I sent was wrong" has already been told
			// about the second case by a 400.
			$payload['facets'] = $this->facets_to_payload( $results );
		}

		return $payload;
	}

	/**
	 * Render facet counts.
	 *
	 * Labels are resolved here rather than carried through the cache, so a
	 * renamed category reads correctly on the next request instead of when the
	 * cache happens to expire.
	 *
	 * @param SearchResults $results Results.
	 * @return array<string,array<string,mixed>>
	 */
	private function facets_to_payload( SearchResults $results ): array {
		$out = array();

		foreach ( $this->labeller->label( $results->facets() ) as $key => $facet ) {
			$out[ (string) $key ] = $this->facet_to_payload( $facet );
		}

		return $out;
	}

	/**
	 * Render one facet.
	 *
	 * @param FacetResult $facet The facet.
	 * @return array<string,mixed>
	 */
	private function facet_to_payload( FacetResult $facet ): array {
		$buckets = array();

		foreach ( $facet->buckets() as $bucket ) {
			$payload = array(
				'key'      => $bucket->value(),
				'label'    => $bucket->label(),
				'count'    => $bucket->count(),
				'selected' => $bucket->is_selected(),
			);

			if ( $bucket->has_children() ) {
				// Only where it is true, so a client can treat its absence as
				// "this is a leaf" without knowing which facet types nest.
				$payload['has_children'] = true;
			}

			$buckets[] = $payload;
		}

		return array(
			'label'    => $facet->label(),
			'type'     => $facet->type(),
			'parent'   => $facet->parent(),
			'selected' => $facet->selected(),
			'buckets'  => $buckets,
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
