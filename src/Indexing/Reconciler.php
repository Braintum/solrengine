<?php
/**
 * Detects drift between WordPress and the index.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\Logger;
use SolrEngine\Contracts\Result;
use SolrEngine\Support\Config;

/**
 * Compares what WordPress has against what the index holds.
 *
 * Indexes drift. A crashed worker, a direct database import, a restored
 * backup, a manually emptied collection, a run of jobs that failed while Solr
 * was down. None of these announce themselves, and without a check the first
 * sign is a customer noticing their content is missing from search — by which
 * point the trust is gone.
 *
 * Deliberately cheap. It compares counts, which is one query on each side, and
 * reports a discrepancy rather than attempting a repair. Repair is a full sync,
 * and starting one automatically because two numbers disagreed would be a
 * surprising thing for a plugin to do to a large site.
 *
 * @since 0.4.0
 */
final class Reconciler {

	/**
	 * Hook the scheduler fires.
	 *
	 * @var string
	 */
	public const HOOK = 'solrengine_reconcile';

	/**
	 * Option holding the most recent report.
	 *
	 * @var string
	 */
	public const OPTION = 'solrengine_reconciliation';

	/**
	 * Proportion of documents that may be missing before it is reported.
	 *
	 * Not zero. A count taken from two systems at slightly different moments
	 * will differ on any busy site, and reporting every transient difference
	 * would train an administrator to ignore the warning.
	 *
	 * @var float
	 */
	private const TOLERANCE = 0.02;

	/**
	 * Backend registry.
	 *
	 * @var BackendRegistry
	 */
	private $backends;

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
	 * Constructor.
	 *
	 * @param BackendRegistry $backends Backend registry.
	 * @param Config          $config   Configuration.
	 * @param Logger          $logger   Logger.
	 */
	public function __construct( BackendRegistry $backends, Config $config, Logger $logger ) {
		$this->backends = $backends;
		$this->config   = $config;
		$this->logger   = $logger;
	}

	/**
	 * Compare the two sides and record what was found.
	 *
	 * @since 0.4.0
	 *
	 * @return Result Success carrying the report, or a failure.
	 */
	public function run(): Result {
		$backend = $this->backends->index();

		if ( null === $backend ) {
			return Result::failure( 'no_backend', 'No index backend is configured.' );
		}

		$status = $backend->status();

		if ( $status->is_failure() ) {
			return $status;
		}

		$index = $status->value();

		if ( ! $index instanceof IndexStatus ) {
			return Result::failure( 'bad_status', 'The backend returned an unusable status.' );
		}

		$expected = $this->expected_count();
		$actual   = $index->document_count();
		$missing  = max( 0, $expected - $actual );

		$report = array(
			'checked_at' => time(),
			'expected'   => $expected,
			'actual'     => $actual,
			'missing'    => $missing,
			'extra'      => max( 0, $actual - $expected ),
			'drifted'    => $this->has_drifted( $expected, $actual ),
		);

		update_option( self::OPTION, $report, false );

		if ( $report['drifted'] ) {
			$this->logger->warning( 'Index drift detected', $report );

			/**
			 * Fires when the index and WordPress disagree beyond tolerance.
			 *
			 * @since 0.4.0
			 *
			 * @param array<string,mixed> $report What was found.
			 */
			do_action( 'solrengine_drift_detected', $report );
		}

		return Result::success( $report );
	}

	/**
	 * The most recent report, or null when none has run.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,mixed>|null
	 */
	public function last_report(): ?array {
		$report = get_option( self::OPTION, null );

		return is_array( $report ) ? $report : null;
	}

	/**
	 * Whether the difference is worth reporting.
	 *
	 * @param int $expected Expected documents.
	 * @param int $actual   Documents present.
	 * @return bool
	 */
	private function has_drifted( int $expected, int $actual ): bool {
		if ( 0 === $expected ) {
			// Nothing should be indexed. Anything present is stale, and the
			// proportional test below cannot express that.
			return $actual > 0;
		}

		return abs( $expected - $actual ) / $expected > self::TOLERANCE;
	}

	/**
	 * How many objects WordPress thinks should be indexed.
	 *
	 * @return int
	 */
	private function expected_count(): int {
		global $wpdb;

		$post_types = array_map( 'strval', $this->config->get_array( 'index.post_types', array( 'post', 'page' ) ) );
		$statuses   = array_values( array_map( 'strval', (array) get_post_stati( array( 'public' => true ) ) ) );

		if ( array() === $post_types || array() === $statuses ) {
			return 0;
		}

		$type_slots   = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_slots = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

		/*
		 * The IN() placeholder lists are generated from a count, never from a
		 * value, and every value is bound. PHP_CodeSniffer cannot verify that
		 * statically, so the sniffs below are suppressed with that reasoning
		 * rather than because the query is unsafe.
		 *
		 * phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		 * phpcs:disable WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		 *
		 * Password-protected posts are counted. The document builder indexes
		 * them as metadata with their content withheld, so excluding them here
		 * would report drift on every site that has one.
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
}
