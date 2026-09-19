<?php
/**
 * A search request, expressed without engine syntax.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What to search for, as structured values.
 *
 * Carries no engine vocabulary at all. There is no place to put a Solr query
 * string, a filter query or a local parameter, and that absence is the point:
 * it is what makes the same object usable by a future hosted backend, and it is
 * what stops user input ever reaching a query as syntax.
 *
 * The 0.1.0 prototype's equivalent was `search( string $q, array $params )`,
 * which passed the visitor's raw text straight through as the Solr `q`
 * parameter. A visitor could submit local-parameter syntax and rewrite the
 * query. This type has no way to express that.
 *
 * Immutable. Built through the `with_*` methods.
 *
 * @since 0.4.0 Unstable until 1.0.
 */
final class SearchQuery {

	/**
	 * The visitor's search text. Treated as text, never as syntax.
	 *
	 * @var string
	 */
	private $terms;

	/**
	 * Site to restrict to.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Post types to restrict to, empty for all indexed types.
	 *
	 * @var string[]
	 */
	private $post_types = array();

	/**
	 * Filter values keyed by registered filter key.
	 *
	 * Keys are looked up in a server-side registry. A key that is not
	 * registered is rejected rather than ignored.
	 *
	 * @var array<string,array<int,scalar>>
	 */
	private $filters = array();

	/**
	 * Sort key, from a server-declared enum.
	 *
	 * @var string
	 */
	private $sort = 'relevance';

	/**
	 * Page number, one-based.
	 *
	 * @var int
	 */
	private $page = 1;

	/**
	 * Results per page.
	 *
	 * @var int
	 */
	private $per_page = 10;

	/**
	 * Language to restrict to, empty for any.
	 *
	 * @var string
	 */
	private $language = '';

	/**
	 * Whether to ask the backend for highlighted snippets.
	 *
	 * @var bool
	 */
	private $highlight = false;

	/**
	 * Constructor.
	 *
	 * @param string $terms   Search text.
	 * @param int    $site_id Site to restrict to.
	 */
	public function __construct( string $terms, int $site_id ) {
		$this->terms   = $terms;
		$this->site_id = $site_id;
	}

	/**
	 * The search text.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function terms(): string {
		return $this->terms;
	}

	/**
	 * Whether there is any search text.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function has_terms(): bool {
		return '' !== trim( $this->terms );
	}

	/**
	 * Site to restrict to.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function site_id(): int {
		return $this->site_id;
	}

	/**
	 * Post types to restrict to.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		return $this->post_types;
	}

	/**
	 * Filter values keyed by registered filter key.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,array<int,scalar>>
	 */
	public function filters(): array {
		return $this->filters;
	}

	/**
	 * Sort key.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function sort(): string {
		return $this->sort;
	}

	/**
	 * Page number, one-based.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function page(): int {
		return $this->page;
	}

	/**
	 * Results per page.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function per_page(): int {
		return $this->per_page;
	}

	/**
	 * Zero-based offset of the first result.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function offset(): int {
		return ( $this->page - 1 ) * $this->per_page;
	}

	/**
	 * Language to restrict to.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function language(): string {
		return $this->language;
	}

	/**
	 * Whether highlighted snippets were requested.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function wants_highlight(): bool {
		return $this->highlight;
	}

	/**
	 * A copy restricted to given post types.
	 *
	 * @since 0.4.0
	 *
	 * @param string[] $post_types Post types.
	 * @return self
	 */
	public function with_post_types( array $post_types ): self {
		$clone             = clone $this;
		$clone->post_types = array_values( array_unique( array_map( 'strval', $post_types ) ) );

		return $clone;
	}

	/**
	 * A copy with a filter applied.
	 *
	 * @since 0.4.0
	 *
	 * @param string            $key    Registered filter key.
	 * @param array<int,scalar> $values Values to match.
	 * @return self
	 */
	public function with_filter( string $key, array $values ): self {
		$clone = clone $this;

		$clone->filters[ $key ] = array_values( $values );

		return $clone;
	}

	/**
	 * A copy sorted differently.
	 *
	 * @since 0.4.0
	 *
	 * @param string $sort Sort key.
	 * @return self
	 */
	public function with_sort( string $sort ): self {
		$clone       = clone $this;
		$clone->sort = $sort;

		return $clone;
	}

	/**
	 * A copy paginated differently.
	 *
	 * @since 0.4.0
	 *
	 * @param int $page     Page number, one-based.
	 * @param int $per_page Results per page.
	 * @return self
	 */
	public function with_pagination( int $page, int $per_page ): self {
		$clone           = clone $this;
		$clone->page     = max( 1, $page );
		$clone->per_page = max( 1, $per_page );

		return $clone;
	}

	/**
	 * A copy restricted to a language.
	 *
	 * @since 0.4.0
	 *
	 * @param string $language BCP-47 tag.
	 * @return self
	 */
	public function with_language( string $language ): self {
		$clone           = clone $this;
		$clone->language = $language;

		return $clone;
	}

	/**
	 * A copy that asks for highlighted snippets.
	 *
	 * @since 0.4.0
	 *
	 * @param bool $highlight Whether to highlight.
	 * @return self
	 */
	public function with_highlight( bool $highlight = true ): self {
		$clone            = clone $this;
		$clone->highlight = $highlight;

		return $clone;
	}

	/**
	 * A stable key identifying this query, for caching.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function cache_key(): string {
		$filters = $this->filters;

		ksort( $filters );

		foreach ( $filters as $key => $values ) {
			sort( $filters[ $key ] );
		}

		return md5(
			(string) wp_json_encode(
				array(
					$this->terms,
					$this->site_id,
					$this->post_types,
					$filters,
					$this->sort,
					$this->page,
					$this->per_page,
					$this->language,
					$this->highlight,
				)
			)
		);
	}
}
