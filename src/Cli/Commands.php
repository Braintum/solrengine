<?php
/**
 * WP-CLI commands.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Cli;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Diagnostics\ConnectionDiagnostic;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Support\Container;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaExporter;
use WP_CLI;

/**
 * Manage SolrEngine from the command line.
 *
 * The command line matters more here than for most plugins. Diagnosing a Solr
 * connection over SSH beats clicking through wp-admin, and later phases will
 * index large sites from here, where there is no web-server timeout to fight.
 *
 * Every command reports the same facts as the admin screen, because both read
 * the same diagnostic.
 *
 * @since 0.1.0
 */
final class Commands {

	/**
	 * Connection diagnostic.
	 *
	 * @var ConnectionDiagnostic
	 */
	private $diagnostic;

	/**
	 * Backend registry.
	 *
	 * @var BackendRegistry
	 */
	private $backends;

	/**
	 * Service container, for services that may not be constructible.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Contract registry.
	 *
	 * @var ContractRegistry
	 */
	private $contracts;

	/**
	 * Schema exporter.
	 *
	 * @var SchemaExporter
	 */
	private $exporter;

	/**
	 * Constructor.
	 *
	 * @param ConnectionDiagnostic $diagnostic Connection diagnostic.
	 * @param BackendRegistry      $backends   Backend registry.
	 * @param ContractRegistry     $contracts  Contract registry.
	 * @param SchemaExporter       $exporter   Schema exporter.
	 * @param Container            $container  Service container.
	 */
	public function __construct(
		ConnectionDiagnostic $diagnostic,
		BackendRegistry $backends,
		ContractRegistry $contracts,
		SchemaExporter $exporter,
		Container $container
	) {
		$this->diagnostic = $diagnostic;
		$this->backends   = $backends;
		$this->contracts  = $contracts;
		$this->exporter   = $exporter;
		$this->container  = $container;
	}

	/**
	 * Test the connection to the search backend.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine test
	 *     wp solrengine test --format=json
	 *
	 * @since 0.1.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function test( array $args, array $assoc_args ): void {
		unset( $args );

		$report = $this->diagnostic->run();

		if ( 'json' === ( $assoc_args['format'] ?? 'table' ) ) {
			WP_CLI::line( (string) wp_json_encode( $report->to_array(), JSON_PRETTY_PRINT ) );
		} else {
			$rows = array();

			foreach ( $report->checks() as $check ) {
				$rows[] = array(
					'check'  => $check['label'],
					'status' => strtoupper( (string) $check['status'] ),
					'detail' => $check['detail'],
				);
			}

			WP_CLI\Utils\format_items( 'table', $rows, array( 'check', 'status', 'detail' ) );
		}

		if ( ! $report->is_usable() ) {
			$failure = $report->first_failure();

			WP_CLI::error(
				null === $failure
					? 'No connection is configured.'
					: (string) $failure['detail']
			);
		}

		WP_CLI::success( 'The connection is usable.' );
	}

	/**
	 * Show index status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine status
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function status(): void {
		$backend = $this->backends->index();

		if ( null === $backend ) {
			WP_CLI::error( 'No backend is configured.' );
		}

		$status = $backend->status();

		if ( $status->is_failure() ) {
			WP_CLI::error( sprintf( '%s (%s)', $status->message(), $status->code() ) );
		}

		$index = $status->value();

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'property' => 'Backend',
					'value'    => $backend->id(),
				),
				array(
					'property' => 'Documents',
					'value'    => number_format_i18n( $index->document_count() ),
				),
				array(
					'property' => 'Schema contract',
					'value'    => (string) $index->schema_version(),
				),
				array(
					'property' => 'Plugin version',
					'value'    => SOLRENGINE_VERSION,
				),
			),
			array( 'property', 'value' )
		);
	}

	/**
	 * Inspect, export or apply the schema contract.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - check
	 *   - export
	 *   - apply
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine schema check
	 *     wp solrengine schema export > schema-fragment.xml
	 *     wp solrengine schema apply
	 *
	 * @since 0.1.0
	 *
	 * @param array<int,string> $args Positional arguments.
	 * @return void
	 */
	public function schema( array $args ): void {
		$action = $args[0] ?? 'check';

		if ( 'export' === $action ) {
			WP_CLI::line( $this->exporter->to_xml( $this->contracts->current() ) );

			return;
		}

		$backend = $this->backends->schema();

		if ( null === $backend ) {
			WP_CLI::error( 'This backend does not expose schema management. Use "wp solrengine schema export" and apply the result by hand.' );

			return;
		}

		$planned = $backend->plan( $this->contracts->current() );

		if ( $planned->is_failure() ) {
			WP_CLI::error( sprintf( '%s (%s)', $planned->message(), $planned->code() ) );
		}

		$plan = $planned->value();

		foreach ( $plan->blockers() as $blocker ) {
			WP_CLI::warning( $blocker );
		}

		if ( $plan->is_empty() ) {
			WP_CLI::success(
				sprintf( 'The schema already satisfies contract version %d.', $plan->target_version() )
			);

			return;
		}

		foreach ( $plan->describe() as $line ) {
			WP_CLI::line( '  ' . $line );
		}

		if ( 'check' === $action ) {
			WP_CLI::line( '' );
			WP_CLI::line( sprintf( '%d change(s) pending. Run "wp solrengine schema apply" to make them.', count( $plan->operations() ) ) );

			return;
		}

		if ( ! $plan->is_applicable() ) {
			WP_CLI::error( 'The plan cannot be applied. Resolve the warnings above, or apply the exported schema by hand.' );
		}

		WP_CLI::confirm( sprintf( 'Apply %d change(s) to your Solr schema?', count( $plan->operations() ) ) );

		$applied = $backend->apply( $plan );

		if ( $applied->is_failure() ) {
			WP_CLI::error( $applied->message() );
		}

		WP_CLI::success( sprintf( 'Applied %d change(s).', (int) $applied->value( 0 ) ) );

		if ( $plan->requires_reindex() ) {
			WP_CLI::warning(
				'Solr does not update documents that are already indexed, so existing content will not use the new fields until it is indexed again.'
			);
		}
	}

