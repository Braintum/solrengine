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
	 * How each filter's values combine, keyed by filter key.
	 *
	 * "or" by default: ticking two categories means "in either", which is what
	 * ticking two boxes means to the person doing it. "and" exists because
	 * WooCommerce's layered navigation defaults to it — a shopper choosing two
	 * attribute values there means "has both" — and answering that query with
	 * a disjunction would return products they excluded.
	 *
	 * @var array<string,string>
	 */
	private $filter_modes = array();

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
	 * Whether the last word is matched as a prefix.
	 *
	 * @var bool
	 */
	private $completion = false;

	/**
	 * Facets to count, keyed by registered facet key.
	 *
	 * The value is the node a hierarchy facet counts the children of, and an
	 * empty string everywhere else. Keys are looked up in a server-side
	 * registry; a key that is not registered is rejected rather than ignored.
	 *
	 * @var array<string,string>
	 */
	private $facets = array();

	/**
	 * Whether the caller wants the matching documents as well as the counts.
	 *
	 * @var bool
	 */
	private $documents = true;

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
	 * How one filter's values combine.
	 *
	 * @since 0.7.0
	 *
	 * @param string $key Filter key.
	 * @return string "or" or "and".
	 */
	public function filter_mode( string $key ): string {
		return $this->filter_modes[ $key ] ?? 'or';
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
	 * Whether the last word is matched as a prefix, as text still being typed.
	 *
	 * @since 0.11.0
	 *
	 * @return bool
	 */
	public function completes_last_word(): bool {
		return $this->completion;
	}

	/**
	 * Facets to count, keyed by facet key.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string,string>
	 */
	public function facets(): array {
		return $this->facets;
	}

	/**
	 * Whether any facet was asked for.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function wants_facets(): bool {
		return array() !== $this->facets;
	}

	/**
	 * The node a hierarchy facet should count the children of.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return string Materialised path, or an empty string for the top level.
	 */
	public function facet_parent( string $key ): string {
		return $this->facets[ $key ] ?? '';
	}

	/**
	 * Whether the matching documents are wanted, or only the counts.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function wants_documents(): bool {
		return $this->documents;
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
	 * @param string            $mode   "or" for any value, "and" for all of them.
	 * @return self
	 */
	public function with_filter( string $key, array $values, string $mode = 'or' ): self {
		$clone = clone $this;

		$clone->filters[ $key ] = array_values( $values );

		if ( 'and' === $mode ) {
			$clone->filter_modes[ $key ] = 'and';
		} else {
			unset( $clone->filter_modes[ $key ] );
		}

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
	 * A copy that also counts a facet.
	 *
	 * The parent is a materialised taxonomy path and means something only to a
	 * hierarchy facet: it says which node's children to count. It is validated
	 * here as well as escaped later, because a path is the one facet input that
	 * originates outside the registry.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key    Registered facet key.
	 * @param string $parent_path Node to count the children of.
	 * @return self
	 */
	public function with_facet( string $key, string $parent_path = '' ): self {
		$clone = clone $this;

		$clone->facets[ $key ] = 1 === preg_match( '#^[0-9]+(/[0-9]+)*$#', $parent_path ) ? $parent_path : '';

		return $clone;
	}

	/**
	 * A copy counting several facets at their top level.
	 *
	 * @since 0.6.0
	 *
	 * @param string[] $keys Registered facet keys.
	 * @return self
	 */
	public function with_facets( array $keys ): self {
		$clone = $this;

		foreach ( $keys as $key ) {
			$clone = $clone->with_facet( (string) $key );
		}

		return $clone;
	}

	/**
	 * A copy that asks for counts without the documents.
	 *
	 * What the facets endpoint runs. Fetching ten documents in order to throw
	 * them away costs Solr the stored-field retrieval and costs the response
	 * its size, on the request most likely to be repeated as a visitor works
	 * through a filter panel.
	 *
	 * @since 0.6.0
	 *
	 * @return self
	 */
	public function without_documents(): self {
		$clone            = clone $this;
		$clone->documents = false;

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
	 * A copy that matches its last word as a prefix.
	 *
	 * For text that is still being typed: "hoo" finds "hoodie". Only the last
	 * word, and only a plain word of two or more letters or digits; see
	 * {@see \SolrEngine\Search\PrefixTerms}. A backend that already matches
	 * partial words may ignore it.
	 *
	 * @since 0.11.0
	 *
	 * @param bool $completion Whether to complete the last word.
	 * @return self
	 */
	public function with_completion( bool $completion = true ): self {
		$clone             = clone $this;
		$clone->completion = $completion;

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
		// Derived from the serialised form rather than from a second hand-written
		// list of properties. The two lists drifting apart is not a cosmetic bug:
		// a property missing from the key means two queries that differ only in
		// that property share a cache entry, and one visitor is served the other's
		// results. Building the key from to_array() makes that impossible by
		// construction rather than by remembering.
		$state = $this->to_array();

		ksort( $state['filters'] );
		ksort( $state['facets'] );
		ksort( $state['filter_modes'] );

		foreach ( $state['filters'] as $key => $values ) {
			sort( $state['filters'][ $key ] );
		}

		return md5( (string) wp_json_encode( $state ) );
	}

	/**
	 * The query as plain data.
	 *
	 * The third of the three properties §21.4 requires for a second backend to
	 * remain possible: the domain model has to be serialisable, so a backend that
	 * is not on this server can be sent the query itself rather than a dialect of
	 * somebody's query language. A remote backend receives this; it never receives
	 * Solr syntax, because Solr syntax is produced one layer further down and
	 * nothing above the adapter has ever seen it.
	 *
	 * Ordered deliberately, and every key always present. A serialisation whose
	 * shape depends on its content is one that has to be pattern-matched on the
	 * way back in.
	 *
	 * @since 0.10.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'terms'        => $this->terms,
			'site_id'      => $this->site_id,
			'post_types'   => $this->post_types,
			'filters'      => $this->filters,
			'filter_modes' => $this->filter_modes,
			'sort'         => $this->sort,
			'page'         => $this->page,
			'per_page'     => $this->per_page,
			'language'     => $this->language,
			'highlight'    => $this->highlight,
			'completion'   => $this->completion,
			'facets'       => $this->facets,
			'documents'    => $this->documents,
		);
	}

	/**
	 * Rebuild a query from its serialised form.
	 *
	 * Every value is re-coerced rather than trusted. This is the boundary a
	 * remote backend's reply, a cached payload or a queued job crosses, and the
	 * type of a value that has been through JSON is whatever JSON felt like:
	 * an integer page number comes back as a float, an empty associative array
	 * comes back as a list.
	 *
	 * Unknown keys are ignored and missing ones take their default, so a query
	 * serialised by a newer version is readable by an older one and vice versa.
	 *
	 * @since 0.10.0
	 *
	 * @param array<string,mixed> $state Serialised query.
	 * @return self
	 */
	public static function from_array( array $state ): self {
		$query = new self(
			isset( $state['terms'] ) ? (string) $state['terms'] : '',
			isset( $state['site_id'] ) ? (int) $state['site_id'] : 0
		);

		$query->post_types = array_values( array_map( 'strval', (array) ( $state['post_types'] ?? array() ) ) );
		$query->sort       = isset( $state['sort'] ) ? (string) $state['sort'] : 'relevance';
		$query->page       = max( 1, (int) ( $state['page'] ?? 1 ) );
		$query->per_page   = max( 1, (int) ( $state['per_page'] ?? 10 ) );
		$query->language   = isset( $state['language'] ) ? (string) $state['language'] : '';
		$query->highlight  = ! empty( $state['highlight'] );
		$query->completion = ! empty( $state['completion'] );
		$query->documents  = ! isset( $state['documents'] ) || (bool) $state['documents'];

		foreach ( (array) ( $state['filters'] ?? array() ) as $key => $values ) {
			$scalars = array();

			foreach ( (array) $values as $value ) {
				if ( is_scalar( $value ) ) {
					$scalars[] = $value;
				}
			}

			if ( array() !== $scalars ) {
				$query->filters[ (string) $key ] = $scalars;
			}
		}

		foreach ( (array) ( $state['filter_modes'] ?? array() ) as $key => $mode ) {
			// Anything that is not "and" is "or", which is the default a caller
			// gets by saying nothing. A mode arriving as "AND", "all" or
			// nonsense must not become a third behaviour.
			$query->filter_modes[ (string) $key ] = 'and' === $mode ? 'and' : 'or';
		}

		foreach ( (array) ( $state['facets'] ?? array() ) as $key => $parent ) {
			$query->facets[ (string) $key ] = is_scalar( $parent ) ? (string) $parent : '';
		}

		return $query;
	}
}
