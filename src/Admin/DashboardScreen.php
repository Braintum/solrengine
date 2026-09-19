<?php
/**
 * System health dashboard.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Admin;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\Reconciler;
use SolrEngine\Rest\RateLimiter;
use SolrEngine\Support\Config;
use SolrEngine\Support\Container;

/**
 * Tells an administrator whether search is working.
 *
 * Every number links to the thing that explains it, and every state that could
 * be quietly wrong is named rather than implied. Three in particular:
 *
 * - A queue driver that cannot run reports itself, because a stalled queue and
 *   an empty one look identical from the outside.
 * - Search being switched off is shown as a state, not an absence, so an
 *   administrator who has indexed everything and wonders why nothing changed
 *   has somewhere to look.
 * - Rate limiting that cannot work without a persistent object cache says so,
 *   rather than implying protection that is not there.
 *
 * @since 0.4.0
 */
final class DashboardScreen {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	public const SLUG = 'solrengine-dashboard';

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container Service container.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Add the menu entry.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function add_menu(): void {
		add_submenu_page(
			ConnectionScreen::SLUG,
			__( 'Dashboard', 'solrengine' ),
			__( 'Dashboard', 'solrengine' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Render the screen.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$config = $this->container->get( 'config' );

		$backends = $this->container->get( 'backends' );

		$queue = $this->container->get( 'indexing.queue' );

		$sync       = $this->container->get( 'indexing.full_sync' );
		$reconciler = $this->container->get( 'indexing.reconciler' );
		$limiter    = $this->container->get( 'rest.rate_limiter' );

		$documents = null;
		$index     = $backends->index();

		if ( null !== $index ) {
			$status = $index->status();
			$value  = $status->is_ok() ? $status->value() : null;

			$documents = $value instanceof IndexStatus ? $value->document_count() : null;
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'SolrEngine', 'solrengine' ); ?></h1>

			<table class="widefat striped" style="max-width:820px">
				<tbody>
					<?php
					$this->row(
						__( 'Search', 'solrengine' ),
						$config->get_bool( 'search.enabled' )
							? __( 'Active. WordPress search is answered by SolrEngine.', 'solrengine' )
							: __( 'Off. WordPress is answering searches itself. Turn this on once indexing has finished.', 'solrengine' ),
						$config->get_bool( 'search.enabled' ) ? 'good' : 'idle'
					);

					$this->row(
						__( 'Backend', 'solrengine' ),
						null === $index
							? __( 'Not reachable. Check the connection settings.', 'solrengine' )
							: sprintf(
								/* translators: %s: backend identifier. */
								__( 'Connected via %s', 'solrengine' ),
								$backends->selected_id()
							),
						null === $index ? 'bad' : 'good'
					);

					$this->row(
						__( 'Documents', 'solrengine' ),
						null === $documents
							? __( 'Unknown', 'solrengine' )
							: number_format_i18n( $documents ),
						null === $documents ? 'idle' : 'good'
					);

					$this->row(
						__( 'Schema', 'solrengine' ),
						sprintf(
							/* translators: %d: schema contract version. */
							__( 'Contract version %d required', 'solrengine' ),
							SOLRENGINE_SCHEMA_CONTRACT_VERSION
						),
						'idle'
					);

					$this->render_queue_row( $queue );
					$this->render_sync_row( $sync );
					$this->render_drift_row( $reconciler );
					$this->render_limiter_row( $limiter );
					?>
				</tbody>
			</table>

			<p style="margin-top:1.5em">
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . ConnectionScreen::SLUG ) ); ?>">
					<?php esc_html_e( 'Connection settings', 'solrengine' ); ?>
				</a>
			</p>

			<h2><?php esc_html_e( 'Indexing', 'solrengine' ); ?></h2>
			<p>
				<?php esc_html_e( 'Indexing is run from the command line, where there is no web-server timeout to fight:', 'solrengine' ); ?>
			</p>
			<pre class="code" style="padding:1em;background:#f6f7f7;max-width:820px"><code>wp solrengine index
wp solrengine index progress
wp solrengine index resume</code></pre>
		</div>
		<?php
	}