	/**
	 * Index content.
	 *
	 * Runs every batch in this process rather than through the queue. There is
	 * no web-server timeout here, and a queue round trip per batch would only
	 * add latency to a job nobody is waiting on interactively.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : What to do.
	 * ---
	 * default: start
	 * options:
	 *   - start
	 *   - resume
	 *   - cancel
	 *   - progress
	 * ---
	 *
	 * [--post-type=<types>]
	 * : Comma-separated post types. Defaults to the configured list.
	 *
	 * [--yes]
	 * : Do not prompt for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine index
	 *     wp solrengine index --post-type=post,page
	 *     wp solrengine index progress
	 *     wp solrengine index cancel
	 *
	 * @since 0.3.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function index( array $args, array $assoc_args ): void {
		$sync = $this->container->get( 'indexing.full_sync' );

		if ( ! $sync instanceof FullSync ) {
			WP_CLI::error( 'No usable backend. Run "wp solrengine test" to see why.' );

			return;
		}

		$action = $args[0] ?? 'start';

		if ( 'progress' === $action ) {
			$this->report_progress( $sync );

			return;
		}

		if ( 'cancel' === $action ) {
			$result = $sync->cancel();

			if ( $result->is_failure() ) {
				WP_CLI::error( $result->message() );
			}

			WP_CLI::success( 'Cancelled.' );

			return;
		}

		if ( 'resume' === $action ) {
			$state = $sync->state();

			if ( null === $state ) {
				WP_CLI::error( 'There is no run to resume.' );

				return;
			}

			WP_CLI::log( sprintf( 'Resuming from ID %d.', (int) $state['cursor'] ) );
		} else {
			$types = isset( $assoc_args['post-type'] )
				? array_filter( array_map( 'trim', explode( ',', (string) $assoc_args['post-type'] ) ) )
				: array();

			$started = $sync->start( $types );

			if ( $started->is_failure() ) {
				WP_CLI::error( $started->message() );

				return;
			}

			$state = $started->value();

			WP_CLI::log( sprintf( 'Indexing about %s objects.', number_format_i18n( (int) $state['total'] ) ) );
		}

		$total    = max( 1, (int) ( $sync->state()['total'] ?? 1 ) );
		$reported = 0;

		// Periodic lines rather than a progress bar. A bar needs a terminal,
		// and an index of a large site is exactly the job somebody runs
		// detached or in CI, where a bar renders as thousands of control
		// characters and a line is readable.
		$result = $sync->run_to_completion(
			static function ( array $run ) use ( $total, &$reported ): void {
				$processed = (int) $run['processed'];

				if ( $processed - $reported < 1000 && 'running' === $run['state'] ) {
					return;
				}

				$reported = $processed;

				WP_CLI::log(
					sprintf(
						'  %s / %s (%d%%)',
						number_format_i18n( $processed ),
						number_format_i18n( $total ),
						(int) floor( min( 100, $processed / $total * 100 ) )
					)
				);
			}
		);

		if ( $result->is_failure() ) {
			WP_CLI::error( $result->message() );

			return;
		}

		$run = $result->value();

		WP_CLI::success(
			sprintf(
				'%s written, %s removed, %s failed.',
				number_format_i18n( (int) $run['written'] ),
				number_format_i18n( (int) $run['removed'] ),
				number_format_i18n( (int) $run['failed'] )
			)
		);

		if ( (int) $run['failed'] > 0 ) {
			WP_CLI::warning( 'Some objects could not be indexed. Enable SOLRENGINE_DEBUG to see why.' );
		}
	}

	/**
	 * Print the state of the current run.
	 *
	 * @param FullSync $sync Full sync service.
	 * @return void
	 */
	private function report_progress( FullSync $sync ): void {
		$run = $sync->state();

		if ( null === $run ) {
			WP_CLI::log( 'No synchronisation has been run.' );

			return;
		}

		WP_CLI\Utils\format_items(
			'table',
			array(
				array(
					'property' => 'State',
					'value'    => (string) $run['state'],
				),
				array(
					'property' => 'Processed',
					'value'    => number_format_i18n( (int) $run['processed'] ),
				),
				array(
					'property' => 'Of about',
					'value'    => number_format_i18n( (int) $run['total'] ),
				),
				array(
					'property' => 'Written',
					'value'    => number_format_i18n( (int) $run['written'] ),
				),
				array(
					'property' => 'Removed',
					'value'    => number_format_i18n( (int) $run['removed'] ),
				),
				array(
					'property' => 'Failed',
					'value'    => number_format_i18n( (int) $run['failed'] ),
				),
				array(
					'property' => 'Cursor',
					'value'    => (string) $run['cursor'],
				),
				array(
					'property' => 'Batch size',
					'value'    => (string) $run['batch_size'],
				),
				array(
					'property' => 'Last error',
					'value'    => (string) $run['last_error'],
				),
			),
			array( 'property', 'value' )
		);
	}
}
