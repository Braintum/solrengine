<?php
/**
 * Walks the whole site into the index.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Documents\IndexedTypes;
use SolrEngine\Support\Config;

/**
 * Indexes every eligible object, one batch at a time.
 *
 * Cursor-based, never offset-based. Offset pagination degrades as the offset
 * grows, and worse, it silently skips rows when content changes mid-run: delete
 * a post at position 50 and everything after it shifts back one, so one post
 * per deletion is never visited. An ID cursor is stable under concurrent
 * writes, cheap at any depth, and resumable after a crash because the position
 * is a single number.
 *
 * Batch size adapts. A hard-coded size is wrong on a 256 MB shared host and
 * wrong again on a 4 GB dedicated one, so each batch measures its own time and
 * memory and the next one is sized from that.
 *
 * Progress lives in an option, not a transient. A transient can be evicted by
 * an object cache under pressure, which would silently restart a six-hour run.
 *
 * A run belongs to a channel. The default channel writes to the live index; a
 * rebuild runs on its own channel, with its own option and its own queue hook,
 * so that building a replacement index cannot overwrite the progress of an
 * ordinary sync or be continued by the wrong instance. One FullSync per
 * destination, never one shared instance that is told where to write.
 *
 * @since 0.3.0
 */
final class FullSync {

	/**
	 * Hook the queue fires to continue a run.
	 *
	 * @var string
	 */
	public const HOOK = 'solrengine_full_sync_batch';

	/**
	 * Option holding the current run.
	 *
	 * @var string
	 */
	public const OPTION = 'solrengine_sync_run';

	/**
	 * Seconds a batch aims to take.
	 *
	 * @var int
	 */
	private const TARGET_SECONDS = 20;

	/**
	 * Fraction of the memory limit a batch may reach before shrinking.
	 *
	 * @var float
	 */
	private const MEMORY_CEILING = 0.6;

	/**
	 * Indexer.
	 *
	 * @var Indexer
	 */
	private $indexer;

	/**
	 * Job queue.
	 *
	 * @var JobQueue
	 */
	private $queue;

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Run history, or null when runs are not recorded.
	 *
	 * @var RunHistory|null
	 */
	private $history;

	/**
	 * Channel suffix. Empty for the live index.
	 *
	 * @var string
	 */
	private $channel;

	/**
	 * Index this channel writes to, for the history record.
	 *
	 * @var string
	 */
	private $target;

	/**
	 * Constructor.
	 *
	 * @param Indexer         $indexer Indexer.
	 * @param JobQueue        $queue   Job queue.
	 * @param Config          $config  Configuration.
	 * @param Logger          $logger  Logger.
	 * @param int             $site_id Site identifier.
	 * @param RunHistory|null $history Run history.
	 * @param string          $channel Channel name, empty for the live index.
	 * @param string          $target  Index this channel writes to.
	 */
	public function __construct(
		Indexer $indexer,
		JobQueue $queue,
		Config $config,
		Logger $logger,
		int $site_id,
		?RunHistory $history = null,
		string $channel = '',
		string $target = ''
	) {
		$this->indexer = $indexer;
		$this->queue   = $queue;
		$this->config  = $config;
		$this->logger  = $logger;
		$this->site_id = $site_id;
		$this->history = $history;
		$this->channel = preg_replace( '/[^a-z0-9_]/', '', strtolower( $channel ) ) ?? '';
		$this->target  = $target;
	}

