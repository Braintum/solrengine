<?php
/**
 * Index run history.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Support\Tables;

/**
 * What has been indexed, when, and what went wrong.
 *
 * The one observability feature the plan's section 17.5 puts in v1 alongside
 * live status: a record of each run and the objects that failed inside it. It
 * exists because "search is missing some posts" is otherwise unanswerable — the
 * live run state holds one run, the current one, and the run that broke was the
 * one before it.
 *
 * Bounded by construction, in three ways. Only a fixed number of runs is kept;
 * only a fixed number of failures is recorded per run, because a Solr that
 * rejects every document would otherwise write one row per post on the site;
 * and messages are truncated to the column width rather than relying on MySQL
 * to do it, which in strict mode is an error rather than a truncation.
 *
 * @since 0.8.0
 */
final class RunHistory {

	/**
	 * Runs kept before the oldest are removed.
	 *
	 * @var int
	 */
	public const KEEP_RUNS = 50;

	/**
	 * Failures recorded per run.
	 *
	 * @var int
	 */
	public const KEEP_FAILURES_PER_RUN = 100;

	/**
	 * Longest message stored, matching the column width.
	 *
	 * @var int
	 */
	private const MESSAGE_LIMIT = 500;

	/**
	 * Site identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Failures already recorded this request, keyed by run.
	 *
	 * @var array<int,int>
	 */
	private $recorded = array();

	/**
	 * Constructor.
	 *
	 * @param int $site_id Site identifier.
	 */
	public function __construct( int $site_id ) {
		$this->site_id = $site_id;
	}

