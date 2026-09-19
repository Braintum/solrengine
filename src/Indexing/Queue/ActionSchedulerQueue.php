<?php
/**
 * Action Scheduler job queue driver.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing\Queue;

use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Result;

/**
 * Runs jobs through Action Scheduler.
 *
 * The preferred driver (ADR-004). Action Scheduler is a mature job queue built
 * for distribution inside plugins, with retries, claim-based concurrency, an
 * admin interface and logging, and it is already present on every WooCommerce
 * site.
 *
 * It is reached only through the JobQueue contract, so a custom table driver or
 * a hosted worker can replace it later without touching a caller. That
 * replaceability is what makes bundling a dependency acceptable at all under
 * Principle 10.
 *
 * Defensive about its own availability. Action Scheduler negotiates versions
 * between every plugin that ships it, so the copy that wins may be older or
 * newer than expected, and the caller degrades rather than fataling if the
 * expected functions are absent.
 *
 * @since 0.3.0
 */
final class ActionSchedulerQueue implements JobQueue {

	/**
	 * Group name, so SolrEngine's jobs are distinguishable in the admin UI and
	 * cancellable as a set without touching another plugin's work.
	 *
	 * @var string
	 */
	public const GROUP = 'solrengine';

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return function_exists( 'as_enqueue_async_action' )
			&& function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                  $hook Hook to fire.
	 * @param array<int|string,mixed> $args Hook arguments.
	 * @return Result
	 */
	public function enqueue( string $hook, array $args = array() ): Result {
		if ( ! $this->is_available() ) {
			return Result::failure( 'queue_unavailable', 'Action Scheduler is not available.' );
		}

		if ( as_has_scheduled_action( $hook, $args, self::GROUP ) ) {
			// Already pending with identical arguments. Enqueuing again would
			// index the same object twice for one change, which on a bulk
			// operation multiplies into thousands of redundant jobs.
			return Result::success( 0 );
		}

		$id = as_enqueue_async_action( $hook, $args, self::GROUP );

		return 0 === $id
			? Result::failure( 'enqueue_failed', 'Action Scheduler refused the job.' )
			: Result::success( $id );
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
		if ( ! $this->is_available() ) {
			return Result::failure( 'queue_unavailable', 'Action Scheduler is not available.' );
		}

		$id = as_schedule_single_action( time() + max( 1, $delay_seconds ), $hook, $args, self::GROUP );

		return 0 === $id
			? Result::failure( 'enqueue_failed', 'Action Scheduler refused the job.' )
			: Result::success( $id );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Argument filter.
	 * @return Result
	 */
	public function cancel( string $hook, array $args = array() ): Result {
		if ( ! $this->is_available() ) {
			return Result::failure( 'queue_unavailable', 'Action Scheduler is not available.' );
		}

		as_unschedule_all_actions( $hook, $args, self::GROUP );

		return Result::success();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $hook Optional hook name.
	 * @return int
	 */
	public function pending_count( string $hook = '' ): int {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return 0;
		}

		$query = array(
			'group'    => self::GROUP,
			'status'   => 'pending',
			'per_page' => -1,
		);

		if ( '' !== $hook ) {
			$query['hook'] = $hook;
		}

		$actions = as_get_scheduled_actions( $query, 'ids' );

		return is_array( $actions ) ? count( $actions ) : 0;
	}
}
