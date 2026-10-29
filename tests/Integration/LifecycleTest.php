<?php
/**
 * Deactivation and uninstall tests.
 *
 * The privacy document promises that uninstalling removes everything the
 * plugin stored locally, and that deactivating removes nothing a site owner
 * would miss. Both are only true if every hook, option and queued job is
 * accounted for, which is what these assert.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\Queue\ActionSchedulerQueue;
use SolrEngine\Indexing\QueueWorker;
use SolrEngine\Support\Lifecycle;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Support\Lifecycle
 * @covers \SolrEngine\Indexing\Queue\CronQueue::cancel
 */
final class LifecycleTest extends WP_UnitTestCase {

	public function test_cron_jobs_are_cancelled_whatever_their_arguments(): void {
		// Index jobs carry their batch. wp_clear_scheduled_hook() without the
		// same arguments matches none of them, which is the bug this pins.
		wp_schedule_single_event( time() + 60, QueueWorker::INDEX_HOOK, array( 'batch-a' ) );
		wp_schedule_single_event( time() + 60, QueueWorker::DELETE_HOOK, array( 'batch-b' ) );
		wp_schedule_single_event( time() + 60, FullSync::HOOK );

		Lifecycle::cancel_queued_work();

		foreach ( Lifecycle::hooks() as $hook ) {
			$this->assertFalse( $this->scheduled( $hook ), $hook );
		}
	}

	public function test_another_plugins_cron_jobs_are_left_alone(): void {
		wp_schedule_single_event( time() + 60, 'someone_elses_hook', array( 'x' ) );

		Lifecycle::cancel_queued_work();

		$this->assertNotFalse( wp_next_scheduled( 'someone_elses_hook', array( 'x' ) ) );

		wp_unschedule_hook( 'someone_elses_hook' );
	}

	public function test_action_scheduler_jobs_in_the_plugins_group_are_cancelled(): void {
		$this->assertTrue( function_exists( 'as_enqueue_async_action' ), 'Action Scheduler is bundled and loaded with the plugin.' );

		as_enqueue_async_action( QueueWorker::INDEX_HOOK, array( 'batch-a' ), ActionSchedulerQueue::GROUP );
		as_enqueue_async_action( 'someone_elses_hook', array(), 'someone-else' );

		Lifecycle::cancel_queued_work();

		$this->assertFalse( as_has_scheduled_action( QueueWorker::INDEX_HOOK, null, ActionSchedulerQueue::GROUP ) );
		$this->assertTrue( as_has_scheduled_action( 'someone_elses_hook', null, 'someone-else' ) );

		as_unschedule_all_actions( 'someone_elses_hook' );
	}

	public function test_deactivation_marks_a_running_sync_stalled_and_keeps_settings(): void {
		update_option( 'solrengine_connection', array( 'url' => 'http://127.0.0.1:8983/solr' ), false );
		update_option(
			FullSync::OPTION,
			array(
				'state'      => 'running',
				'processed'  => 10,
				'updated_at' => 1,
			),
			false
		);

		Lifecycle::deactivate();

		$run = get_option( FullSync::OPTION );

		$this->assertSame( 'stalled', $run['state'] );
		$this->assertSame( 10, $run['processed'] );
		$this->assertNotFalse( get_option( 'solrengine_connection' ) );
	}

	public function test_deactivation_leaves_a_finished_sync_as_it_was(): void {
		update_option( FullSync::OPTION, array( 'state' => 'complete' ), false );

		Lifecycle::deactivate();

		$this->assertSame( 'complete', get_option( FullSync::OPTION )['state'] );
	}

	public function test_uninstall_removes_the_breaker_and_queued_work(): void {
		update_option( 'solrengine_breaker', array( 'failures' => 3 ), false );
		update_option( 'solrengine_connection', array( 'url' => 'http://127.0.0.1:8983/solr' ), false );
		set_transient( 'solrengine_wc_price_ceiling', 100.0 );
		wp_schedule_single_event( time() + 60, QueueWorker::INDEX_HOOK, array( 'batch-a' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'solrengine/solrengine.php' );
		}

		require dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertFalse( get_option( 'solrengine_breaker' ) );
		$this->assertFalse( get_option( 'solrengine_connection' ) );
		$this->assertFalse( get_transient( 'solrengine_wc_price_ceiling' ) );
		$this->assertFalse( $this->scheduled( QueueWorker::INDEX_HOOK ) );
	}

	/**
	 * Whether any event for a hook is scheduled, whatever its arguments.
	 *
	 * @param string $hook Hook name.
	 * @return bool
	 */
	private function scheduled( string $hook ): bool {
		foreach ( (array) _get_cron_array() as $events ) {
			if ( isset( $events[ $hook ] ) ) {
				return true;
			}
		}

		return false;
	}
}
