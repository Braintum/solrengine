<?php
/**
 * One facet a caller may ask for.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A server-declared facet.
 *
 * The unit the facet registry holds, and the reason the public endpoint can be
 * public: a caller names a definition, it never describes one. Everything Solr
 * needs — which field, which type, how many buckets, how they sort — is decided
 * here, on the server, by an administrator or a developer. A request carries
 * the key "category" and nothing else.
 *
 * Three kinds, because they answer different questions:
 *
 * - **terms**, "how many documents per value" — the ordinary case.
 * - **range**, "how many documents per numeric band" — prices, ratings.
 * - **hierarchy**, "how many documents per child of the term I am looking at" —
 *   one level of a taxonomy tree, from the materialised path field.
 *
 * Immutable, and deliberately free of callbacks. A definition ends up inside a
 * cached SearchResults, and a closure on it would make that object
 * unserialisable — so labels for bucket values are resolved by FacetLabeller at
 * render time rather than carried here.
 *
 * @since 0.6.0 Unstable until 1.0.
 */
final class FacetDefinition {

	/**
	 * Counts per distinct value.
	 *
	 * @var string
	 */
	public const TERMS = 'terms';

	/**
	 * Counts per numeric band.
	 *
	 * @var string
	 */
	public const RANGE = 'range';

	/**
	 * Counts per child of one node of a taxonomy tree.
	 *
	 * @var string
	 */
	public const HIERARCHY = 'hierarchy';

	/**
	 * Public key a caller names.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Human label for a UI.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Index field the counts come from.
	 *
	 * @var string
	 */
	private $field;

	/**
	 * Which kind of facet this is.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Type of the values a caller may filter by, from FieldType.
	 *
	 * @var string
	 */
	private $value_type;

	/**
	 * Most buckets to return.
	 *
	 * @var int
	 */
	private $limit = 20;

	/**
	 * Smallest count worth a bucket.
	 *
	 * @var int
	 */
	private $min_count = 1;

	/**
	 * Bucket order: "count" or "index".
	 *
	 * @var string
	 */
	private $sort = 'count';

	/**
	 * Whether selecting one value leaves the others countable.
	 *
	 * @var bool
	 */
	private $multi_select = true;

	/**
	 * Taxonomy a hierarchy facet walks, empty otherwise.
	 *
	 * @var string
	 */
	private $taxonomy = '';

	/**
	 * Whether counts must be exact across shards.
	 *
	 * @var bool
	 */
	private $refine = false;

	/**
	 * Range start, for a range facet.
	 *
	 * @var float
	 */
	private $start = 0.0;

	/**
	 * Range end, for a range facet.
	 *
	 * @var float
	 */
	private $end = 0.0;

	/**
	 * Width of each range bucket.
	 *
	 * @var float
	 */
	private $gap = 0.0;

	/**
	 * Constructor.
	 *
	 * Private: the three kinds want different arguments, and a single public
	 * constructor taking every one of them would accept combinations that mean
	 * nothing, such as a gap on a terms facet.
	 *
	 * @param string $key        Public key.
	 * @param string $label      Human label.
	 * @param string $field      Index field.
	 * @param string $type       Facet kind.
	 * @param string $value_type Filter value type.
	 */
	private function __construct( string $key, string $label, string $field, string $type, string $value_type ) {
		$this->key        = $key;
		$this->label      = $label;
		$this->field      = $field;
		$this->type       = $type;
		$this->value_type = $value_type;
	}

	/**
	 * A facet counting distinct values of a field.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key        Public key.
	 * @param string $field      Index field.
	 * @param string $label      Human label.
	 * @param string $value_type Filter value type, from FieldType.
	 * @return self
	 */
	public static function terms( string $key, string $field, string $label, string $value_type = FieldType::STRING ): self {
		return new self( $key, $label, $field, self::TERMS, $value_type );
	}

	/**
	 * A facet counting one level of a taxonomy tree.
	 *
	 * Reads the materialised path field rather than the term identifiers, which
	 * is what lets one request return exactly the children of one node with
	 * counts that include everything below them.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key      Public key.
	 * @param string $taxonomy Taxonomy name.
	 * @param string $field    Path field.
	 * @param string $label    Human label.
	 * @return self
	 */
	public static function hierarchy( string $key, string $taxonomy, string $field, string $label ): self {
		$definition = new self( $key, $label, $field, self::HIERARCHY, FieldType::STRING );

		$definition->taxonomy = $taxonomy;

		return $definition;
	}

	/**
	 * A facet counting documents per numeric band.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key   Public key.
	 * @param string $field Index field.
	 * @param string $label Human label.
	 * @param float  $start First band's lower bound.
	 * @param float  $end   Last band's upper bound.
	 * @param float  $gap   Band width.
	 * @return self
	 */
	public static function range( string $key, string $field, string $label, float $start, float $end, float $gap ): self {
		$definition = new self( $key, $label, $field, self::RANGE, FieldType::FLOAT );

		$definition->start = $start;
		$definition->end   = $end;
		$definition->gap   = $gap;

		return $definition;
	}

