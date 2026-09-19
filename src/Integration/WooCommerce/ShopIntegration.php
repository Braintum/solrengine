<?php
/**
 * Answering shop queries from the index.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\TermPaths;
use SolrEngine\Integration\WordPress\ResultHydration;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\Config;
use WP_Block;
use WP_Query;
use WP_Term;

/**
 * Puts Solr behind the shop, without asking a merchant to change their theme.
 *
 * Three entry points, because WooCommerce has three ways of listing products:
 *
 * 1. `woocommerce_product_query` — the classic shop page, product archives and
 *    product searches, which all run through WC_Query on the main query.
 * 2. `query_loop_block_query_vars` — the Product Collection block, which builds
 *    query variables through the core Query Loop filter and then runs an
 *    ordinary WP_Query with them.
 * 3. `solrengine_search_query` and `solrengine_suggest_query` — every other
 *    path that reads the index, which have to apply the shop's visibility
 *    rules too. A product hidden from search must not surface in the site's
 *    search results, in the REST API, or in an autocomplete dropdown.
 *
 * All three end in `posts_pre_query`, the same substitution the site search
 * uses, so pagination, templates and page builders keep working.
 *
 * The plan named `woocommerce_product_collection_build_query` for the second
 * of those. That filter does not exist: WooCommerce 11 builds the block's
 * query through the core Query Loop filter and offers extension only through a
 * registry of *named collections*, which is a different thing. See the Phase 6
 * addendum to ADR-006.
 *
 * @since 0.7.0
 */
final class ShopIntegration {

	/**
	 * Query variable marking a query as ours, and naming its context.
	 *
	 * @var string
	 */
	private const FLAG = 'solrengine_wc_context';

	/**
	 * Builds the search service.
	 *
	 * A factory rather than the service itself, because constructing one
	 * validates the configured endpoint — a DNS lookup — and these hooks are
	 * attached on every front-end request, including the ones that never list
	 * a product.
	 *
	 * @var callable
	 */
	private $search_factory;

	/**
	 * Builds the filter registry.
	 *
	 * Lazy for a smaller reason: the registry enumerates the site's public
	 * taxonomies and its attributes, which is work worth skipping on a request
	 * that never queries.
	 *
	 * @var callable
	 */
	private $filters_factory;

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
	 * Constructor.
	 *
	 * @param callable $search_factory  Returns the search service.
	 * @param callable $filters_factory Returns the filter registry.
	 * @param Config   $config          Configuration.
	 * @param int      $site_id         Site identifier.
	 */
	public function __construct( callable $search_factory, callable $filters_factory, Config $config, int $site_id ) {
		$this->search_factory  = $search_factory;
		$this->filters_factory = $filters_factory;
		$this->config          = $config;
		$this->site_id         = $site_id;
	}

	/**
	 * The search service.
	 *
	 * @return SearchService
	 */
	private function search(): SearchService {
		return ( $this->search_factory )();
	}

	/**
	 * The filter registry.
	 *
	 * @return FilterRegistry
	 */
	private function filters(): FilterRegistry {
		return ( $this->filters_factory )();
	}

	/**
	 * Attach the hooks.
	 *
	 * @since 0.7.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Applied whether or not the shop itself is answered from Solr,
		// because they are visibility rules rather than a feature: a product
		// the merchant hid from search must stay hidden on every path that
		// reads the index.
		add_filter( 'solrengine_search_query', array( $this, 'narrow_search' ) );
		add_filter( 'solrengine_suggest_query', array( $this, 'narrow_suggest' ) );

		if ( ! $this->config->get_bool( 'search.shop_enabled', false ) || is_admin() ) {
			return;
		}

		add_action( 'woocommerce_product_query', array( $this, 'mark_shop_query' ) );
		add_filter( 'query_loop_block_query_vars', array( $this, 'mark_collection_query' ), 20, 2 );
		add_filter( 'posts_pre_query', array( $this, 'maybe_substitute' ), 10, 2 );
	}

	/**
	 * Apply the shop's visibility rules to an ordinary search.
	 *
	 * @since 0.7.0
	 *
	 * @param SearchQuery $query The query about to run.
	 * @return SearchQuery
	 */
	public function narrow_search( $query ) {
		if ( ! $query instanceof SearchQuery ) {
			return $query;
		}

		$filters = $query->filters();

		if ( isset( $filters[ ShopQuery::CONTEXT_KEY ] ) ) {
			// A shop query, which set its own context already. Overwriting it
			// here would turn a catalogue listing into a search.
			return $query;
		}

		$query = $query->with_filter( ShopQuery::CONTEXT_KEY, array( CatalogContext::SEARCH ) );

		return $this->without_hidden_stock( $query );
	}

	/**
	 * Apply the same rules to autocomplete.
	 *
	 * @since 0.7.0
	 *
	 * @param SuggestQuery $query The query about to run.
	 * @return SuggestQuery
	 */
	public function narrow_suggest( $query ) {
		if ( ! $query instanceof SuggestQuery ) {
			return $query;
		}

		$query = $query->with_filter( ShopQuery::CONTEXT_KEY, array( CatalogContext::SEARCH ) );

		if ( $this->hides_out_of_stock() ) {
			$query = $query->with_filter( ShopQuery::STOCK_EXCLUSION_KEY, array( 'outofstock' ) );
		}

		return $query;
	}

