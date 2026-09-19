<?php
/**
 * Substitutes Solr results into WordPress's own query.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\IndexedTypes;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FacetSelection;
use SolrEngine\Search\FilterRegistry;
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
	 * Which filters may be applied.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Interprets selected facet values.
	 *
	 * @var FacetSelection
	 */
	private $selection;

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
	 * @param SearchService       $search  Search service.
	 * @param Config              $config  Configuration.
	 * @param int                 $site_id Site identifier.
	 * @param FilterRegistry|null $filters Which filters may be applied.
	 * @param FacetRegistry|null  $facets  Which facets may be counted.
	 */
	public function __construct(
		SearchService $search,
		Config $config,
		int $site_id,
		?FilterRegistry $filters = null,
		?FacetRegistry $facets = null
	) {
		$this->search    = $search;
		$this->config    = $config;
		$this->site_id   = $site_id;
		$this->filters   = $filters ?? FilterRegistry::defaults();
		$this->selection = new FacetSelection( $facets ?? new FacetRegistry() );
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

		ResultHydration::paginate( $query, $results );

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

		return $this->with_selected_filters( $search )->with_pagination( $page, $per_page );
	}

	/**
	 * Apply the filters the URL carries.
	 *
	 * Applied to the main query, not to a separate one, which is what makes a
	 * filtered search still a WordPress search: the theme's template, its
	 * pagination and its result count all describe the filtered set, because
	 * that is the set WP_Query was given.
	 *
	 * Unknown keys are dropped here rather than refused. This is a URL a
	 * visitor can edit, a crawler can mangle and an old bookmark can carry
	 * after a taxonomy is unregistered; a search page that refuses to render
	 * because of a stale query string would be worse than one that ignores it.
	 * The REST endpoint, whose caller is a program, rejects them instead.
	 *
	 * @param SearchQuery $search Query being built.
	 * @return SearchQuery
	 */
	private function with_selected_filters( SearchQuery $search ): SearchQuery {
		foreach ( FacetUrls::selected() as $key => $values ) {
			if ( ! $this->filters->has( $key ) ) {
				continue;
			}

			$values = $this->selection->values_for( $key, $values );

			if ( array() !== $values ) {
				$search = $search->with_filter( $key, $values );
			}
		}

		return $search;
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
		$indexed   = IndexedTypes::all( $this->config );
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
	 * @param SearchResults $results Results.
	 * @param WP_Query      $query   The query.
	 * @return array<int,mixed>
	 */
	private function hydrate( SearchResults $results, WP_Query $query ): array {
		return ResultHydration::posts( $results, $query );
	}
}