	/**
	 * Record the start of a run.
	 *
	 * @since 0.8.0
	 *
	 * @param string $kind   Run kind: "full", "rebuild" or "reconcile".
	 * @param int    $total  Objects the run expects to visit.
	 * @param string $target Index the run writes to, when it is not the live one.
	 * @return int Run identifier, or 0 when history is unavailable.
	 */
	public function open( string $kind, int $total = 0, string $target = '' ): int {
		global $wpdb;

		Tables::ensure();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$inserted = $wpdb->insert(
			Tables::runs(),
			array(
				'site_id'    => $this->site_id,
				'kind'       => substr( $kind, 0, 20 ),
				'state'      => 'running',
				'target'     => substr( $target, 0, 120 ),
				'started_at' => time(),
				'total'      => max( 0, $total ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d' )
		);

		if ( false === $inserted ) {
			// History is a record, not a prerequisite. A run whose history row
			// could not be written still indexes; it is simply not listed.
			return 0;
		}

		$this->prune();

		return (int) $wpdb->insert_id;
	}

	/**
	 * Record the end of a run.
	 *
	 * @since 0.8.0
	 *
	 * @param int                 $run_id  Run identifier from open().
	 * @param string              $state   Final state: complete, cancelled, stalled or failed.
	 * @param array<string,mixed> $counts  Processed, written, removed and failed counts.
	 * @param string              $message Final message, usually the last error.
	 * @return void
	 */
	public function close( int $run_id, string $state, array $counts = array(), string $message = '' ): void {
		global $wpdb;

		if ( $run_id <= 0 ) {
			return;
		}

		Tables::ensure();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			Tables::runs(),
			array(
				'state'       => substr( $state, 0, 20 ),
				'finished_at' => time(),
				'processed'   => (int) ( $counts['processed'] ?? 0 ),
				'written'     => (int) ( $counts['written'] ?? 0 ),
				'removed'     => (int) ( $counts['removed'] ?? 0 ),
				'failed'      => (int) ( $counts['failed'] ?? 0 ),
				'total'       => (int) ( $counts['total'] ?? 0 ),
				'message'     => $this->clip( $message ),
			),
			array( 'id' => $run_id ),
			array( '%s', '%d', '%d', '%d', '%d', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Record which objects failed inside a run.
	 *
	 * @since 0.8.0
	 *
	 * @param int                  $run_id   Run identifier.
	 * @param array<string,string> $failures Message keyed by document ID.
	 * @return void
	 */
	public function record_failures( int $run_id, array $failures ): void {
		global $wpdb;

		if ( $run_id <= 0 || array() === $failures ) {
			return;
		}

		Tables::ensure();

		$already = $this->recorded[ $run_id ] ?? 0;

		foreach ( $failures as $document_id => $message ) {
			if ( $already >= self::KEEP_FAILURES_PER_RUN ) {
				// The count on the run row still reports every failure. This
				// cap bounds the detail, not the tally: a backend refusing
				// every document would otherwise write a row per post.
				break;
			}

			$ref = DocumentRef::from_document_id( (string) $document_id );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->insert(
				Tables::failures(),
				array(
					'run_id'      => $run_id,
					'site_id'     => null === $ref ? $this->site_id : $ref->site_id(),
					'object_type' => null === $ref ? '' : $ref->object_type(),
					'object_id'   => null === $ref ? 0 : $ref->object_id(),
					'message'     => $this->clip( (string) $message ),
					'created_at'  => time(),
				),
				array( '%d', '%d', '%s', '%d', '%s', '%d' )
			);

			++$already;
		}

		$this->recorded[ $run_id ] = $already;
	}

	/**
	 * The most recent runs, newest first.
	 *
	 * @since 0.8.0
	 *
	 * @param int $limit How many to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 10 ): array {
		global $wpdb;

		if ( ! Tables::exist() ) {
			return array();
		}

		$table = Tables::runs();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// The table name comes from the trusted $wpdb prefix. Only the
				// bound values below come from a caller.
				"SELECT * FROM {$table} WHERE site_id = %d ORDER BY started_at DESC, id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->site_id,
				max( 1, min( 100, $limit ) )
			),
			ARRAY_A
		);

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * The failures recorded against one run, or the latest across all runs.
	 *
	 * @since 0.8.0
	 *
	 * @param int $run_id Run identifier, or 0 for the most recent failures.
	 * @param int $limit  How many to return.
	 * @return array<int,array<string,mixed>>
	 */
	public function failures( int $run_id = 0, int $limit = 25 ): array {
		global $wpdb;

		if ( ! Tables::exist() ) {
			return array();
		}

		$table = Tables::failures();
		$limit = max( 1, min( 200, $limit ) );

		if ( $run_id > 0 ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE run_id = %d AND site_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$run_id,
					$this->site_id,
					$limit
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE site_id = %d ORDER BY id DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$this->site_id,
					$limit
				),
				ARRAY_A
			);
		}

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Remove old runs and the failures belonging to them.
	 *
	 * Called on every open(), so the tables are pruned by use rather than by a
	 * scheduled task that a site with WP-Cron disabled would never run.
	 *
	 * @since 0.8.0
	 *
	 * @param int $keep Runs to keep per site.
	 * @return int Runs removed.
	 */
	public function prune( int $keep = self::KEEP_RUNS ): int {
		global $wpdb;

		$runs  = Tables::runs();
		$fails = Tables::failures();
		$keep  = max( 1, $keep );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cutoff = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$runs} WHERE site_id = %d ORDER BY id DESC LIMIT 1 OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->site_id,
				$keep
			)
		);

		if ( null === $cutoff ) {
			return 0;
		}

		// Failures first. Deleting the runs first would orphan them, and an
		// orphaned failure row has no timestamp anybody looks at, so it would
		// stay in the table until the plugin was deleted.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$fails} WHERE site_id = %d AND run_id <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->site_id,
				(int) $cutoff
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$removed = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$runs} WHERE site_id = %d AND id <= %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$this->site_id,
				(int) $cutoff
			)
		);

		return is_numeric( $removed ) ? (int) $removed : 0;
	}

	/**
	 * Truncate a message to the stored width.
	 *
	 * Done here rather than left to MySQL: in strict mode an over-long value is
	 * an error that would lose the whole row, so the record of a failure would
	 * itself fail.
	 *
	 * @param string $message Message.
	 * @return string
	 */
	private function clip( string $message ): string {
		// Characters, not bytes: the column is declared varchar(500), which
		// MySQL counts in characters, and a byte-wise cut would split a
		// multibyte one and store invalid UTF-8.
		if ( mb_strlen( $message ) <= self::MESSAGE_LIMIT ) {
			return $message;
		}

		return mb_substr( $message, 0, self::MESSAGE_LIMIT - 1 ) . "\u{2026}";
	}
}
