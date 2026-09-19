<?php
/**
 * Reading a WooCommerce product query.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Search\FilterRegistry;

/**
 * Turns a product query into a SearchQuery, or declines to.
 *
 * **Declining is the most important thing this class does.** A shop query can
 * carry filters from WooCommerce, from an extension, or from a theme: a rating
 * filter, an AND-mode attribute filter, a meta query, a hand-picked list of
 * product identifiers. If SolrEngine answered a query whose constraints it only
 * partly understood, a shopper would get a page that looks filtered and is not
 * — products the merchant excluded, at prices the shopper filtered out.
 *
 * So every parameter is either understood and translated, or the whole query is
 * handed back to WordPress. A slow correct page beats a fast wrong one, and the
 * database is still there.
 *
 * Pure apart from one injected resolver, so the "do I understand this?"
 * decision can be argued with in unit tests rather than in a live shop.
 *
 * @since 0.7.0
 */
final class ShopQuery {

	/**
	 * Filter key carrying the catalogue context.
	 *
	 * @var string
	 */
	public const CONTEXT_KEY = 'wc_context';

	/**
	 * Filter key removing stock statuses a store hides.
	 *
	 * @var string
	 */
	public const STOCK_EXCLUSION_KEY = 'wc_stock_excluded';

	/**
	 * Taxonomies whose query-time filters are understood.
	 *
	 * @var string[]
	 */
	private const TAXONOMIES = array( 'product_cat', 'product_tag', 'product_brand' );

	/**
	 * Which filters may be applied.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Turns a taxonomy term into the value its filter expects.
	 *
	 * @var callable
	 */
	private $resolver;

	/**
	 * Whether the store hides products that are out of stock.
	 *
	 * @var bool
	 */
	private $hide_out_of_stock;

	/**
	 * Most results a query may ask for.
	 *
	 * @var int
	 */
	private $max_rows;

	/**
	 * Constructor.
	 *
	 * @param FilterRegistry $filters           Registered filters.
	 * @param int            $site_id           Site identifier.
	 * @param callable       $resolver          Turns (taxonomy, term) into a filter value or null.
	 * @param bool           $hide_out_of_stock Whether the store hides out-of-stock products.
	 * @param int            $max_rows          Results ceiling.
	 */
	public function __construct(
		FilterRegistry $filters,
		int $site_id,
		callable $resolver,
		bool $hide_out_of_stock = false,
		int $max_rows = 50
	) {
		$this->filters           = $filters;
		$this->site_id           = $site_id;
		$this->resolver          = $resolver;
		$this->hide_out_of_stock = $hide_out_of_stock;
		$this->max_rows          = max( 1, $max_rows );
	}

	/**
	 * Build a query, or decline.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,mixed> $vars    WP_Query variables.
	 * @param array<string,mixed> $request Request parameters, as WooCommerce's filters read them.
	 * @param string              $context Catalogue context this query runs in.
	 * @return SearchQuery|null Null means "let WordPress answer this".
	 */
	public function for_query( array $vars, array $request, string $context ): ?SearchQuery {
		if ( ! CatalogContext::is_valid( $context ) ) {
			return null;
		}

		if ( ! $this->is_expressible( $vars, $request ) ) {
			return null;
		}

		$sort = $this->sort_for( $vars );

		if ( null === $sort ) {
			return null;
		}

		$filters = $this->filters_for( $vars, $request );

		if ( null === $filters ) {
			return null;
		}

		$query = new SearchQuery( (string) ( $vars['s'] ?? '' ), $this->site_id );
		$query = $query->with_post_types( array( ProductDocument::POST_TYPE ) );

		foreach ( $filters as $key => $filter ) {
			$query = $query->with_filter(
				(string) $key,
				$filter['values'],
				(string) ( $filter['mode'] ?? 'or' )
			);
		}

		// Applied last and unconditionally. A caller may narrow a product
		// query; nothing may widen it into content the merchant hid.
		$query = $query->with_filter( self::CONTEXT_KEY, array( $context ) );

		if ( $this->hide_out_of_stock ) {
			// Expressed as an exclusion rather than as a list of the statuses
			// that are allowed, so it stays correct if WooCommerce ever adds a
			// fifth status: a product with an unfamiliar status stays visible
			// rather than silently vanishing from the shop.
			$query = $query->with_filter( self::STOCK_EXCLUSION_KEY, array( 'outofstock' ) );
		}

		return $query->with_sort( $sort )->with_pagination( $this->page_for( $vars ), $this->per_page_for( $vars ) );
	}

