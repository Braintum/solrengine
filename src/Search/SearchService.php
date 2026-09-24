<?php
/**
 * Policy around searching.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\Breaker;
use SolrEngine\Contracts\Cache;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Contracts\Viewer;
use SolrEngine\Support\ObjectCache;
use SolrEngine\Support\OptionBreaker;
use SolrEngine\Support\WpViewer;

/**
 * Decides whether and how a search runs, and what happens when it cannot.
 *
 * The backend answers queries. This decides policy: caching, the circuit
 * breaker, and what a failure means. Both the WordPress query integration and
 * the REST controllers go through here, so relevance and failure handling
 * cannot diverge between the two.
 *
 * The circuit breaker is the piece worth explaining. When Solr is down, every
 * visitor's search would otherwise wait for the full timeout before falling
 * back — turning an outage of the search backend into an outage of the site's
 * perceived speed. After a few consecutive failures the breaker opens and
 * searches fall back immediately, without a request, until a cooldown expires.
 *
 * @since 0.4.0
 */
final class SearchService {

	/**
	 * Cache group for search results.
	 *
	 * @var string
	 */
	private const CACHE_GROUP = 'solrengine';

	/**
	 * Backend to query.
	 *
	 * @var SearchBackend|null
	 */
	private $backend;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Seconds to cache a result set, or 0 to not cache.
	 *
	 * @var int
	 */
	private $cache_ttl;

	/**
	 * Who is asking.
	 *
	 * @var Viewer
	 */
	private $viewer;

	/**
	 * Where results are kept.
	 *
	 * @var Cache
	 */
	private $cache;

	/**
	 * Backend health.
	 *
	 * @var Breaker
	 */
	private $breaker;

	/**
	 * Constructor.
	 *
	 * Every collaborator is injected and every default is a WordPress-backed
	 * implementation, so this class contains no WordPress call of its own beyond
	 * `apply_filters()`. That is what lets the instant-search entry point run it
	 * in a process with no object cache and no user stack, and lets the unit
	 * suite run it with no WordPress at all.
	 *
	 * @param SearchBackend|null $backend   Backend to query, or null when none.
	 * @param Logger             $logger    Logger.
	 * @param int                $cache_ttl Seconds to cache results.
	 * @param Viewer|null        $viewer    Who is asking.
	 * @param Cache|null         $cache     Where to keep results.
	 * @param Breaker|null       $breaker   Backend health.
	 */
	public function __construct(
		?SearchBackend $backend,
		Logger $logger,
		int $cache_ttl = 300,
		?Viewer $viewer = null,
		?Cache $cache = null,
		?Breaker $breaker = null
	) {
		$this->backend   = $backend;
		$this->logger    = $logger;
		$this->cache_ttl = max( 0, $cache_ttl );
		$this->viewer    = $viewer ?? new WpViewer();
		$this->cache     = $cache ?? new ObjectCache( self::CACHE_GROUP );
		$this->breaker   = $breaker ?? new OptionBreaker( $logger );
	}

	/**
	 * Whether a search can be attempted at all.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return null !== $this->backend && ! $this->breaker_is_open();
	}

	/**
	 * Run a search.
	 *
	 * @since 0.4.0
	 *
	 * @param SearchQuery $query What to search for.
	 * @return Result Success carrying SearchResults, or a failure. A failure is
	 *                an expected condition and the caller falls back.
	 */
	public function search( SearchQuery $query ): Result {
		if ( null === $this->backend ) {
			return Result::failure( 'no_backend', 'No search backend is configured.' );
		}

		if ( $this->breaker_is_open() ) {
			return Result::failure( 'circuit_open', 'The search backend is temporarily unavailable.' );
		}

		/**
		 * Filters a query before it reaches the backend.
		 *
		 * @since 0.4.0
		 *
		 * @param SearchQuery $query The query about to run.
		 */
		$query = apply_filters( 'solrengine_search_query', $query );

		if ( ! $query instanceof SearchQuery ) {
			return $this->bad_hook( 'bad_filter', 'solrengine_search_query', 'a SearchQuery' );
		}

		$cached = $this->from_cache( $query );

		if ( null !== $cached ) {
			return Result::success( $cached );
		}

		$result = $this->backend->search( $query );

		if ( $result->is_failure() ) {
			$this->record_failure( $result->code() );

			return $result;
		}

		$this->record_success();

		$results = $result->value();

		if ( ! $results instanceof SearchResults ) {
			return $this->bad_hook( 'bad_results', 'search backend', 'SearchResults' );
		}

		$this->to_cache( $query, $results );

		/**
		 * Filters results before they are returned.
		 *
		 * @since 0.4.0
		 *
		 * @param SearchResults $results The results.
		 * @param SearchQuery   $query   The query that produced them.
		 */
		$results = apply_filters( 'solrengine_search_results', $results, $query );

		return $results instanceof SearchResults
			? Result::success( $results )
			: $this->bad_hook( 'bad_filter', 'solrengine_search_results', 'SearchResults' );
	}

