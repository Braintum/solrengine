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
use SolrEngine\Indexing\Rebuild;
use SolrEngine\Indexing\RunHistory;
use SolrEngine\Multisite\NetworkConfig;
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

		if ( ! $backend->is_manageable() ) {
			// A managed backend owns its own schema. Distinguished from the case
			// above because the answer is different: there is nothing to apply
			// by hand either, and nothing the operator needs to do.
			WP_CLI::success( 'This backend manages its own schema. There is nothing to check or apply.' );

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

	/**
	 * Build a replacement index alongside the live one, and switch to it.
	 *
	 * The alternative to reindexing in place. A reindex writes over the index
	 * that is answering searches, so results are wrong for as long as it runs and
	 * there is no way back; this builds a second index, checks it, and switches
	 * only then. The previous index is never deleted automatically.
	 *
	 * ## OPTIONS
	 *
	 * [<action>]
	 * : What to do.
	 * ---
	 * default: status
	 * options:
	 *   - status
	 *   - start
	 *   - verify
	 *   - switch
	 *   - discard
	 *   - drop-previous
	 * ---
	 *
	 * [--target=<name>]
	 * : Index to build into. Required where SolrEngine cannot create one, which
	 * is any Solr not running in SolrCloud mode.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine rebuild start
	 *     wp solrengine rebuild start --target=wordpress_v2
	 *     wp solrengine rebuild verify
	 *     wp solrengine rebuild switch
	 *
	 * @since 0.8.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function rebuild( array $args, array $assoc_args ): void {
		$rebuild = $this->container->get( 'indexing.rebuild' );

		if ( ! $rebuild instanceof Rebuild ) {
			WP_CLI::error( 'No usable backend. Run "wp solrengine test" to see why.' );
		}

		$action = isset( $args[0] ) ? (string) $args[0] : 'status';

		switch ( $action ) {
			case 'start':
				$this->rebuild_start( $rebuild, isset( $assoc_args['target'] ) ? (string) $assoc_args['target'] : '' );
				return;

			case 'verify':
				$this->report_rebuild( $this->expect( $rebuild->verify() ) );
				return;

			case 'switch':
				$this->expect( $rebuild->switch_over() );

				WP_CLI::success( 'Searches are now answered by the new index. The previous one is still on the server.' );
				return;

			case 'discard':
				$this->expect( $rebuild->discard() );

				WP_CLI::success( 'Discarded.' );
				return;

			case 'drop-previous':
				$this->expect( $rebuild->drop_previous() );

				WP_CLI::success( 'The previous index was deleted.' );
				return;

			default:
				$state = $rebuild->state();

				if ( null === $state ) {
					$plan = $this->expect( $rebuild->plan() );

					WP_CLI::log( sprintf( 'No rebuild in progress. Strategy: %s.', (string) $plan['style'] ) );
					WP_CLI::log(
						$plan['can_create']
							? sprintf( 'Starting one would create "%s".', (string) $plan['suggested'] )
							: 'Starting one needs --target, naming a core that already exists.'
					);

					return;
				}

				$this->report_rebuild( $state );
		}
	}

	/**
	 * Show what has been indexed and what failed.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : How many runs to list.
	 * ---
	 * default: 10
	 * ---
	 *
	 * [--failures]
	 * : List the objects that failed instead of the runs.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine history
	 *     wp solrengine history --failures
	 *
	 * @since 0.8.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function history( array $args, array $assoc_args ): void {
		unset( $args );

		$history = $this->container->get( 'indexing.history' );

		if ( ! $history instanceof RunHistory ) {
			WP_CLI::error( 'Run history is unavailable.' );
		}

		$limit = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : 10;

		if ( isset( $assoc_args['failures'] ) ) {
			$failures = $history->failures( 0, $limit );

			if ( array() === $failures ) {
				WP_CLI::log( 'No failures have been recorded.' );

				return;
			}

			WP_CLI\Utils\format_items( 'table', $failures, array( 'run_id', 'object_type', 'object_id', 'message' ) );

			return;
		}

		$runs = $history->recent( $limit );

		if ( array() === $runs ) {
			WP_CLI::log( 'Nothing has been indexed from this site yet.' );

			return;
		}

		$rows = array();

		foreach ( $runs as $run ) {
			$rows[] = array(
				'started' => gmdate( 'Y-m-d H:i', (int) $run['started_at'] ),
				'kind'    => (string) $run['kind'],
				'target'  => (string) $run['target'],
				'state'   => (string) $run['state'],
				'indexed' => (int) $run['written'],
				'failed'  => (int) $run['failed'],
				'seconds' => max( 0, (int) $run['finished_at'] - (int) $run['started_at'] ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'started', 'kind', 'target', 'state', 'indexed', 'failed', 'seconds' ) );
	}

	/**
	 * Start a rebuild and run it to completion in this process.
	 *
	 * Run here rather than handed to the queue, for the same reason the index
	 * command does it: there is no web-server timeout on a command line, and a
	 * queue round trip per batch would only add latency to a run somebody is
	 * watching.
	 *
	 * @param Rebuild $rebuild Rebuild service.
	 * @param string  $target  Target index, or empty to create one.
	 * @return void
	 */
	private function rebuild_start( Rebuild $rebuild, string $target ): void {
		$state = $this->expect( $rebuild->start( $target ) );

		WP_CLI::log( sprintf( 'Building into "%s". Searches are still answered by the current index.', (string) $state['target'] ) );

		$done = 0;

		do {
			$batch = $rebuild->run_batch();

			if ( $batch->is_failure() ) {
				WP_CLI::error( $batch->message() );
			}

			$run = $batch->value();

			if ( (int) $run['processed'] - $done >= 1000 ) {
				$done = (int) $run['processed'];

				WP_CLI::log( sprintf( '%s indexed.', number_format_i18n( $done ) ) );
			}
		} while ( 'running' === $run['state'] );

		$this->report_rebuild( $this->expect( $rebuild->verify() ) );

		WP_CLI::log( 'Run "wp solrengine rebuild switch" when you are satisfied with the new index.' );
	}

	/**
	 * Print a rebuild's state.
	 *
	 * @param array<string,mixed> $state Rebuild state.
	 * @return void
	 */
	private function report_rebuild( array $state ): void {
		WP_CLI::log(
			sprintf(
				'%s: building into "%s" — %s of about %s documents.',
				(string) $state['state'],
				(string) $state['target'],
				number_format_i18n( (int) $state['actual'] ),
				number_format_i18n( (int) $state['expected'] )
			)
		);

		if ( '' !== (string) $state['message'] ) {
			WP_CLI::log( (string) $state['message'] );
		}

		if ( 'ready' === $state['state'] ) {
			WP_CLI::success( 'The new index matches WordPress and is ready to switch to.' );
		}
	}

	/**
	 * Unwrap a Result, or stop with its message.
	 *
	 * @param \SolrEngine\Contracts\Result $result Result to unwrap.
	 * @return mixed
	 */
	private function expect( $result ) {
		if ( $result->is_failure() ) {
			WP_CLI::error( $result->message() );
		}

		return $result->value( array() );
	}

	/**
	 * Manage SolrEngine across a multisite network.
	 *
	 * Network-wide work belongs here rather than in the network admin screen, and
	 * §14.4 says so from the outset: per-site collections multiply every migration
	 * across the network, and a per-site button clicked four hundred times is not
	 * an operation, it is an afternoon.
	 *
	 * Indexing and schema work run **one site at a time, in a separate process
	 * per site**. That is the throttle §20 asks for, and the isolation is not
	 * optional: WordPress caches a great deal per site, and a single process
	 * walking four hundred sites through switch_to_blog() accumulates all of it
	 * until it runs out of memory somewhere in the three hundreds.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - status
	 *   - enable
	 *   - disable
	 *   - index
	 *   - schema
	 * ---
	 *
	 * [<site>...]
	 * : Site IDs, for enable and disable.
	 *
	 * [--all]
	 * : Every site in the network, for enable and disable.
	 *
	 * [--pause=<seconds>]
	 * : Wait this long between sites, for index and schema. Default 0.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine network status
	 *     wp solrengine network enable 2 3 4
	 *     wp solrengine network index --pause=30
	 *     wp solrengine network schema
	 *
	 * @since 0.9.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function network( array $args, array $assoc_args ): void {
		if ( ! is_multisite() ) {
			WP_CLI::error( 'This is not a multisite network.' );
		}

		$network = $this->container->get( 'network' );

		if ( ! $network instanceof NetworkConfig ) {
			WP_CLI::error( 'Network configuration is unavailable.' );
		}

		$action = isset( $args[0] ) ? (string) $args[0] : 'status';

		switch ( $action ) {
			case 'enable':
				$this->network_switch( $network, array_slice( $args, 1 ), $assoc_args, true );
				return;

			case 'disable':
				$this->network_switch( $network, array_slice( $args, 1 ), $assoc_args, false );
				return;

			case 'index':
				$this->network_run( $network, 'solrengine index', $assoc_args );
				return;

			case 'schema':
				$this->network_run( $network, 'solrengine schema apply --yes', $assoc_args );
				return;

			default:
				$this->network_status( $network );
		}
	}

	/**
	 * Print the network's state.
	 *
	 * @param NetworkConfig $network Network configuration.
	 * @return void
	 */
	private function network_status( NetworkConfig $network ): void {
		$enabled = $network->enabled_sites();

		WP_CLI::log( sprintf( 'Topology: %s.', $network->topology() ) );

		$pattern = $network->collection_pattern();

		WP_CLI::log(
			'' === $pattern
				? 'Collection naming: each site names its own.'
				: sprintf( 'Collection naming: %s', $pattern )
		);

		$rows = array();

		foreach ( $this->network_sites() as $site ) {
			$id = (int) $site->blog_id;

			$rows[] = array(
				'id'   => $id,
				'site' => (string) $site->domain . (string) $site->path,
				'on'   => in_array( $id, $enabled, true ) ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'site', 'on' ) );
	}

	/**
	 * Switch sites on or off.
	 *
	 * @param NetworkConfig        $network    Network configuration.
	 * @param array<int,string>    $ids        Site identifiers given.
	 * @param array<string,string> $assoc_args Flags.
	 * @param bool                 $on         Whether to enable.
	 * @return void
	 */
	private function network_switch( NetworkConfig $network, array $ids, array $assoc_args, bool $on ): void {
		$targets = array_map( 'intval', $ids );

		if ( isset( $assoc_args['all'] ) ) {
			$targets = array();

			foreach ( $this->network_sites() as $site ) {
				$targets[] = (int) $site->blog_id;
			}
		}

		if ( array() === $targets ) {
			WP_CLI::error( 'Name at least one site ID, or pass --all.' );
		}

		$enabled = $network->enabled_sites();

		$updated = $on
			? array_merge( $enabled, $targets )
			: array_diff( $enabled, $targets );

		$network->set_enabled_sites( array_values( $updated ) );

		WP_CLI::success(
			sprintf(
				'%d site(s) switched %s. %s',
				count( $targets ),
				$on ? 'on' : 'off',
				$on
					? 'Nothing is indexed yet: run "wp solrengine network index" when you are ready.'
					: 'Their documents are still in Solr. Remove them per site if you want them gone.'
			)
		);
	}

	/**
	 * Run one command on every enabled site, one at a time.
	 *
	 * A separate process per site, through runcommand() with launch enabled. In
	 * one process the per-site caches WordPress builds are never released, and a
	 * walk across a large network dies of memory exhaustion partway through — at
	 * which point half the network is indexed and nothing says which half.
	 *
	 * A failure on one site is reported and the walk continues. Stopping would
	 * mean one unreachable site blocks every site after it in the list.
	 *
	 * @param NetworkConfig        $network    Network configuration.
	 * @param string               $command    Command to run per site.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	private function network_run( NetworkConfig $network, string $command, array $assoc_args ): void {
		$enabled = $network->enabled_sites();

		if ( array() === $enabled ) {
			WP_CLI::error( 'No sites are switched on. Run "wp solrengine network enable" first.' );
		}

		$pause  = isset( $assoc_args['pause'] ) ? max( 0, (int) $assoc_args['pause'] ) : 0;
		$failed = 0;
		$done   = 0;

		foreach ( $this->network_sites() as $site ) {
			$id = (int) $site->blog_id;

			if ( ! in_array( $id, $enabled, true ) ) {
				continue;
			}

			$url = (string) $site->domain . (string) $site->path;

			WP_CLI::log( sprintf( '--- site %d (%s)', $id, $url ) );

			$result = WP_CLI::runcommand(
				$command . ' --url=' . escapeshellarg( $url ),
				array(
					'launch'     => true,
					'exit_error' => false,
					'return'     => 'all',
				)
			);

			if ( is_object( $result ) && 0 !== (int) $result->return_code ) {
				++$failed;

				WP_CLI::warning( sprintf( 'Site %d failed: %s', $id, trim( (string) $result->stderr ) ) );
			} else {
				++$done;
			}

			if ( $pause > 0 ) {
				// The throttle. One Solr cluster serving a whole network has
				// other work to do, and a pause between sites is the difference
				// between a slow reindex and a cluster that stops answering
				// searches while it runs.
				sleep( $pause );
			}
		}

		if ( $failed > 0 ) {
			WP_CLI::warning( sprintf( '%d site(s) finished, %d failed.', $done, $failed ) );

			return;
		}

		WP_CLI::success( sprintf( '%d site(s) finished.', $done ) );
	}

	/**
	 * The sites in this network, ordered by identifier.
	 *
	 * @return array<int,\WP_Site>
	 */
	private function network_sites(): array {
		$sites = get_sites(
			array(
				'number'  => 0,
				'orderby' => 'id',
			)
		);

		return is_array( $sites ) ? array_values( $sites ) : array();
	}

	/**
	 * Move this site from one search backend to another.
	 *
	 * A migration in three moves, with visitors served throughout. Indexing is
	 * pointed at the new backend while the old one keeps answering searches; the
	 * new index fills; and only then does anything visitors can see change.
	 *
	 * That split is Principle 2 made concrete — §21.1 names it as the payoff for
	 * keeping SearchBackend and IndexBackend separate — and it is the same idea
	 * as the Phase 7 rebuild, one level up: never serve from a half-built index.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : What to do.
	 * ---
	 * options:
	 *   - status
	 *   - start
	 *   - finish
	 *   - abort
	 * ---
	 *
	 * [--to=<backend>]
	 * : The backend to migrate to, for start.
	 *
	 * ## EXAMPLES
	 *
	 *     wp solrengine backends status
	 *     wp solrengine backends start --to=memory
	 *     wp solrengine index
	 *     wp solrengine backends finish
	 *
	 * @since 0.10.0
	 *
	 * @param array<int,string>    $args       Positional arguments.
	 * @param array<string,string> $assoc_args Flags.
	 * @return void
	 */
	public function backends( array $args, array $assoc_args ): void {
		$config = $this->container->get( 'config' );
		$action = isset( $args[0] ) ? (string) $args[0] : 'status';

		switch ( $action ) {
			case 'start':
				$target = isset( $assoc_args['to'] ) ? (string) $assoc_args['to'] : '';

				if ( ! $this->backends->has( $target ) ) {
					WP_CLI::error(
						sprintf(
							'No backend called "%s". Registered: %s.',
							$target,
							implode( ', ', array_keys( $this->backends->available() ) )
						)
					);
				}

				if ( $target === $this->backends->selected_id() ) {
					WP_CLI::error( 'That is already the backend this site indexes into.' );
				}

				// Searches stay where they are; indexing moves. Recorded in this
				// order so that no request can observe the new backend serving
				// before it has been written to.
				$config->set( 'connection.search_backend', $this->backends->selected_id() );
				$config->set( 'connection.backend', $target );

				WP_CLI::success(
					sprintf(
						'Indexing now writes to "%s"; searches are still answered by "%s". Run "wp solrengine index", then "wp solrengine backends finish".',
						$target,
						$this->backends->search_id()
					)
				);

				return;

			case 'finish':
				if ( ! $this->backends->is_migrating() ) {
					WP_CLI::error( 'No migration is in progress.' );
				}

				$this->finish_migration( $config );

				return;

			case 'abort':
				if ( ! $this->backends->is_migrating() ) {
					WP_CLI::error( 'No migration is in progress.' );
				}

				// Indexing goes back to where searches never left. Whatever was
				// written to the other backend stays there; SolrEngine does not
				// delete an index it was told to fill.
				$config->set( 'connection.backend', $this->backends->search_id() );
				$config->set( 'connection.search_backend', '' );

				WP_CLI::success( 'Migration abandoned. Nothing was deleted from the backend you were moving to.' );

				return;

			default:
				$this->backend_status();
		}
	}

	/**
	 * Switch searches to the backend that has been filling.
	 *
	 * Verified before switching, by the same rule the rebuild uses: a run that
	 * finished is not the same claim as an index that is correct.
	 *
	 * @param \SolrEngine\Support\Config $config Configuration.
	 * @return void
	 */
	private function finish_migration( $config ): void {
		$index = $this->backends->index();

		if ( null === $index ) {
			WP_CLI::error( 'The backend you are migrating to is not reachable.' );
		}

		$status = $index->status();

		if ( $status->is_failure() ) {
			WP_CLI::error( sprintf( 'The new backend could not be asked how full it is: %s', $status->message() ) );
		}

		$reconciler = $this->container->get( 'indexing.reconciler' );
		$expected   = $reconciler instanceof \SolrEngine\Indexing\Reconciler ? $reconciler->expected_count() : 0;
		$actual     = $status->value()->document_count();

		if ( $expected > 0 && $actual < (int) floor( $expected * 0.98 ) ) {
			WP_CLI::error(
				sprintf(
					'The new backend holds %s documents and should hold about %s. Index it fully before finishing, or run "wp solrengine backends abort".',
					number_format_i18n( $actual ),
					number_format_i18n( $expected )
				)
			);
		}

		$config->set( 'connection.search_backend', '' );

		WP_CLI::success(
			sprintf( 'Searches are now answered by "%s". The previous backend still holds its documents.', $this->backends->selected_id() )
		);
	}

	/**
	 * Print which backend does what.
	 *
	 * @return void
	 */
	private function backend_status(): void {
		$rows = array();

		foreach ( $this->backends->available() as $id => $label ) {
			$id = (string) $id;

			$roles = array();

			if ( $id === $this->backends->selected_id() ) {
				$roles[] = 'indexing';
			}

			if ( $id === $this->backends->search_id() ) {
				$roles[] = 'searching';
			}

			$rows[] = array(
				'id'    => $id,
				'name'  => (string) $label,
				'doing' => array() === $roles ? '-' : implode( ', ', $roles ),
			);
		}

		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'doing' ) );

		if ( $this->backends->is_migrating() ) {
			WP_CLI::log(
				sprintf(
					'A migration is in progress: indexing into "%s", searching "%s".',
					$this->backends->selected_id(),
					$this->backends->search_id()
				)
			);
		}
	}
}