	/**
	 * Public key.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Human label.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Index field the counts come from.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function field(): string {
		return $this->field;
	}

	/**
	 * Which kind of facet this is.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Type of the values a caller may filter by.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function value_type(): string {
		return $this->value_type;
	}

	/**
	 * Most buckets to return.
	 *
	 * @since 0.6.0
	 *
	 * @return int
	 */
	public function limit(): int {
		return $this->limit;
	}

	/**
	 * Smallest count worth a bucket.
	 *
	 * @since 0.6.0
	 *
	 * @return int
	 */
	public function min_count(): int {
		return $this->min_count;
	}

	/**
	 * Bucket order.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function sort(): string {
		return $this->sort;
	}

	/**
	 * Whether selecting one value leaves the others countable.
	 *
	 * True for the ordinary case: a shopper who ticks "Lenovo" still wants to
	 * see how many Dells there are, so this facet's own filter is excluded from
	 * its own domain. False means selecting a value narrows this facet too,
	 * which is what a single-choice filter wants.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_multi_select(): bool {
		return $this->multi_select;
	}

	/**
	 * Taxonomy a hierarchy facet walks.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function taxonomy(): string {
		return $this->taxonomy;
	}

	/**
	 * Whether counts must be exact across shards.
	 *
	 * Off by default, and that is a considered choice rather than an oversight.
	 * On a single-shard collection — which is every self-hosted install and
	 * most SolrCloud ones — counts are exact anyway and refinement buys
	 * nothing but a second round trip. It matters only where a facet's counts
	 * are load-bearing across many shards, such as a price band a shopper
	 * filters by, and there it is worth asking for explicitly.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_refined(): bool {
		return $this->refine;
	}

	/**
	 * Range start.
	 *
	 * @since 0.6.0
	 *
	 * @return float
	 */
	public function start(): float {
		return $this->start;
	}

	/**
	 * Range end.
	 *
	 * @since 0.6.0
	 *
	 * @return float
	 */
	public function end(): float {
		return $this->end;
	}

	/**
	 * Band width.
	 *
	 * @since 0.6.0
	 *
	 * @return float
	 */
	public function gap(): float {
		return $this->gap;
	}

	/**
	 * Whether this definition could produce a sane Solr request.
	 *
	 * Checked when a facet is registered rather than when one is requested, so
	 * a mistake in a filter surfaces on the next page load rather than as an
	 * empty facet nobody can explain.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_usable(): bool {
		if ( '' === $this->key || '' === $this->field || ! FieldType::is_valid( $this->value_type ) ) {
			return false;
		}

		// Only fields SolrEngine owns. A definition pointing at an arbitrary
		// field would turn a facet into a way to enumerate another
		// application's data in a shared collection.
		if ( 0 !== strpos( $this->field, 'se_' ) ) {
			return false;
		}

		if ( self::HIERARCHY === $this->type && '' === $this->taxonomy ) {
			return false;
		}

		if ( self::RANGE === $this->type && ( $this->gap <= 0.0 || $this->end <= $this->start ) ) {
			// A zero or negative gap asks Solr for an unbounded number of
			// buckets, which is a request that never returns.
			return false;
		}

		return true;
	}

	/**
	 * A copy returning at most a given number of buckets.
	 *
	 * @since 0.6.0
	 *
	 * @param int $limit Bucket ceiling.
	 * @return self
	 */
	public function with_limit( int $limit ): self {
		$clone = clone $this;

		// Capped. A facet limit is a request for Solr to sort that many
		// buckets, and an uncapped one on a high-cardinality field is an
		// inexpensive way to make the server work hard.
		$clone->limit = max( 1, min( 500, $limit ) );

		return $clone;
	}

	/**
	 * A copy with a different minimum count.
	 *
	 * @since 0.6.0
	 *
	 * @param int $min_count Smallest count worth a bucket.
	 * @return self
	 */
	public function with_min_count( int $min_count ): self {
		$clone = clone $this;

		$clone->min_count = max( 0, $min_count );

		return $clone;
	}

	/**
	 * A copy ordered differently.
	 *
	 * @since 0.6.0
	 *
	 * @param string $sort "count" or "index".
	 * @return self
	 */
	public function with_sort( string $sort ): self {
		$clone = clone $this;

		$clone->sort = 'index' === $sort ? 'index' : 'count';

		return $clone;
	}

	/**
	 * A copy that counts values of a taxonomy.
	 *
	 * Recorded so labels can be resolved: a bucket keyed 34 is "Guides" to a
	 * reader, and only the taxonomy says which 34 that is.
	 *
	 * @since 0.6.0
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return self
	 */
	public function with_taxonomy( string $taxonomy ): self {
		$clone = clone $this;

		$clone->taxonomy = $taxonomy;

		return $clone;
	}

	/**
	 * A copy whose counts are exact across shards.
	 *
	 * @since 0.6.0
	 *
	 * @param bool $refine Whether to refine.
	 * @return self
	 */
	public function with_refine( bool $refine = true ): self {
		$clone = clone $this;

		$clone->refine = $refine;

		return $clone;
	}

	/**
	 * A copy whose own selection narrows its own counts.
	 *
	 * @since 0.6.0
	 *
	 * @param bool $multi_select Whether to exclude its own filter.
	 * @return self
	 */
	public function with_multi_select( bool $multi_select ): self {
		$clone = clone $this;

		$clone->multi_select = $multi_select;

		return $clone;
	}
}
