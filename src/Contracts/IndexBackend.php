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

	/**
	 * Write documents.
	 *
	 * Deliberately the only write method. There is no write_one(): a
	 * single-document path gets used by accident inside loops, and that is how
	 * the 0.1.0 prototype ended up committing once per post. Callers with one
	 * document pass an array of one.
	 *
	 * Writes are upserts keyed on DocumentRef::document_id(), so reindexing is
	 * idempotent and a replayed job costs time rather than correctness.
	 *
	 * Implementations must not commit per document. Visibility is arranged
	 * through a commit window so that Solr batches the work.
	 *
	 * @since 0.3.0
	 *
	 * @param Document[] $documents Documents to write.
	 * @return Result Success carrying a WriteResult, which may report partial
	 *                failure, or a failure when the whole batch was refused.
	 */
	public function write_batch( array $documents ): Result;

	/**
	 * Remove every document matching a selector.
	 *
	 * The way a site's documents are removed without enumerating a million
	 * identifiers. Implementations must refuse an unconstrained selector.
	 *
	 * @since 0.3.0
	 *
	 * @param Selector $selector Which documents to remove.
	 * @return Result Success carrying a WriteResult, or a failure.
	 */
	public function delete_by_selector( Selector $selector ): Result;
}
