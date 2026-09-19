<?php
/**
 * Substitutes Solr results into WordPress's own query.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\Config;
use WP_Query;

/**
 * Makes WordPress search return Solr results, without touching a template.
 *
 * The mechanism from ADR-005: mark eligible queries in pre_get_posts, then
 * return posts from posts_pre_query. WordPress does no database query of its
 * own, and every theme, block theme, page builder and pagination helper keeps
 * working, because all of them interact with WP_Query rather than with SQL.
 *
 * **Returning null is the entire fallback story.** When the backend is
 * unreachable, slow, or returns something unusable, this filter returns null
 * and WordPress runs the query it would have run anyway. The visitor gets
 * ordinary WordPress results and sees no error. That the fallback costs one
 * return statement is why it will still be correct in five years; a fallback
 * that needed its own subsystem would rot.
 *
 * @since 0.4.0
 */
final class QueryIntegration {

	/**
	 * Query variable marking a query as ours to handle.
	 *
	 * @var string
	 */
	private const FLAG = 'solrengine_handled';

	/**
	 * Search service.
	 *
	 * @var SearchService
	 */
	private $search;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Results of the most recent handled query, for templates to inspect.
	 *
	 * @var SearchResults|null
	 */
	private $last_results = null;

	/**
	 * Constructor.
	 *
	 * @param SearchService $search  Search service.
	 * @param Config        $config  Configuration.
	 * @param int           $site_id Site identifier.
	 */
	public function __construct( SearchService $search, Config $config, int $site_id ) {
		$this->search  = $search;
		$this->config  = $config;
		$this->site_id = $site_id;
	}

	/**
	 * Attach the filters.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'pre_get_posts', array( $this, 'mark_query' ) );
		add_filter( 'posts_pre_query', array( $this, 'maybe_substitute' ), 10, 2 );
	}

	/**
	 * Decide whether a query is ours.
	 *
	 * @since 0.4.0
	 *
	 * @param WP_Query $query The query about to run.
	 * @return void
	 */
	public function mark_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! $this->is_eligible( $query ) ) {
			return;
		}

		$query->set( self::FLAG, true );
	}

	/**
	 * Return Solr results in place of a database query.
	 *
	 * @since 0.4.0
	 *
	 * @param array<int,mixed>|null $posts Posts, or null to let WordPress run.
	 * @param WP_Query              $query The query.
	 * @return array<int,mixed>|null
	 */
	public function maybe_substitute( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof WP_Query || ! $query->get( self::FLAG ) ) {
			return $posts;
		}

		$result = $this->search->search( $this->to_search_query( $query ) );

		if ( $result->is_failure() ) {
			// The fallback. WordPress runs its own query and the visitor is
			// none the wiser.
			return null;
		}

		$results = $result->value();

		if ( ! $results instanceof SearchResults ) {
			return null;
		}

		$this->last_results = $results;

		// WordPress cannot work these out itself, because no query ran.
		$query->found_posts   = $results->total();
		$query->max_num_pages = $results->total_pages();

		return $this->hydrate( $results, $query );
	}

	/**
	 * The results of the most recent handled query.
	 *
	 * Lets a theme say "no exact matches, showing similar results" when the
	 * fuzzy retry produced the hits, rather than presenting an approximation
	 * as though it were what was asked for.
	 *
	 * @since 0.4.0
	 *
	 * @return SearchResults|null
	 */
	public function last_results(): ?SearchResults {
		return $this->last_results;
	}

	/**
	 * Whether SolrEngine should handle a query.
	 *
	 * @param WP_Query $query Query to assess.
	 * @return bool
	 */
	private function is_eligible( WP_Query $query ): bool {
		$eligible = $query->is_main_query()
			&& $query->is_search()
			&& ! is_admin()
			&& ! $query->is_feed()
			&& $this->config->get_bool( 'search.enabled', false )
			&& $this->search->is_available();

		/**
		 * Filters whether SolrEngine handles a particular query.
		 *
		 * The escape hatch for a site where another plugin also filters
		 * posts_pre_query, or where one search form should stay on WordPress.
		 *
		 * @since 0.4.0
		 *
		 * @param bool     $eligible Whether to handle it.
		 * @param WP_Query $query    The query.
		 */
		return (bool) apply_filters( 'solrengine_should_handle_query', $eligible, $query );
	}

	/**
	 * Build a search query from WordPress query variables.
	 *
	 * @param WP_Query $query The query.
	 * @return SearchQuery
	 */
	private function to_search_query( WP_Query $query ): SearchQuery {
		$per_page = (int) $query->get( 'posts_per_page' );

		if ( $per_page <= 0 ) {
			$per_page = (int) get_option( 'posts_per_page', 10 );
		}

		$per_page = min( $per_page, $this->config->get_int( 'search.max_rows', 50 ) );
		$page     = max( 1, (int) $query->get( 'paged' ) );

		// Deep paging is refused rather than served slowly. Past a few hundred
		// pages nobody is reading; something is enumerating the index.
		$ceiling = $this->config->get_int( 'search.deep_paging_limit', 1000 );

		if ( $page * $per_page > $ceiling ) {
			$page = max( 1, (int) floor( $ceiling / $per_page ) );
		}

		$search = new SearchQuery( (string) $query->get( 's' ), $this->site_id );

		$post_types = $this->requested_post_types( $query );

		if ( array() !== $post_types ) {
			$search = $search->with_post_types( $post_types );
		}

		return $search->with_pagination( $page, $per_page );
	}

	/**
	 * Post types a query asked for, restricted to those actually indexed.
	 *
	 * A query asking for a post type SolrEngine does not index would otherwise
	 * silently return nothing, which looks like a broken search rather than an
	 * unindexed type.
	 *
	 * @param WP_Query $query The query.
	 * @return string[]
	 */
	private function requested_post_types( WP_Query $query ): array {
		$indexed   = array_map( 'strval', $this->config->get_array( 'index.post_types', array( 'post', 'page' ) ) );
		$requested = $query->get( 'post_type' );

		if ( '' === $requested || 'any' === $requested ) {
			return $indexed;
		}

		$requested = array_map( 'strval', (array) $requested );

		return array_values( array_intersect( $requested, $indexed ) );
	}

	/**
	 * Turn hits into whatever WordPress asked for.
	 *
	 * Honours the "fields" query variable. A query asking for IDs must not be
	 * handed full objects: callers that asked for IDs treat the return value
	 * as scalars, and handing them objects produces errors far from here.
	 *
	 * @param SearchResults $results Results.
	 * @param WP_Query      $query   The query.
	 * @return array<int,mixed>
	 */
	private function hydrate( SearchResults $results, WP_Query $query ): array {
		$ids = $results->object_ids();

		if ( array() === $ids ) {
			return array();
		}

		$fields = (string) $query->get( 'fields' );

		if ( 'ids' === $fields ) {
			return $ids;
		}

		// One query for every post, rather than one per post while the theme
		// renders. Without this a page of ten results costs ten queries plus
		// their meta and term lookups.
		_prime_post_caches( $ids, true, true );

		$posts = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof \WP_Post ) {
				// Relevance order comes from the index, so the posts are
				// appended in the order the hits arrived rather than the order
				// a database would have returned them.
				$posts[] = $post;
			}
		}

		if ( 'id=>parent' === $fields ) {
			$pairs = array();

			foreach ( $posts as $post ) {
				$pairs[ $post->ID ] = $post->post_parent;
			}

			return $pairs;
		}

		return $posts;
	}
}