	/**
	 * Mark the classic shop query as ours.
	 *
	 * @since 0.7.0
	 *
	 * @param WP_Query $query The product query.
	 * @return void
	 */
	public function mark_shop_query( $query ): void {
		if ( ! $query instanceof WP_Query || ! $this->is_available() ) {
			return;
		}

		$query->set( self::FLAG, $query->is_search() ? CatalogContext::SEARCH : CatalogContext::CATALOG );
	}

	/**
	 * Mark a Product Collection block's query as ours.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,mixed> $vars  Query variables the block built.
	 * @param WP_Block            $block The block being rendered.
	 * @return array<string,mixed>
	 */
	public function mark_collection_query( $vars, $block ) {
		if ( ! is_array( $vars ) || ! $block instanceof WP_Block || ! $this->is_available() ) {
			return $vars;
		}

		if ( empty( $block->context['query']['isProductCollectionBlock'] ) ) {
			return $vars;
		}

		// A collection is a catalogue listing even when it carries search
		// text: it is rendered inside a page, not as the site's search results.
		$vars[ self::FLAG ] = CatalogContext::CATALOG;

		return $vars;
	}

	/**
	 * Answer a marked query from the index.
	 *
	 * @since 0.7.0
	 *
	 * @param array<int,mixed>|null $posts Posts, or null to let WordPress run.
	 * @param WP_Query              $query The query.
	 * @return array<int,mixed>|null
	 */
	public function maybe_substitute( $posts, $query ) {
		if ( null !== $posts || ! $query instanceof WP_Query ) {
			return $posts;
		}

		$context = (string) $query->get( self::FLAG );

		if ( ! CatalogContext::is_valid( $context ) ) {
			return $posts;
		}

		$search = $this->shop_query()->for_query( $query->query_vars, $this->request(), $context );

		if ( null === $search ) {
			// Something on this query could not be expressed. WordPress answers
			// it instead, which is slower and right.
			return null;
		}

		$result = $this->search()->search( $search );

		if ( $result->is_failure() ) {
			return null;
		}

		$results = $result->value();

		if ( ! $results instanceof SearchResults ) {
			return null;
		}

		ResultHydration::paginate( $query, $results );

		return ResultHydration::posts( $results, $query );
	}

	/**
	 * Build the translator.
	 *
	 * @return ShopQuery
	 */
	private function shop_query(): ShopQuery {
		return new ShopQuery(
			$this->filters(),
			$this->site_id,
			array( $this, 'resolve_term' ),
			$this->hides_out_of_stock(),
			$this->config->get_int( 'search.max_rows', 50 )
		);
	}

	/**
	 * Turn a taxonomy term into the value its filter expects.
	 *
	 * Attribute filters match term identifiers; a hierarchical taxonomy such as
	 * product categories matches a materialised path, so that choosing a parent
	 * category includes everything filed beneath it — which is what a shopper
	 * clicking it means, and what makes the count beside it agree with the page
	 * they land on.
	 *
	 * @since 0.7.0
	 *
	 * @param string     $taxonomy Taxonomy name.
	 * @param string|int $term     Term slug or identifier.
	 * @return string|null
	 */
	public function resolve_term( string $taxonomy, $term ): ?string {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return null;
		}

		$object = is_numeric( $term )
			? get_term( (int) $term, $taxonomy )
			: get_term_by( 'slug', (string) $term, $taxonomy );

		if ( ! $object instanceof WP_Term ) {
			return null;
		}

		if ( 0 === strpos( $taxonomy, 'pa_' ) || ! is_taxonomy_hierarchical( $taxonomy ) ) {
			return (string) $object->term_id;
		}

		$path = TermPaths::path_for( $object, $taxonomy );

		return '' === $path ? null : $path;
	}

	/**
	 * Add the out-of-stock exclusion when the store asks for it.
	 *
	 * @param SearchQuery $query Query being narrowed.
	 * @return SearchQuery
	 */
	private function without_hidden_stock( SearchQuery $query ): SearchQuery {
		if ( ! $this->hides_out_of_stock() ) {
			return $query;
		}

		return $query->with_filter( ShopQuery::STOCK_EXCLUSION_KEY, array( 'outofstock' ) );
	}

	/**
	 * Whether the store hides products that are out of stock.
	 *
	 * @return bool
	 */
	private function hides_out_of_stock(): bool {
		return 'yes' === get_option( 'woocommerce_hide_out_of_stock_items', 'no' );
	}

	/**
	 * Whether a shop query can be answered from the index at all.
	 *
	 * @return bool
	 */
	private function is_available(): bool {
		return ! is_admin() && $this->search()->is_available();
	}

	/**
	 * The request parameters WooCommerce's own filters read.
	 *
	 * @return array<string,mixed>
	 */
	private function request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading public filter parameters from a public URL; nothing changes state.
		$raw = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return is_array( $raw ) ? $raw : array();
	}
}
