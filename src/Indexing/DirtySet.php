<?php
/**
 * Request-scoped set of objects that need reindexing.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\DocumentRef;

/**
 * Collects what changed during this request, once per object.
 *
 * The single most important piece of the indexing design, and the cheapest.
 *
 * One wp_update_post() fires save_post, wp_after_insert_post, a handful of
 * updated_post_meta calls, set_object_terms per taxonomy and
 * transition_post_status. A listener that queued work directly would produce
 * six or more jobs for one logical change, and a bulk edit of five hundred
 * posts would produce thousands. Hook listeners therefore only mark objects
 * dirty here, and a single shutdown handler turns the set into exactly one job
 * per changed object.
 *
 * Deletions win over updates. If an object is marked both — trashed and then
 * its meta cleaned up in the same request, say — indexing it would resurrect a
 * document that should be gone, so removal takes precedence.
 *
 * @since 0.3.0
 */
final class DirtySet {

	/**
	 * References to index, keyed by deduplication key.
	 *
	 * @var array<string,DocumentRef>
	 */
	private $upserts = array();

	/**
	 * References to remove, keyed by deduplication key.
	 *
	 * @var array<string,DocumentRef>
	 */
	private $deletes = array();

	/**
	 * Why each object was marked, for diagnostics.
	 *
	 * @var array<string,string>
	 */
	private $reasons = array();

	/**
	 * Mark an object as needing indexing.
	 *
	 * @since 0.3.0
	 *
	 * @param DocumentRef $ref    Object that changed.
	 * @param string      $reason What changed, for diagnostics.
	 * @return void
	 */
	public function mark_for_index( DocumentRef $ref, string $reason = '' ): void {
		$key = $ref->dedupe_key();

		if ( isset( $this->deletes[ $key ] ) ) {
			// Already destined for removal. Indexing it now would put back a
			// document that a deletion in the same request said to remove.
			return;
		}

		$this->upserts[ $key ] = $ref;
		$this->remember( $key, $reason );
	}

	/**
	 * Mark an object as needing removal.
	 *
	 * @since 0.3.0
	 *
	 * @param DocumentRef $ref    Object that went away.
	 * @param string      $reason What happened, for diagnostics.
	 * @return void
	 */
	public function mark_for_delete( DocumentRef $ref, string $reason = '' ): void {
		$key = $ref->dedupe_key();

		unset( $this->upserts[ $key ] );

		$this->deletes[ $key ] = $ref;
		$this->remember( $key, $reason );
	}

	/**
	 * References to index.
	 *
	 * @since 0.3.0
	 *
	 * @return DocumentRef[]
	 */
	public function upserts(): array {
		return array_values( $this->upserts );
	}

	/**
	 * References to remove.
	 *
	 * @since 0.3.0
	 *
	 * @return DocumentRef[]
	 */
	public function deletes(): array {
		return array_values( $this->deletes );
	}

	/**
	 * Why an object was marked.
	 *
	 * @since 0.3.0
	 *
	 * @param DocumentRef $ref Object.
	 * @return string
	 */
	public function reason_for( DocumentRef $ref ): string {
		return $this->reasons[ $ref->dedupe_key() ] ?? '';
	}

	/**
	 * How many distinct objects are marked.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function count(): int {
		return count( $this->upserts ) + count( $this->deletes );
	}

	/**
	 * Whether anything is marked.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->upserts && array() === $this->deletes;
	}

	/**
	 * Empty the set.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->upserts = array();
		$this->deletes = array();
		$this->reasons = array();
	}

	/**
	 * Record the first reason seen for an object.
	 *
	 * The first is kept rather than the last, because it is the one that
	 * describes what actually happened. Later marks in the same request are
	 * usually incidental: a meta update triggered by the save that came first.
	 *
	 * @param string $key    Deduplication key.
	 * @param string $reason Reason.
	 * @return void
	 */
	private function remember( string $key, string $reason ): void {
		if ( '' !== $reason && ! isset( $this->reasons[ $key ] ) ) {
			$this->reasons[ $key ] = $reason;
		}
	}
}
