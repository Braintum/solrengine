<?php
/**
 * Index backend contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Writes to a search index.
 *
 * Split from SearchBackend (ADR-007). Indexing can be built and tested with no
 * search path in existence, which is the order the roadmap builds them in.
 *
 * Implementations must not throw, must not commit per document, and must treat
 * writes as upserts keyed by DocumentRef::document_id() so that reindexing is
 * idempotent.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
interface IndexBackend {

	/**
	 * Identifier for this backend, matching the SearchBackend identifier when
	 * one implementation provides both.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * What the backend reports about the index.
	 *
	 * @since 0.1.0
	 *
	 * @return Result Success carrying an IndexStatus, or a failure.
	 */
	public function status(): Result;

	/**
	 * Remove documents by reference.
	 *
	 * Deleting a reference that is not present is a success, not a failure:
	 * deletion is called on trash, on status transitions and during
	 * reconciliation, and in each case the desired end state is "absent".
	 *
	 * @since 0.1.0
	 *
	 * @param DocumentRef[] $refs References to remove.
	 * @return Result Success carrying a WriteResult, or a failure.
	 */
	public function delete( array $refs ): Result;

	/*
	 * Arriving in later phases:
	 *
	 *   Phase 2: write_batch( Document[] $documents ): Result // value: WriteResult
	 *   Phase 2: delete_by_selector( Selector $selector ): Result
	 *
	 * write_batch is deliberately the only write method. There is no
	 * write_one(): a single-document path would be used by accident in loops
	 * and would reintroduce the per-document commit that made the prototype
	 * slow. Callers with one document pass an array of one.
	 */
}