	/**
	 * The option this channel stores its run in.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public function option(): string {
		return '' === $this->channel ? self::OPTION : self::OPTION . '_' . $this->channel;
	}

	/**
	 * The queue hook this channel's batches are scheduled on.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public function hook(): string {
		return '' === $this->channel ? self::HOOK : self::HOOK . '_' . $this->channel;
	}

	/**
	 * The index this channel writes to, empty when it is the live one.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public function target(): string {
		return $this->target;
	}

	/**
	 * Begin a run.
	 *
	 * @since 0.3.0
	 *
	 * @param string[] $post_types Post types to index, or empty for configured.
	 * @return Result Success carrying the run state, or a failure.
	 */
	public function start( array $post_types = array() ): Result {
		$existing = $this->state();

		if ( null !== $existing && 'running' === $existing['state'] ) {
			return Result::failure( 'already_running', __( 'A synchronisation is already running.', 'solrengine' ) );
		}

		// An explicit list — `wp solrengine index --post-type=product` — is
		// honoured as given, because somebody asked for it by name. Otherwise
		// the site's own answer, which is the same answer the save listeners
		// use: when those two disagree, content reaches the index when it is
		// edited and vanishes from it on the next full sync.
		$types = array() !== $post_types ? $post_types : IndexedTypes::all( $this->config );

		if ( array() === $types ) {
			return Result::failure( 'no_post_types', __( 'No post types are configured for indexing.', 'solrengine' ) );
		}

		$total = $this->count_total( $types );

		$run = array(
			'state'      => 'running',
			'post_types' => array_values( $types ),
			'cursor'     => 0,
			'batch_size' => max( 10, $this->config->get_int( 'index.batch_size', 100 ) ),
			'processed'  => 0,
			'written'    => 0,
			'removed'    => 0,
			'failed'     => 0,
			'total'      => $total,
			'started_at' => time(),
			'updated_at' => time(),
			'last_error' => '',
			'target'     => $this->target,
			'history_id' => null === $this->history ? 0 : $this->history->open( $this->kind(), $total, $this->target ),
		);

		$this->save( $run );

		$queued = $this->queue->enqueue( $this->hook() );

		if ( $queued->is_failure() ) {
			// The run is recorded even when the queue refuses, so the admin
			// screen can explain why nothing is happening instead of showing
			// a run that silently never starts.
			$run['state']      = 'stalled';
			$run['last_error'] = $queued->message();

			$this->save( $run );
			$this->record_end( $run );

			return $queued;
		}

		return Result::success( $run );
	}

	/**
	 * Process one batch and schedule the next.
	 *
	 * @since 0.3.0
	 *
	 * @return Result Success carrying the run state.
	 */
	public function run_batch(): Result {
		$run = $this->state();

		if ( null === $run || 'running' !== $run['state'] ) {
			return Result::failure( 'not_running', 'No synchronisation is in progress.' );
		}

		$started = microtime( true );
		$ids     = $this->next_ids( $run['post_types'], (int) $run['cursor'], (int) $run['batch_size'] );

		if ( array() === $ids ) {
			// One explicit hard commit, at the end and nowhere else. Every batch
			// above relied on the commit window, which means the last of them may
			// not be visible yet — and the next thing that happens is something
			// asking whether the run worked. A failed commit is logged and not
			// treated as a failed run: the documents are written, and Solr will
			// make them visible on its own schedule regardless.
			$committed = $this->indexer->commit();

			if ( $committed->is_failure() ) {
				$this->logger->warning(
					'Could not commit at the end of a full sync',
					array( 'code' => $committed->code() )
				);
			}

			$run['state']      = 'complete';
			$run['updated_at'] = time();

			$this->save( $run );
			$this->record_end( $run );
			$this->logger->info( 'Full synchronisation complete', array( 'processed' => $run['processed'] ) );

			/**
			 * Fires when a full synchronisation finishes.
			 *
			 * @since 0.3.0
			 *
			 * @param array<string,mixed> $run Final run state.
			 */
			do_action( 'solrengine_full_sync_complete', $run );

			return Result::success( $run );
		}

		$refs = array();

		foreach ( $ids as $id ) {
			$refs[] = new DocumentRef( $this->site_id, 'post', (int) $id );
		}

		$result = $this->indexer->index( $refs );

		if ( $result->is_ok() ) {
			$outcome           = $result->value();
			$run['written']   += $outcome->written();
			$run['removed']   += $outcome->removed();
			$run['failed']    += count( $outcome->failures() );
			$run['last_error'] = '';

			if ( null !== $this->history && $outcome->has_failures() ) {
				// Which objects failed, not just how many. "Search is missing
				// some posts" is unanswerable from a count.
				$this->history->record_failures( (int) ( $run['history_id'] ?? 0 ), $outcome->failures() );
			}
		} else {
			// A backend failure is not fatal to the run. The cursor still
			// advances, because retrying the same batch forever against a Solr
			// that rejects it would never finish; the reconciliation pass is
			// what catches what was missed.
			$run['failed']    += count( $refs );
			$run['last_error'] = $result->message();

			if ( null !== $this->history ) {
				$rejected = array();

				foreach ( $refs as $ref ) {
					$rejected[ $ref->document_id() ] = $result->message();
				}

				$this->history->record_failures( (int) ( $run['history_id'] ?? 0 ), $rejected );
			}

			$this->logger->error( 'Batch failed during full sync', array( 'code' => $result->code() ) );
		}

		$run['processed'] += count( $ids );
		$run['cursor']     = (int) max( $ids );
		$run['batch_size'] = $this->next_batch_size( (int) $run['batch_size'], microtime( true ) - $started );
		$run['updated_at'] = time();

		$this->save( $run );

		$this->free_memory();

		$queued = $this->queue->enqueue( $this->hook() );

		if ( $queued->is_failure() ) {
			$run['state']      = 'stalled';
			$run['last_error'] = $queued->message();

			$this->save( $run );
			$this->record_end( $run );
		}

		return Result::success( $run );
	}