	/**
	 * Render the queue row.
	 *
	 * @param mixed $queue Queue service.
	 * @return void
	 */
	private function render_queue_row( $queue ): void {
		if ( ! $queue instanceof JobQueue ) {
			$this->row( __( 'Queue', 'solrengine' ), __( 'Unavailable', 'solrengine' ), 'bad' );

			return;
		}

		$driver = ( new \ReflectionClass( $queue ) )->getShortName();
		$is_as  = false !== strpos( $driver, 'ActionScheduler' );

		if ( ! $queue->is_available() ) {
			// Named explicitly. A queue that cannot run looks exactly like an
			// empty one, and an administrator would otherwise wait for jobs
			// that will never start.
			$this->row(
				__( 'Queue', 'solrengine' ),
				__( 'Not running. Indexing jobs will not be processed. If WP-Cron is disabled on this site, run indexing from the command line instead.', 'solrengine' ),
				'bad'
			);

			return;
		}

		$this->row(
			__( 'Queue', 'solrengine' ),
			sprintf(
				/* translators: 1: number of pending jobs, 2: extra note. */
				__( '%1$s pending. %2$s', 'solrengine' ),
				number_format_i18n( $queue->pending_count() ),
				$is_as
					? __( 'Using Action Scheduler.', 'solrengine' )
					: __( 'Using WP-Cron, which has weaker guarantees: jobs can run twice and only run when the site is visited. Indexing is safe to repeat, so this costs time rather than correctness.', 'solrengine' )
			),
			$is_as ? 'good' : 'warn'
		);
	}

	/**
	 * Render the synchronisation row.
	 *
	 * @param mixed $sync Full sync service.
	 * @return void
	 */
	private function render_sync_row( $sync ): void {
		if ( ! $sync instanceof FullSync ) {
			return;
		}

		$run = $sync->state();

		if ( null === $run ) {
			$this->row(
				__( 'Last full index', 'solrengine' ),
				__( 'Never run. Nothing is in the index yet.', 'solrengine' ),
				'warn'
			);

			return;
		}

		$this->row(
			__( 'Last full index', 'solrengine' ),
			sprintf(
				/* translators: 1: state, 2: processed count, 3: failure count, 4: human time difference. */
				__( '%1$s — %2$s processed, %3$s failed, %4$s ago', 'solrengine' ),
				esc_html( (string) $run['state'] ),
				number_format_i18n( (int) $run['processed'] ),
				number_format_i18n( (int) $run['failed'] ),
				human_time_diff( (int) $run['updated_at'] )
			),
			'complete' === $run['state'] ? 'good' : ( 'running' === $run['state'] ? 'idle' : 'warn' )
		);
	}

	/**
	 * Render the drift row.
	 *
	 * @param mixed $reconciler Reconciler service.
	 * @return void
	 */
	private function render_drift_row( $reconciler ): void {
		if ( ! $reconciler instanceof Reconciler ) {
			return;
		}

		$report = $reconciler->last_report();

		if ( null === $report ) {
			return;
		}

		$this->row(
			__( 'Index accuracy', 'solrengine' ),
			empty( $report['drifted'] )
				? sprintf(
					/* translators: 1: indexed count, 2: expected count. */
					__( 'Matches WordPress: %1$s indexed of %2$s expected.', 'solrengine' ),
					number_format_i18n( (int) $report['actual'] ),
					number_format_i18n( (int) $report['expected'] )
				)
				: sprintf(
					/* translators: 1: indexed count, 2: expected count. */
					__( 'Out of step: %1$s indexed, %2$s expected. Run a full index to repair it.', 'solrengine' ),
					number_format_i18n( (int) $report['actual'] ),
					number_format_i18n( (int) $report['expected'] )
				),
			empty( $report['drifted'] ) ? 'good' : 'warn'
		);
	}

	/**
	 * Render the rate limiter row.
	 *
	 * @param mixed $limiter Rate limiter.
	 * @return void
	 */
	private function render_limiter_row( $limiter ): void {
		if ( ! $limiter instanceof RateLimiter || $limiter->is_effective() ) {
			return;
		}

		// Only shown when it cannot work. Claiming protection that is absent
		// would be worse than saying nothing.
		$this->row(
			__( 'Search API limits', 'solrengine' ),
			__( 'Not enforced. Rate limiting needs a persistent object cache. Limit requests at your CDN or web server instead.', 'solrengine' ),
			'warn'
		);
	}

	/**
	 * Render one row.
	 *
	 * @param string $label  Row label.
	 * @param string $detail Row detail.
	 * @param string $state  One of good, warn, bad or idle.
	 * @return void
	 */
	private function row( string $label, string $detail, string $state ): void {
		$colours = array(
			'good' => '#008a20',
			'warn' => '#bd8600',
			'bad'  => '#d63638',
			'idle' => '#646970',
		);

		printf(
			'<tr><td style="width:2em;color:%s">&#9679;</td><td style="width:14em"><strong>%s</strong></td><td>%s</td></tr>',
			esc_attr( $colours[ $state ] ?? '#646970' ),
			esc_html( $label ),
			esc_html( $detail )
		);
	}
}
