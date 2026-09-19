<?php
/**
 * The counts one facet produced.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What one facet found, with the selection that produced it.
 *
 * Carries the selected values alongside the buckets deliberately. A filter UI
 * has to render a ticked box for a value that has been selected even when that
 * value's bucket is missing from the response — which happens whenever another
 * filter narrowed it to zero — and a UI that could not do that would silently
 * drop the control a visitor needs in order to undo their own selection.
 *
 * Immutable.
 *
 * @since 0.6.0 Unstable until 1.0.
 */
final class FacetResult {

	/**
	 * Facet key.
	 *
	 * @var string
	 */
	private $key;

	/**
	 * Human label for the facet itself.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Which kind of facet produced this.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Counted values.
	 *
	 * @var FacetBucket[]
	 */
	private $buckets;

	/**
	 * Values the query filtered on.
	 *
	 * @var string[]
	 */
	private $selected = array();

	/**
	 * Node these counts are children of, for a hierarchy facet.
	 *
	 * @var string
	 */
	private $parent = '';

	/**
	 * Constructor.
	 *
	 * @param string        $key     Facet key.
	 * @param string        $label   Human label.
	 * @param string        $type    Facet kind.
	 * @param FacetBucket[] $buckets Counted values.
	 */
	public function __construct( string $key, string $label, string $type, array $buckets = array() ) {
		$this->key     = $key;
		$this->label   = $label;
		$this->type    = $type;
		$this->buckets = $buckets;
	}

	/**
	 * Facet key.
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
	 * Which kind of facet produced this.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Counted values.
	 *
	 * @since 0.6.0
	 *
	 * @return FacetBucket[]
	 */
	public function buckets(): array {
		return $this->buckets;
	}

	/**
	 * Values the query filtered on.
	 *
	 * @since 0.6.0
	 *
	 * @return string[]
	 */
	public function selected(): array {
		return $this->selected;
	}

	/**
	 * Node these counts are children of.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function parent(): string {
		return $this->parent;
	}

	/**
	 * Whether anything was counted.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->buckets;
	}

	/**
	 * A copy with different buckets.
	 *
	 * @since 0.6.0
	 *
	 * @param FacetBucket[] $buckets Counted values.
	 * @return self
	 */
	public function with_buckets( array $buckets ): self {
		$clone = clone $this;

		$clone->buckets = $buckets;

		return $clone;
	}

	/**
	 * A copy recording what the query filtered on.
	 *
	 * @since 0.6.0
	 *
	 * @param string[] $selected Selected values.
	 * @return self
	 */
	public function with_selected( array $selected ): self {
		$clone = clone $this;

		$clone->selected = array_values( array_map( 'strval', $selected ) );

		return $clone;
	}

	/**
	 * A copy recording which node these are children of.
	 *
	 * @since 0.6.0
	 *
	 * @param string $parent_path Parent path.
	 * @return self
	 */
	public function with_parent( string $parent_path ): self {
		$clone = clone $this;

		$clone->parent = $parent_path;

		return $clone;
	}
}