	/**
	 * Run every remaining batch in this process.
	 *
	 * Used by WP-CLI, where there is no web-server timeout to fight and a
	 * queue round trip per batch would only add latency.
	 *
	 * @since 0.3.0
	 *
	 * @param callable|null $progress Called with the run state after each batch.
	 * @return Result Success carrying the final run state.
	 */
	public function run_to_completion( ?callable $progress = null ): Result {
		do {
			$result = $this->run_batch();

			if ( $result->is_failure() ) {
				return $result;
			}

			$run = $result->value();

			if ( null !== $progress ) {
				$progress( $run );
			}
		} while ( 'running' === $run['state'] );

		return Result::success( $run );
	}

	/**
	 * Stop the current run.
	 *
	 * @since 0.3.0
	 *
	 * @return Result
	 */
	public function cancel(): Result {
		$run = $this->state();

		if ( null === $run ) {
			return Result::failure( 'not_running', 'No synchronisation is in progress.' );
		}

		$this->queue->cancel( $this->hook() );

		$run['state']      = 'cancelled';
		$run['updated_at'] = time();

		$this->save( $run );
		$this->record_end( $run );

		return Result::success( $run );
	}

	/**
	 * Resume a stalled or cancelled run from its cursor.
	 *
	 * @since 0.3.0
	 *
	 * @return Result
	 */
	public function resume(): Result {
		$run = $this->state();

		if ( null === $run ) {
			return Result::failure( 'nothing_to_resume', 'There is no run to resume.' );
		}

		if ( 'complete' === $run['state'] ) {
			return Result::failure( 'already_complete', 'That run already finished.' );
		}

		$run['state'] = 'running';

		if ( empty( $run['history_id'] ) && null !== $this->history ) {
			// A run resumed after its history row was pruned, or after a
			// version that did not record one. Open a fresh row rather than
			// dropping the rest of the run from the record.
			$run['history_id'] = $this->history->open( $this->kind(), (int) ( $run['total'] ?? 0 ), $this->target );
		}

		$run['updated_at'] = time();

		$this->save( $run );

		return $this->queue->enqueue( $this->hook() );
	}

	/**
	 * The current run, or null when there has never been one.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,mixed>|null
	 */
	public function state(): ?array {
		$run = get_option( $this->option(), null );

		return is_array( $run ) && isset( $run['state'] ) ? $run : null;
	}

