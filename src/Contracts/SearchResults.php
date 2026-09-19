<?php
/**
 * The outcome of a search.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What a search found.
 *
 * Immutable.
 *
 * @since 0.4.0 Unstable until 1.0.
 */
final class SearchResults {

	/**
	 * Matching objects, in relevance order.
	 *
	 * @var SearchHit[]
	 */
	private $hits;

	/**
	 * Total matches, across all pages.
	 *
	 * @var int
	 */
	private $total;

	/**
	 * How long the backend took, in milliseconds.
	 *
	 * @var int
	 */
	private $took_ms;

	/**
	 * The query that produced these results.
	 *
	 * @var SearchQuery
	 */
	private $query;

	/**
	 * Which retrieval strategy produced these results.
	 *
	 * @var string
	 */
	private $strategy;

	/**
	 * Facet counts, keyed by facet key.
	 *
	 * @var array<string,FacetResult>
	 */
	private $facets = array();

	/**
	 * Constructor.
	 *
	 * @param SearchHit[] $hits     Matching objects.
	 * @param int         $total    Total matches.
	 * @param SearchQuery $query    The query that ran.
	 * @param int         $took_ms  Backend duration.
	 * @param string      $strategy Retrieval strategy used.
	 */
	public function __construct(
		array $hits,
		int $total,
		SearchQuery $query,
		int $took_ms = 0,
		string $strategy = 'standard'
	) {
		$this->hits     = $hits;
		$this->total    = $total;
		$this->query    = $query;
		$this->took_ms  = $took_ms;
		$this->strategy = $strategy;
	}

	/**
	 * Matching objects.
	 *
	 * @since 0.4.0
	 *
	 * @return SearchHit[]
	 */
	public function hits(): array {
		return $this->hits;
	}

	/**
	 * WordPress object identifiers, in relevance order.
	 *
	 * @since 0.4.0
	 *
	 * @return int[]
	 */
	public function object_ids(): array {
		$ids = array();

		foreach ( $this->hits as $hit ) {
			$ids[] = $hit->object_id();
		}

		return $ids;
	}

	/**
	 * Total matches across all pages.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function total(): int {
		return $this->total;
	}

	/**
	 * How many pages there are.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function total_pages(): int {
		return (int) ceil( $this->total / max( 1, $this->query->per_page() ) );
	}

	/**
	 * Whether anything matched.
	 *
	 * @since 0.4.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->hits;
	}

	/**
	 * Backend duration in milliseconds.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function took_ms(): int {
		return $this->took_ms;
	}

	/**
	 * The query that produced these results.
	 *
	 * @since 0.4.0
	 *
	 * @return SearchQuery
	 */
	public function query(): SearchQuery {
		return $this->query;
	}

	/**
	 * Which retrieval strategy produced these results.
	 *
	 * "standard", "fuzzy" or "loosened". Surfaced so a theme can say "no exact
	 * matches, showing similar results" rather than silently pretending a
	 * fuzzy match was what the visitor asked for.
	 *
	 * @since 0.4.0
	 *
	 * @return string
	 */
	public function strategy(): string {
		return $this->strategy;
	}

	/**
	 * Facet counts, keyed by facet key.
	 *
	 * Carried here rather than fetched separately because a count without the
	 * query that produced it is meaningless: "42 guides" is only true of one
	 * particular set of filters, and a second request to work them out would
	 * be answered against an index that had moved on.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string,FacetResult>
	 */
	public function facets(): array {
		return $this->facets;
	}

	/**
	 * One facet's counts.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return FacetResult|null
	 */
	public function facet( string $key ): ?FacetResult {
		return $this->facets[ $key ] ?? null;
	}

	/**
	 * A copy carrying facet counts.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,FacetResult> $facets Facet counts.
	 * @return self
	 */
	public function with_facets( array $facets ): self {
		$clone         = clone $this;
		$clone->facets = $facets;

		return $clone;
	}

	/**
	 * A copy reporting a different strategy.
	 *
	 * @since 0.4.0
	 *
	 * @param string $strategy Strategy name.
	 * @return self
	 */
	public function with_strategy( string $strategy ): self {
		$clone           = clone $this;
		$clone->strategy = $strategy;

		return $clone;
	}
}