	/**
	 * Suggest completions.
	 *
	 * Cached far more aggressively than a search. A visitor typing eight
	 * characters generates several requests, and the shorter prefixes among
	 * them were almost certainly generated by somebody else a moment ago.
	 *
	 * @since 0.5.0
	 *
	 * @param SuggestQuery $query What has been typed.
	 * @return Result Success carrying Suggestions, or a failure.
	 */
	public function suggest( SuggestQuery $query ): Result {
		if ( null === $this->backend ) {
			return Result::failure( 'no_backend', 'No search backend is configured.' );
		}

		if ( ! $query->is_long_enough() ) {
			return Result::success( new Suggestions( array(), $query->prefix() ) );
		}

		if ( $this->breaker_is_open() ) {
			return Result::failure( 'circuit_open', 'The search backend is temporarily unavailable.' );
		}

		/**
		 * Filters an autocomplete query before it reaches the backend.
		 *
		 * The counterpart of `solrengine_search_query`, and it exists for the
		 * same reason: rules that narrow what a visitor may see have to reach
		 * every path that reads the index, not only the results page.
		 *
		 * @since 0.7.0
		 *
		 * @param SuggestQuery $query The query about to run.
		 */
		$query = apply_filters( 'solrengine_suggest_query', $query );

		if ( ! $query instanceof SuggestQuery ) {
			return $this->bad_hook( 'bad_filter', 'solrengine_suggest_query', 'a SuggestQuery' );
		}

		$key    = 'suggest_' . SOLRENGINE_SCHEMA_CONTRACT_VERSION . '_' . $query->cache_key();
		$cached = $this->cache_enabled() ? $this->cache->get( $key ) : null;

		if ( $cached instanceof Suggestions ) {
			return Result::success( $cached );
		}

		$result = $this->backend->suggest( $query );

		if ( $result->is_failure() ) {
			$this->record_failure( $result->code() );

			return $result;
		}

		$this->record_success();

		$suggestions = $result->value();

		if ( ! $suggestions instanceof Suggestions ) {
			return $this->bad_hook( 'bad_results', 'search backend', 'Suggestions' );
		}

		if ( $this->cache_enabled() ) {
			$this->cache->set( $key, $suggestions, max( 60, $this->cache_ttl ) );
		}

		/**
		 * Filters suggestions before they are returned.
		 *
		 * @since 0.5.0
		 *
		 * @param Suggestions  $suggestions The suggestions.
		 * @param SuggestQuery $query       The query that produced them.
		 */
		$suggestions = apply_filters( 'solrengine_suggestions', $suggestions, $query );

		return $suggestions instanceof Suggestions
			? Result::success( $suggestions )
			: $this->bad_hook( 'bad_filter', 'solrengine_suggestions', 'Suggestions' );
	}

	/**
	 * Report a hook that returned something unusable.
	 *
	 * These are developer errors, and they used to fail silently: a filter
	 * returning the wrong type turned search off and left nothing anywhere
	 * saying why. The visitor still gets the WordPress fallback; the site owner
	 * now gets a line in the log naming the hook.
	 *
	 * @param string $code Stable failure code.
	 * @param string $hook Hook that misbehaved.
	 * @param string $want What it should have returned.
	 * @return Result
	 */
	private function bad_hook( string $code, string $hook, string $want ): Result {
		$message = sprintf( 'A %1$s filter returned something that is not %2$s.', $hook, $want );

		$this->logger->error(
			'Search hook returned an unusable value',
			array(
				'hook'     => $hook,
				'expected' => $want,
			)
		);

		return Result::failure( $code, $message );
	}

	/**
	 * Whether results may be cached for this visitor.
	 *
	 * Never for a logged-in user. They may be able to see content a visitor
	 * cannot, and one shared cache entry would serve one of them the other's
	 * view.
	 *
	 * @return bool
	 */
	private function cache_enabled(): bool {
		return 0 !== $this->cache_ttl && ! $this->viewer->is_authenticated();
	}

	/**
	 * Read cached results.
	 *
	 * Only for visitors who are not logged in. A logged-in user may be able to
	 * see content a visitor cannot, and one shared cache entry would serve one
	 * of them the other's view.
	 *
	 * @param SearchQuery $query Query.
	 * @return SearchResults|null
	 */
	private function from_cache( SearchQuery $query ): ?SearchResults {
		if ( ! $this->cache_enabled() ) {
			return null;
		}

		$cached = $this->cache->get( $this->cache_key( $query ) );

		return $cached instanceof SearchResults ? $cached : null;
	}

	/**
	 * Store results in the cache.
	 *
	 * @param SearchQuery   $query   Query.
	 * @param SearchResults $results Results.
	 * @return void
	 */
	private function to_cache( SearchQuery $query, SearchResults $results ): void {
		if ( ! $this->cache_enabled() ) {
			return;
		}

		$this->cache->set( $this->cache_key( $query ), $results, $this->cache_ttl );
	}

	/**
	 * A cache key for a query.
	 *
	 * Includes the schema contract version, so applying a schema change or
	 * reindexing invalidates every entry at once without enumerating keys.
	 *
	 * @param SearchQuery $query Query.
	 * @return string
	 */
	private function cache_key( SearchQuery $query ): string {
		return 'search_' . SOLRENGINE_SCHEMA_CONTRACT_VERSION . '_' . $query->cache_key();
	}

	/**
	 * Whether the breaker is currently open.
	 *
	 * @return bool
	 */
	private function breaker_is_open(): bool {
		return $this->breaker->is_open();
	}

	/**
	 * Note that a search failed.
	 *
	 * @param string $code Failure code.
	 * @return void
	 */
	private function record_failure( string $code ): void {
		$this->breaker->record_failure( $code );
	}

	/**
	 * Note that a search succeeded.
	 *
	 * @return void
	 */
	private function record_success(): void {
		$this->breaker->record_success();
	}
}
