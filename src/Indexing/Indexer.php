<?php
/**
 * Turns references into index writes.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\WriteResult;
use SolrEngine\Documents\PostDocumentBuilder;
use WP_Post;

/**
 * Builds documents for a set of references and writes them in one batch.
 *
 * The one place that knows how a reference becomes a document and reaches the
 * backend. Hook listeners, the queue worker, the full sync and WP-CLI all come
 * through here, so they cannot drift in what they index or how they handle
 * failure.
 *
 * Failure isolation is the notable behaviour. One malformed document must not
 * discard a batch of a hundred, so a rejected batch is retried document by
 * document and the offenders are reported individually. Ninety-nine innocent
 * documents getting indexed is worth one extra round trip on the rare occasion
 * a batch fails.
 *
 * @since 0.3.0
 */
final class Indexer {

	/**
	 * Backend to write to.
	 *
	 * @var IndexBackend
	 */
	private $backend;

	/**
	 * Document builder.
	 *
	 * @var PostDocumentBuilder
	 */
	private $builder;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param IndexBackend        $backend Backend to write to.
	 * @param PostDocumentBuilder $builder Document builder.
	 * @param Logger              $logger  Logger.
	 */
	public function __construct( IndexBackend $backend, PostDocumentBuilder $builder, Logger $logger ) {
		$this->backend = $backend;
		$this->builder = $builder;
		$this->logger  = $logger;
	}

	/**
	 * Index a set of references.
	 *
	 * A reference whose object is gone or is no longer publicly visible is
	 * removed rather than skipped. That is what makes the queue self-correcting:
	 * a job created while a post was public, and run after it was unpublished,
	 * does the right thing instead of indexing something it should not.
	 *
	 * @since 0.3.0
	 *
	 * @param DocumentRef[] $refs References to index.
	 * @return Result Success carrying an IndexOutcome, or a failure when the
	 *                backend refused everything.
	 */
	public function index( array $refs ): Result {
		$documents = array();
		$removals  = array();
		$skipped   = array();

		foreach ( $refs as $ref ) {
			$post = get_post( $ref->object_id() );

			if ( ! $post instanceof WP_Post ) {
				$removals[] = $ref;

				continue;
			}

			$built = $this->builder->build( $post );

			if ( $built->is_failure() ) {
				// Not an error. A post that became a draft, or that a filter
				// vetoed, must leave the index rather than linger in it.
				$removals[]                    = $ref;
				$skipped[ $ref->dedupe_key() ] = $built->code();

				continue;
			}

			$documents[] = $built->value();
		}

		$written = $this->write( $documents );
		$deleted = $this->remove( $removals );

		if ( $written->is_failure() && $deleted->is_failure() ) {
			return $written;
		}

		$outcome = new IndexOutcome(
			$written->is_ok() ? $written->value()->written() : 0,
			count( $removals ),
			$written->is_ok() ? $written->value()->failures() : array(),
			$skipped
		);

		/**
		 * Fires after a batch has been written to the index.
		 *
		 * @since 0.3.0
		 *
		 * @param DocumentRef[] $refs    References that were processed.
		 * @param IndexOutcome  $outcome What happened to them.
		 */
		do_action( 'solrengine_indexed', $refs, $outcome );

		return Result::success( $outcome );
	}

	/**
	 * Make everything written so far visible.
	 *
	 * A passthrough, but a deliberate one: the backend is private here, and the
	 * alternative is exposing it so a caller can reach past the indexer to the
	 * thing the indexer exists to wrap.
	 *
	 * @since 0.8.0
	 *
	 * @return Result
	 */
	public function commit(): Result {
		return $this->backend->commit();
	}

	/**
	 * Remove a set of references.
	 *
	 * @since 0.3.0
	 *
	 * @param DocumentRef[] $refs References to remove.
	 * @return Result
	 */
	public function remove( array $refs ): Result {
		if ( array() === $refs ) {
			return Result::success( new WriteResult( 0 ) );
		}

		$result = $this->backend->delete( $refs );

		if ( $result->is_failure() ) {
			$this->logger->error(
				'Failed to remove documents',
				array(
					'count' => count( $refs ),
					'code'  => $result->code(),
				)
			);
		}

		return $result;
	}

	/**
	 * Write documents, isolating any that the backend rejects.
	 *
	 * @param Document[] $documents Documents to write.
	 * @return Result Success carrying a WriteResult.
	 */
	private function write( array $documents ): Result {
		if ( array() === $documents ) {
			return Result::success( new WriteResult( 0 ) );
		}

		$result = $this->backend->write_batch( $documents );

		if ( $result->is_ok() ) {
			return $result;
		}

		if ( 1 === count( $documents ) ) {
			// Already isolated. Report it rather than recursing.
			$document = $documents[0];

			$this->logger->error(
				'Document rejected',
				array(
					'id'   => $document->id(),
					'code' => $result->code(),
				)
			);

			return Result::success(
				new WriteResult( 0, array( $document->ref()->dedupe_key() => $result->message() ) )
			);
		}

		$this->logger->warning(
			'Batch rejected, retrying one document at a time',
			array(
				'count' => count( $documents ),
				'code'  => $result->code(),
			)
		);

		$written  = 0;
		$failures = array();

		foreach ( $documents as $document ) {
			$single = $this->backend->write_batch( array( $document ) );

			if ( $single->is_ok() ) {
				++$written;

				continue;
			}

			$failures[ $document->ref()->dedupe_key() ] = $single->message();

			/**
			 * Fires when one document could not be indexed.
			 *
			 * @since 0.3.0
			 *
			 * @param DocumentRef $ref   The document that failed.
			 * @param string      $error Why it failed.
			 */
			do_action( 'solrengine_index_failed', $document->ref(), $single->message() );
		}

		return Result::success( new WriteResult( $written, $failures ) );
	}
}
