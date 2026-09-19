<?php
/**
 * Flushes the dirty set and runs queued indexing jobs.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Logger;

/**
 * Connects the dirty set, the queue and the indexer.
 *
 * Two responsibilities, both small:
 *
 * 1. On shutdown, turn whatever the hook listeners marked into queued jobs.
 *    This is where coalescing pays off: six hooks firing for one post produce
 *    one job, and a bulk edit of five hundred posts produces five hundred
 *    rather than several thousand.
 * 2. When the queue fires a job, hand the references to the indexer.
 *
 * Flushing happens on shutdown rather than inline so that the whole request has
 * finished changing things first. A post saved and then immediately updated by
 * another plugin on a later hook is indexed once, in its final state.
 *
 * @since 0.3.0
 */
final class QueueWorker {

	/**
	 * Hook fired to index a set of references.
	 *
	 * @var string
	 */
	public const INDEX_HOOK = 'solrengine_index_refs';

	/**
	 * Hook fired to remove a set of references.
	 *
	 * @var string
	 */
	public const DELETE_HOOK = 'solrengine_delete_refs';

	/**
	 * How many references one job carries.
	 *
	 * Small on purpose. A job's arguments are serialised into the queue's
	 * storage, and a job that fails takes its whole payload down with it.
	 *
	 * @var int
	 */
	private const REFS_PER_JOB = 25;

	/**
	 * Dirty set filled by the hook listeners.
	 *
	 * @var DirtySet
	 */
	private $dirty;

	/**
	 * Job queue.
	 *
	 * @var JobQueue
	 */
	private $queue;

	/**
	 * Indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Whether indexing runs inline instead of through the queue.
	 *
	 * @var bool
	 */
	private $synchronous;

	/**
	 * Constructor.
	 *
	 * @param DirtySet $dirty       Dirty set.
	 * @param JobQueue $queue       Job queue.
	 * @param Indexer  $indexer     Indexer.
	 * @param Logger   $logger      Logger.
	 * @param bool     $synchronous Whether to index inline.
	 */
	public function __construct(
		DirtySet $dirty,
		JobQueue $queue,
		Indexer $indexer,
		Logger $logger,
		bool $synchronous = false
	) {
		$this->dirty       = $dirty;
		$this->queue       = $queue;
		$this->indexer     = $indexer;
		$this->logger      = $logger;
		$this->synchronous = $synchronous;
	}

	/**
	 * Attach the flush and the job handlers.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'shutdown', array( $this, 'flush' ), 100 );
		add_action( self::INDEX_HOOK, array( $this, 'handle_index' ) );
		add_action( self::DELETE_HOOK, array( $this, 'handle_delete' ) );
		add_action( FullSync::HOOK, array( $this, 'handle_full_sync_batch' ) );
	}

	/**
	 * Turn the dirty set into queued work.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function flush(): void {
		if ( $this->dirty->is_empty() ) {
			return;
		}

		$upserts = $this->dirty->upserts();
		$deletes = $this->dirty->deletes();

		// Cleared before dispatching, so that a synchronous run whose indexing
		// touches a post cannot re-enter this method and loop.
		$this->dirty->clear();

		if ( $this->synchronous ) {
			$this->indexer->index( $upserts );
			$this->indexer->remove( $deletes );

			return;
		}

		foreach ( array_chunk( $upserts, self::REFS_PER_JOB ) as $chunk ) {
			$this->queue->enqueue( self::INDEX_HOOK, array( $this->encode( $chunk ) ) );
		}

		foreach ( array_chunk( $deletes, self::REFS_PER_JOB ) as $chunk ) {
			$this->queue->enqueue( self::DELETE_HOOK, array( $this->encode( $chunk ) ) );
		}
	}

	/**
	 * Index a queued set of references.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $encoded Encoded references.
	 * @return void
	 */
	public function handle_index( $encoded ): void {
		$refs = $this->decode( $encoded );

		if ( array() === $refs ) {
			return;
		}

		$result = $this->indexer->index( $refs );

		if ( $result->is_failure() ) {
			$this->logger->error(
				'Indexing job failed',
				array(
					'count' => count( $refs ),
					'code'  => $result->code(),
				)
			);
		}
	}

	/**
	 * Remove a queued set of references.
	 *
	 * @since 0.3.0
	 *
	 * @param mixed $encoded Encoded references.
	 * @return void
	 */
	public function handle_delete( $encoded ): void {
		$refs = $this->decode( $encoded );

		if ( array() !== $refs ) {
			$this->indexer->remove( $refs );
		}
	}

	/**
	 * Run one full-sync batch.
	 *
	 * Resolved from the container at call time rather than injected, because a
	 * full sync is rare and constructing it on every request that registers the
	 * hook would defeat the laziness the composition root is built around.
	 *
	 * @since 0.3.0
	 *
	 * @return void
	 */
	public function handle_full_sync_batch(): void {
		/**
		 * Fires when the queue asks for the next full-sync batch.
		 *
		 * @since 0.3.0
		 */
		do_action( 'solrengine_run_full_sync_batch' );
	}

	/**
	 * Encode references for storage in a job's arguments.
	 *
	 * Plain arrays of scalars, because queue drivers serialise arguments and a
	 * stored object would break the moment its class changed.
	 *
	 * @param DocumentRef[] $refs References.
	 * @return array<int,array<string,mixed>>
	 */
	private function encode( array $refs ): array {
		$encoded = array();

		foreach ( $refs as $ref ) {
			$encoded[] = array(
				'site' => $ref->site_id(),
				'type' => $ref->object_type(),
				'id'   => $ref->object_id(),
			);
		}

		return $encoded;
	}

	/**
	 * Rebuild references from a job's arguments.
	 *
	 * @param mixed $encoded Encoded references.
	 * @return DocumentRef[]
	 */
	private function decode( $encoded ): array {
		if ( ! is_array( $encoded ) ) {
			return array();
		}

		$refs = array();

		foreach ( $encoded as $item ) {
			if ( ! is_array( $item ) || ! isset( $item['site'], $item['type'], $item['id'] ) ) {
				continue;
			}

			$refs[] = new DocumentRef( (int) $item['site'], (string) $item['type'], (int) $item['id'] );
		}

		return $refs;
	}
}
