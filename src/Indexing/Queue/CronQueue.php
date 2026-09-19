<?php
/**
 * WP-Cron job queue driver.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing\Queue;

use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Result;

/**
 * Runs jobs through WP-Cron.
 *
 * The fallback when Action Scheduler is absent, so that a site without
 * WooCommerce and without the standalone library still indexes.
 *
 * Honestly weaker than the Action Scheduler driver, and the admin dashboard
 * says so: WP-Cron has no claim isolation, so two overlapping requests can run
 * the same job twice, and it only runs when somebody visits the site. Indexing
 * is idempotent — every write is an upsert keyed on a stable document ID — so
 * a duplicate run costs time rather than correctness, which is what makes this
 * fallback acceptable rather than dangerous.
 *
 * @since 0.3.0
 */
final class CronQueue implements JobQueue {

	/**
	 * {@inheritDoc}
	 *
	 * WP-Cron can be disabled outright, in which case nothing will ever run and
	 * the dashboard needs to say so rather than showing a queue that silently
	 * never drains.
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                  $hook Hook to fire.
	 * @param array<int|string,mixed> $args Hook arguments.
	 * @return Result
	 */
	public function enqueue( string $hook, array $args = array() ): Result {
		return $this->enqueue_after( 1, $hook, $args );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int                     $delay_seconds Seconds to wait.
	 * @param string                  $hook          Hook to fire.
	 * @param array<int|string,mixed> $args          Hook arguments.
	 * @return Result
	 */
	public function enqueue_after( int $delay_seconds, string $hook, array $args = array() ): Result {
		$values = array_values( $args );

		if ( false !== wp_next_scheduled( $hook, $values ) ) {
			return Result::success( 0 );
		}

		$scheduled = wp_schedule_single_event( time() + max( 1, $delay_seconds ), $hook, $values );

		return true === $scheduled
			? Result::success( 0 )
			: Result::failure( 'enqueue_failed', 'WP-Cron refused the job.' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Argument filter.
	 * @return Result
	 */
	public function cancel( string $hook, array $args = array() ): Result {
		if ( array() === $args ) {
			wp_clear_scheduled_hook( $hook );

			return Result::success();
		}

		wp_clear_scheduled_hook( $hook, array_values( $args ) );

		return Result::success();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $hook Optional hook name.
	 * @return int
	 */
	public function pending_count( string $hook = '' ): int {
		$cron = _get_cron_array();

		if ( ! is_array( $cron ) ) {
			return 0;
		}

		$count = 0;

		foreach ( $cron as $events ) {
			foreach ( (array) $events as $name => $instances ) {
				if ( 0 !== strpos( (string) $name, 'solrengine_' ) ) {
					continue;
				}

				if ( '' !== $hook && $name !== $hook ) {
					continue;
				}

				$count += count( (array) $instances );
			}
		}

		return $count;
	}
}
