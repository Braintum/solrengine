<?php
/**
 * A list of autocomplete suggestions.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What an autocomplete request found.
 *
 * Immutable.
 *
 * @since 0.5.0 Unstable until 1.0.
 */
final class Suggestions {

	/**
	 * The suggestions, in order.
	 *
	 * @var Suggestion[]
	 */
	private $items;

	/**
	 * How long the backend took, in milliseconds.
	 *
	 * @var int
	 */
	private $took_ms;

	/**
	 * The prefix that produced them.
	 *
	 * @var string
	 */
	private $prefix;

	/**
	 * Constructor.
	 *
	 * @param Suggestion[] $items   The suggestions.
	 * @param string       $prefix  The prefix that produced them.
	 * @param int          $took_ms Backend duration.
	 */
	public function __construct( array $items, string $prefix = '', int $took_ms = 0 ) {
		$this->items   = $items;
		$this->prefix  = $prefix;
		$this->took_ms = $took_ms;
	}

	/**
	 * The suggestions.
	 *
	 * @since 0.5.0
	 *
	 * @return Suggestion[]
	 */
	public function items(): array {
		return $this->items;
	}

	/**
	 * Whether anything was found.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->items;
	}

	/**
	 * How many suggestions there are.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->items );
	}

	/**
	 * Backend duration in milliseconds.
	 *
	 * @since 0.5.0
	 *
	 * @return int
	 */
	public function took_ms(): int {
		return $this->took_ms;
	}

	/**
	 * The prefix that produced them.
	 *
	 * @since 0.5.0
	 *
	 * @return string
	 */
	public function prefix(): string {
		return $this->prefix;
	}
}