	/**
	 * Whether every constraint on this query can be expressed.
	 *
	 * @param array<string,mixed> $vars    Query variables.
	 * @param array<string,mixed> $request Request parameters.
	 * @return bool
	 */
	private function is_expressible( array $vars, array $request ): bool {
		if ( array() !== array_filter( (array) ( $vars['post__in'] ?? array() ) ) ) {
			// Something has pinned the loop to a list of products — a "related
			// products" block, or an extension. Whatever it is, it knows
			// something this index does not.
			return false;
		}

		if ( array() !== array_filter( (array) ( $vars['post__not_in'] ?? array() ) ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Reading a query to decide whether to answer it, not building one.
		if ( $this->has_clauses( (array) ( $vars['meta_query'] ?? array() ) ) ) {
			return false;
		}

		if ( ! empty( $request['rating_filter'] ) ) {
			// WooCommerce's rating filter. Expressible in principle, not
			// registered as a filter here, and answering the query without it
			// would silently ignore what the shopper asked for.
			return false;
		}

		return true;
	}

	/**
	 * Whether a meta or tax query holds real clauses.
	 *
	 * @param array<mixed> $query Query array.
	 * @return bool
	 */
	private function has_clauses( array $query ): bool {
		foreach ( $query as $key => $clause ) {
			if ( 'relation' === $key ) {
				continue;
			}

			if ( is_array( $clause ) && array() !== $clause ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Translate every filter this query carries.
	 *
	 * @param array<string,mixed> $vars    Query variables.
	 * @param array<string,mixed> $request Request parameters.
	 * @return array<string,array{values:array<int,scalar>,mode?:string}>|null Null when one could not be expressed.
	 */
	private function filters_for( array $vars, array $request ): ?array {
		$filters = array();

		$clauses = $this->tax_clauses( $vars );

		foreach ( self::layered_nav( $request ) as $taxonomy => $terms ) {
			$clauses[ $taxonomy ] = array_merge( $clauses[ $taxonomy ] ?? array(), $terms );
		}

		foreach ( $clauses as $taxonomy => $terms ) {
			if ( ! $this->filters->has( $taxonomy ) ) {
				return null;
			}

			$values = array();

			foreach ( array_unique( $terms ) as $term ) {
				$value = ( $this->resolver )( $taxonomy, $term );

				if ( null === $value || '' === $value ) {
					// A term that does not resolve would silently widen the
					// query, so the whole thing goes back to WordPress.
					return null;
				}

				$values[] = $value;
			}

			if ( array() !== $values ) {
				$filters[ $taxonomy ] = array(
					'values' => $values,
					'mode'   => $this->mode_for( $taxonomy, $request ),
				);
			}
		}

		$price = $this->price_for( $request );

		if ( '' !== $price ) {
			if ( ! $this->filters->has( 'price_range' ) ) {
				return null;
			}

			$filters['price_range'] = array( 'values' => array( $price ) );
		}

		$stock = $this->stock_for( $vars, $request );

		if ( array() !== $stock ) {
			$filters['stock_status'] = array( 'values' => $stock );
		}

		if ( ! empty( $request['on_sale'] ) || ! empty( $vars['on_sale'] ) ) {
			$filters['on_sale'] = array( 'values' => array( true ) );
		}

		return $filters;
	}

	/**
	 * How one attribute's chosen values combine.
	 *
	 * WooCommerce's default is "and", not "or" — a shopper choosing two values
	 * of one attribute there means a product having both. Answering that with a
	 * disjunction would hand them products they had just excluded, so the
	 * default is matched exactly rather than assumed.
	 *
	 * @param string              $taxonomy Attribute taxonomy.
	 * @param array<string,mixed> $request  Request parameters.
	 * @return string
	 */
	private function mode_for( string $taxonomy, array $request ): string {
		if ( 0 !== strpos( $taxonomy, 'pa_' ) ) {
			// An archive or a category filter. Two categories mean "in
			// either": that is what a category page's own links produce.
			return 'or';
		}

		$key = 'query_type_' . substr( $taxonomy, 3 );

		$chosen = isset( $request[ $key ] ) && is_scalar( $request[ $key ] )
			? strtolower( (string) $request[ $key ] )
			: '';

		if ( 'or' === $chosen ) {
			return 'or';
		}

		// WooCommerce's own filter, called with WooCommerce's own signature.
		// A store that changed its layered-navigation default did so once, and
		// a search plugin that quietly disagreed with that setting would return
		// a different set of products from the one the shop's own pages do.
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Reading WooCommerce's setting, not declaring a hook.
		$default = (string) apply_filters( 'woocommerce_layered_nav_default_query_type', 'and' );

		return 'or' === strtolower( $default ) ? 'or' : 'and';
	}

	/**
	 * Taxonomy terms this query constrains by, keyed by taxonomy.
	 *
	 * Reads both the archive context — a category page sets a query variable —
	 * and WooCommerce's layered navigation, which arrives as `filter_pa_color`
	 * in the request.
	 *
	 * @param array<string,mixed> $vars Query variables.
	 * @return array<string,array<int,string>>
	 */
	private function tax_clauses( array $vars ): array {
		$out = array();

		foreach ( self::TAXONOMIES as $taxonomy ) {
			$value = $vars[ $taxonomy ] ?? '';

			if ( is_string( $value ) && '' !== $value ) {
				$out[ $taxonomy ] = array_filter( array_map( 'trim', explode( ',', $value ) ) );
			}
		}

		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- Reading a query to decide whether to answer it, not building one.
		foreach ( (array) ( $vars['tax_query'] ?? array() ) as $key => $clause ) {
			if ( 'relation' === $key || ! is_array( $clause ) ) {
				continue;
			}

			$taxonomy = (string) ( $clause['taxonomy'] ?? '' );

			if ( 'product_visibility' === $taxonomy ) {
				// WooCommerce's own visibility exclusions. SolrEngine applies
				// the same rules from indexed fields, so these are recognised
				// and dropped rather than translated.
				continue;
			}

			$terms = array();

			foreach ( (array) ( $clause['terms'] ?? array() ) as $term ) {
				if ( is_scalar( $term ) ) {
					$terms[] = (string) $term;
				}
			}

			if ( array() === $terms ) {
				continue;
			}

			$out[ $taxonomy ] = array_merge( $out[ $taxonomy ] ?? array(), $terms );
		}

		return $out;
	}

	/**
	 * The price range this query asks for, as "min:max".
	 *
	 * @param array<string,mixed> $request Request parameters.
	 * @return string
	 */
	private function price_for( array $request ): string {
		$min = $request['min_price'] ?? '';
		$max = $request['max_price'] ?? '';

		$min = is_scalar( $min ) && is_numeric( $min ) ? (string) $min : '';
		$max = is_scalar( $max ) && is_numeric( $max ) ? (string) $max : '';

		return '' === $min && '' === $max ? '' : $min . ':' . $max;
	}

	/**
	 * Stock statuses this query asks for.
	 *
	 * @param array<string,mixed> $vars    Query variables.
	 * @param array<string,mixed> $request Request parameters.
	 * @return array<int,string>
	 */
	private function stock_for( array $vars, array $request ): array {
		$raw = $vars['stock_status'] ?? ( $request['stock_status'] ?? '' );
		$out = array();

		foreach ( is_array( $raw ) ? $raw : explode( ',', (string) $raw ) as $status ) {
			$status = trim( (string) $status );

			if ( in_array( $status, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$out[] = $status;
			}
		}

		return $out;
	}

	/**
	 * The sort key for a query's ordering, or null when it has none we can use.
	 *
	 * @param array<string,mixed> $vars Query variables.
	 * @return string|null
	 */
	private function sort_for( array $vars ): ?string {
		$orderby    = strtolower( trim( (string) ( $vars['orderby'] ?? '' ) ) );
		$descending = 'DESC' === strtoupper( (string) ( $vars['order'] ?? 'DESC' ) );

		if ( '' === $orderby ) {
			return '' !== (string) ( $vars['s'] ?? '' ) ? 'relevance' : 'menu_order';
		}

		switch ( $orderby ) {
			case 'menu_order':
			case 'menu_order title':
				return 'menu_order';

			case 'title':
				return $descending ? 'title_desc' : 'title_asc';

			case 'date':
			case 'date id':
				return $descending ? 'date_desc' : 'date_asc';

			case 'modified':
			case 'modified id':
				return 'modified';

			case 'relevance':
				return 'relevance';

			case 'price':
				return $descending ? 'price_desc' : 'price_asc';

			case 'popularity':
				return 'popularity';

			case 'rating':
				return 'rating';

			default:
				// "rand" and anything an extension invented. Ordering a page of
				// results differently from what was asked is the kind of bug
				// nobody reports and everybody notices.
				return null;
		}
	}

	/**
	 * Page number.
	 *
	 * @param array<string,mixed> $vars Query variables.
	 * @return int
	 */
	private function page_for( array $vars ): int {
		$paged = (int) ( $vars['paged'] ?? 0 );

		return max( 1, $paged );
	}

	/**
	 * Results per page.
	 *
	 * @param array<string,mixed> $vars Query variables.
	 * @return int
	 */
	private function per_page_for( array $vars ): int {
		$per_page = (int) ( $vars['posts_per_page'] ?? 0 );

		if ( $per_page <= 0 ) {
			$per_page = 12;
		}

		return min( $per_page, $this->max_rows );
	}

	/**
	 * Read the layered-navigation attributes a request carries.
	 *
	 * WooCommerce's widgets and blocks send `filter_pa_color=red,blue`. The
	 * values are slugs, which is why a resolver is needed at all.
	 *
	 * @since 0.7.0
	 *
	 * @param array<string,mixed> $request Request parameters.
	 * @return array<string,array<int,string>>
	 */
	public static function layered_nav( array $request ): array {
		$out = array();

		foreach ( $request as $key => $value ) {
			$key = (string) $key;

			if ( 0 !== strpos( $key, 'filter_' ) || ! is_scalar( $value ) ) {
				continue;
			}

			$taxonomy = 'pa_' . substr( $key, strlen( 'filter_' ) );
			$terms    = array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );

			if ( array() !== $terms ) {
				$out[ $taxonomy ] = $terms;
			}
		}

		return $out;
	}
}
