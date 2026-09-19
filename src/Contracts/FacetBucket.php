<?php
/**
 * One counted value within a facet.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A value and how many documents carry it.
 *
 * Carries the raw indexed value as well as a label, and the two are not the
 * same thing: the value is what a caller sends back to filter by, the label is
 * what a visitor reads. For a taxonomy facet the value is a term identifier and
 * the label is the term's name, which means a site can rename a category
 * without invalidating a bookmarked filtered URL.
 *
 * A bucket starts out labelled with its own value. FacetLabeller replaces that
 * with something readable, and does so at render time rather than at parse
 * time, so a cached result set never holds a label that a subsequent rename
 * made wrong.
 *
 * Immutable.
 *
 * @since 0.6.0 Unstable until 1.0.
 */
final class FacetBucket {

	/**
	 * The indexed value, as a caller would send it back.
	 *
	 * @var string
	 */
	private $value;

	/**
	 * Documents carrying it.
	 *
	 * @var int
	 */
	private $count;

	/**
	 * What a visitor reads.
	 *
	 * @var string
	 */
	private $label;

	/**
	 * Whether the current query already filters on this value.
	 *
	 * @var bool
	 */
	private $selected = false;

	/**
	 * Whether anything sits below this value, for a hierarchy facet.
	 *
	 * @var bool
	 */
	private $has_children = false;

	/**
	 * Constructor.
	 *
	 * @param string $value Indexed value.
	 * @param int    $count Documents carrying it.
	 * @param string $label Human label, defaulting to the value.
	 */
	public function __construct( string $value, int $count, string $label = '' ) {
		$this->value = $value;
		$this->count = $count;
		$this->label = '' !== $label ? $label : $value;
	}

	/**
	 * The indexed value.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function value(): string {
		return $this->value;
	}

	/**
	 * Documents carrying it.
	 *
	 * @since 0.6.0
	 *
	 * @return int
	 */
	public function count(): int {
		return $this->count;
	}

	/**
	 * What a visitor reads.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * Whether the current query already filters on this value.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_selected(): bool {
		return $this->selected;
	}

	/**
	 * Whether anything sits below this value.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function has_children(): bool {
		return $this->has_children;
	}

	/**
	 * A copy with a readable label.
	 *
	 * @since 0.6.0
	 *
	 * @param string $label Human label.
	 * @return self
	 */
	public function with_label( string $label ): self {
		$clone = clone $this;

		$clone->label = '' !== $label ? $label : $this->value;

		return $clone;
	}

	/**
	 * A copy marked as selected or not.
	 *
	 * @since 0.6.0
	 *
	 * @param bool $selected Whether the query filters on it.
	 * @return self
	 */
	public function with_selected( bool $selected ): self {
		$clone = clone $this;

		$clone->selected = $selected;

		return $clone;
	}

	/**
	 * A copy marked as having children.
	 *
	 * @since 0.6.0
	 *
	 * @param bool $has_children Whether anything sits below it.
	 * @return self
	 */
	public function with_children( bool $has_children ): self {
		$clone = clone $this;

		$clone->has_children = $has_children;

		return $clone;
	}
}
