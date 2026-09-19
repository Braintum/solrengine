<?php
/**
 * Background job queue contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Defers work out of the current request.
 *
 * Action Scheduler is the Phase 2 driver, but nothing outside the driver may
 * know that (ADR-004). The contract keeps a custom table driver, a WP-CLI-only
 * driver or a hosted worker available later without touching a single caller,
 * which is what makes adopting a bundled dependency acceptable under
 * Principle 10.
 *
 * Implementations must be safe to call when the underlying queue is missing or
 * broken: is_available() reports it, and the caller degrades rather than
 * fatals.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
interface JobQueue {

	/**
	 * Whether the queue can accept and run work right now.
	 *
	 * False when the driver's dependency is absent, or when the scheduler that
	 * drives it is not running. The admin dashboard surfaces this, because a
	 * silently stalled queue looks identical to an empty one.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_available(): bool;

	/**
	 * Schedule a job to run as soon as possible.
	 *
	 * Implementations must deduplicate: enqueuing the same hook with the same
	 * arguments while an identical job is still pending replaces it rather than
	 * adding a second. Without that, a bulk edit of five hundred posts becomes
	 * thousands of jobs.
	 *
	 * @since 0.1.0
	 *
	 * @param string                  $hook Hook name to fire. Must be prefixed
	 *                                      "solrengine_".
	 * @param array<int|string,mixed> $args Arguments passed to the hook. Must be
	 *                                      scalars or arrays of scalars, because
	 *                                      drivers serialise them.
	 * @return Result Success carrying the driver's job identifier, or a failure.
	 */
	public function enqueue( string $hook, array $args = array() ): Result;

	/**
	 * Schedule a job to run after a delay.
	 *
	 * Used for retry backoff.
	 *
	 * @since 0.1.0
	 *
	 * @param int                     $delay_seconds Seconds to wait.
	 * @param string                  $hook          Hook name to fire.
	 * @param array<int|string,mixed> $args          Arguments passed to the hook.
	 * @return Result Success carrying the driver's job identifier, or a failure.
	 */
	public function enqueue_after( int $delay_seconds, string $hook, array $args = array() ): Result;

	/**
	 * Cancel every pending job for a hook.
	 *
	 * @since 0.1.0
	 *
	 * @param string                  $hook Hook name.
	 * @param array<int|string,mixed> $args Optional argument filter. An empty
	 *                                      array cancels every job for the hook.
	 * @return Result Success carrying the number cancelled, or a failure.
	 */
	public function cancel( string $hook, array $args = array() ): Result;

	/**
	 * Count pending jobs.
	 *
	 * @since 0.1.0
	 *
	 * @param string $hook Optional hook name. Empty counts every SolrEngine job.
	 * @return int
	 */
	public function pending_count( string $hook = '' ): int;
}
