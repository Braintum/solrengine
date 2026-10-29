<?php
/**
 * What deactivation and uninstall do to queued work.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use ActionScheduler;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\Queue\ActionSchedulerQueue;
use SolrEngine\Indexing\QueueWorker;
use SolrEngine\Indexing\Rebuild;
use SolrEngine\Indexing\Reconciler;

/**
 * Stops SolrEngine's background work, and only SolrEngine's.
 *
 * One list of the hooks the plugin schedules, shared by deactivation and
 * uninstall, so that a hook added later cannot be cleaned up by one and missed
 * by the other.
 *
 * Work is queued in one of two places. WP-Cron events are removed by hook.
 * Action Scheduler jobs are cancelled by group: every job SolrEngine queues
 * there carries the `solrengine` group, so the group is exactly the set that
 * belongs to this plugin. Action Scheduler's tables are shared with WooCommerce
 * and anything else that bundles it, and are never dropped or emptied.
 *
 * @since 1.0.0
 */
final class Lifecycle {

	/**
	 * Every WP-Cron hook SolrEngine schedules.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function hooks(): array {
		return array(
			Reconciler::HOOK,
			QueueWorker::INDEX_HOOK,
			QueueWorker::DELETE_HOOK,
			FullSync::HOOK,
			FullSync::HOOK . '_' . Rebuild::CHANNEL,
		);
	}

	/**
	 * The options holding a full sync's progress, one per channel.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public static function run_options(): array {
		return array(
			FullSync::OPTION,
			FullSync::OPTION . '_' . Rebuild::CHANNEL,
		);
	}

	/**
	 * On deactivation: stop queued work and leave everything else.
	 *
	 * Pending jobs would not survive deactivation anyway. WP-Cron fires them
	 * into a hook with no listener, and an Action Scheduler loaded by another
	 * plugin marks them failed for want of a callback. Cancelling them is the
	 * same outcome without the noise.
	 *
	 * A full sync that was running is marked stalled rather than left saying
	 * "running" with nothing behind it, so after reactivation the dashboard
	 * reports it and "Resume" picks it up from its cursor. Settings, history
	 * and the remote index are untouched: deactivation is routinely used for
	 * debugging, and removing data is uninstall's job.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function deactivate(): void {
		self::cancel_queued_work();

		foreach ( self::run_options() as $option ) {
			$run = get_option( $option, null );

			if ( is_array( $run ) && 'running' === ( $run['state'] ?? '' ) ) {
				$run['state']      = 'stalled';
				$run['updated_at'] = time();

				update_option( $option, $run, false );
			}
		}
	}

	/**
	 * Remove every pending job this plugin queued, on the current site.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public static function cancel_queued_work(): void {
		foreach ( self::hooks() as $hook ) {
			// Every event for the hook, whatever its arguments. Index jobs carry
			// their batch, and wp_clear_scheduled_hook() would miss them all.
			wp_unschedule_hook( $hook );
		}

		self::cancel_action_scheduler_jobs();
	}

	/**
	 * Cancel this plugin's Action Scheduler jobs.
	 *
	 * Through Action Scheduler's own API when it is running. During uninstall
	 * it usually is not — the plugin that bundles it is the one being deleted —
	 * so the pending rows are marked cancelled directly, which is what the API
	 * does itself. Rows are never deleted, and other groups are never touched.
	 *
	 * @return void
	 */
	private static function cancel_action_scheduler_jobs(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) && class_exists( ActionScheduler::class ) && ActionScheduler::is_initialized() ) {
			as_unschedule_all_actions( '', array(), ActionSchedulerQueue::GROUP );

			return;
		}

		global $wpdb;

		$actions = $wpdb->prefix . 'actionscheduler_actions';
		$groups  = $wpdb->prefix . 'actionscheduler_groups';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Checking for a table during uninstall; nothing to cache.
		if ( $actions !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $actions ) ) ) ) {
			// Action Scheduler never ran on this site, so nothing was queued there.
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Action Scheduler is not loaded, so its API cannot be used; same update its store makes.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i AS a INNER JOIN %i AS g ON a.group_id = g.group_id SET a.status = %s WHERE g.slug = %s AND a.status = %s',
				$actions,
				$groups,
				'canceled',
				ActionSchedulerQueue::GROUP,
				'pending'
			)
		);
	}
}
