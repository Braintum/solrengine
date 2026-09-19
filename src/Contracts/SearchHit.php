<?php
/**
 * One search result.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A single matching object, as the index knows it.
 *
 * Carries only what the index stored, not a hydrated WordPress object. The
 * query integration turns these into posts when WordPress needs posts; the
 * REST layer renders them directly. Keeping them apart means a REST search
 * costs no database queries at all.
 *
 * Immutable.
 *
 * @since 0.4.0 Unstable until 1.0.
 */
final class SearchHit {

	/**
	 * What matched.
	 *
	 * @var DocumentRef
	 */
	private $ref;

	/**
	 * Relevance score as the backend reported it.
	 *
	 * @var float
	 */
	private $score;

	/**
	 * Stored field values.
	 *
	 * @var array<string,mixed>
	 */
	private $fields;

	/**
	 * Highlighted snippets keyed by field name.
	 *
	 * @var array<string,string[]>
	 */
	private $highlights;

	/**
	 * Constructor.
	 *
	 * @param DocumentRef            $ref        What matched.
	 * @param float                  $score      Relevance score.
	 * @param array<string,mixed>    $fields     Stored field values.
	 * @param array<string,string[]> $highlights Highlighted snippets.
	 */
	public function __construct( DocumentRef $ref, float $score, array $fields = array(), array $highlights = array() ) {
		$this->ref        = $ref;
		$this->score      = $score;
		$this->fields     = $fields;
		$this->highlights = $highlights;
	}

	/**
	 * What matched.
	 *
	 * @since 0.4.0
	 *
	 * @return DocumentRef
	 */
	public function ref(): DocumentRef {
		return $this->ref;
	}

	/**
	 * The WordPress object identifier.
	 *
	 * @since 0.4.0
	 *
	 * @return int
	 */
	public function object_id(): int {
		return $this->ref->object_id();
	}

	/**
	 * Relevance score.
	 *
	 * @since 0.4.0
	 *
	 * @return float
	 */
	public function score(): float {
		return $this->score;
	}

	/**
	 * A stored field value.
	 *
	 * @since 0.4.0
	 *
	 * @param string $field    Field name.
	 * @param mixed  $fallback Returned when absent.
	 * @return mixed
	 */
	public function field( string $field, $fallback = null ) {
		return array_key_exists( $field, $this->fields ) ? $this->fields[ $field ] : $fallback;
	}

	/**
	 * Every stored field.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,mixed>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Highlighted snippets keyed by field name.
	 *
	 * Snippets contain markup from the backend, so they must be escaped with a
	 * function that permits the highlight tag and nothing else. Never echo one
	 * unfiltered.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,string[]>
	 */
	public function highlights(): array {
		return $this->highlights;
	}
}