	/**
	 * Forget this channel's run state.
	 *
	 * Only a channel other than the live one is ever discarded: a rebuild that
	 * was abandoned should leave nothing behind that a later rebuild would
	 * mistake for progress.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function forget(): void {
		if ( '' === $this->channel ) {
			return;
		}

		$this->queue->cancel( $this->hook() );

		delete_option( $this->option() );
	}

	/**
	 * Identifiers after the cursor.
	 *
	 * Ordered by ID so the cursor is meaningful, and fetched as IDs only so a
	 * batch does not hydrate a thousand post objects it will not all use.
	 *
	 * @param string[] $post_types Post types.
	 * @param int      $cursor     Last identifier processed.
	 * @param int      $limit      How many to fetch.
	 * @return int[]
	 */
	private function next_ids( array $post_types, int $cursor, int $limit ): array {
		global $wpdb;

		$statuses = $this->public_statuses();

		if ( array() === $post_types || array() === $statuses ) {
			return array();
		}

		$type_slots   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_slots = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		$values = array_merge( $post_types, $statuses, array( $cursor, $limit ) );

		/*
		 * The IN() placeholder lists are generated from a count, never from any
		 * value, and every value is bound through prepare(). PHP_CodeSniffer
		 * cannot verify that statically — it sees an interpolated string and a
		 * single array argument where it expects literal placeholders — so the
		 * three sniffs below are suppressed with that reasoning rather than
		 * because the query is unsafe.
		 *
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 */
		$sql = $wpdb->prepare(
			"SELECT ID FROM {$wpdb->posts}
			 WHERE post_type IN ( {$type_slots} )
			   AND post_status IN ( {$status_slots} )
			   AND ID > %d
			 ORDER BY ID ASC
			 LIMIT %d",
			$values
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$ids = $wpdb->get_col( $sql );

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * How many objects the run will visit.
	 *
	 * Approximate by nature: content changes while a long run is in progress.
	 * It drives a progress bar, not a correctness decision.
	 *
	 * @param string[] $post_types Post types.
	 * @return int
	 */
	private function count_total( array $post_types ): int {
		global $wpdb;

		$statuses = $this->public_statuses();

		if ( array() === $post_types || array() === $statuses ) {
			return 0;
		}

		$type_slots   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_slots = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		/*
		 * The IN() placeholder lists are generated from a count, never from any
		 * value, and every value is bound through prepare(). PHP_CodeSniffer
		 * cannot verify that statically — it sees an interpolated string and a
		 * single array argument where it expects literal placeholders — so the
		 * three sniffs below are suppressed with that reasoning rather than
		 * because the query is unsafe.
		 *
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 */
		$sql = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_type IN ( {$type_slots} )
			   AND post_status IN ( {$status_slots} )",
			array_merge( $post_types, $statuses )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Statuses that are publicly visible.
	 *
	 * Read from WordPress rather than hard-coded, so a plugin registering a
	 * public custom status is included without SolrEngine knowing about it.
	 *
	 * @return string[]
	 */
	private function public_statuses(): array {
		$statuses = get_post_stati( array( 'public' => true ) );

		return array_values( array_map( 'strval', (array) $statuses ) );
	}

	/**
	 * Choose the next batch size from how the last one went.
	 *
	 * @param int   $current Current batch size.
	 * @param float $elapsed Seconds the last batch took.
	 * @return int
	 */
	private function next_batch_size( int $current, float $elapsed ): int {
		$limit = $this->memory_limit_bytes();

		if ( $limit > 0 && memory_get_usage( true ) > $limit * self::MEMORY_CEILING ) {
			return max( 10, (int) floor( $current / 2 ) );
		}

		if ( $elapsed > self::TARGET_SECONDS ) {
			return max( 10, (int) floor( $current * 0.7 ) );
		}

		if ( $elapsed < self::TARGET_SECONDS / 3 ) {
			return min( 1000, (int) ceil( $current * 1.5 ) );
		}

		return $current;
	}

	/**
	 * The PHP memory limit in bytes, or 0 when unlimited.
	 *
	 * @return int
	 */
	private function memory_limit_bytes(): int {
		$limit = ini_get( 'memory_limit' );

		if ( false === $limit || '-1' === $limit ) {
			return 0;
		}

		return (int) wp_convert_hr_to_bytes( $limit );
	}

	/**
	 * Release what the last batch accumulated.
	 *
	 * Note what this deliberately does not do: call wp_cache_flush(). On a site
	 * with a persistent object cache that evicts every other plugin's cached
	 * data too, which is a real and frequently shipped bug. Only SolrEngine's
	 * own accumulation is released.
	 *
	 * @return void
	 */
	private function free_memory(): void {
		global $wpdb, $wp_object_cache;

		$wpdb->queries = array();

		if ( is_object( $wp_object_cache ) && property_exists( $wp_object_cache, 'cache' ) ) {
			// Non-persistent caches grow without bound across a long run.
			// Persistent ones implement their own eviction and are left alone.
			if ( ! method_exists( $wp_object_cache, 'redis_status' ) && ! isset( $wp_object_cache->mc ) ) {
				$wp_object_cache->cache = array();
			}
		}
	}

	/**
	 * Persist the run state.
	 *
	 * @param array<string,mixed> $run Run state.
	 * @return void
	 */
	private function save( array $run ): void {
		update_option( $this->option(), $run, false );
	}

	/**
	 * The history kind for this channel.
	 *
	 * @return string
	 */
	private function kind(): string {
		return '' === $this->channel ? 'full' : $this->channel;
	}

	/**
	 * Close this run's history record.
	 *
	 * @param array<string,mixed> $run Run state.
	 * @return void
	 */
	private function record_end( array $run ): void {
		if ( null === $this->history ) {
			return;
		}

		$this->history->close(
			(int) ( $run['history_id'] ?? 0 ),
			(string) $run['state'],
			$run,
			(string) ( $run['last_error'] ?? '' )
		);
	}
}
