<?php
/**
 * Composition root.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

use SolrEngine\Admin\ConnectionScreen;
use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Backend\Solr\EndpointValidator;
use SolrEngine\Backend\Solr\HttpTransport;
use SolrEngine\Backend\Solr\SolrBackend;
use SolrEngine\Cli\Commands;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Logger;
use SolrEngine\Diagnostics\ConnectionDiagnostic;
use SolrEngine\Documents\MetaAllowlist;
use SolrEngine\Documents\PostDocumentBuilder;
use SolrEngine\Indexing\DirtySet;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\HookListeners;
use SolrEngine\Indexing\Indexer;
use SolrEngine\Indexing\Queue\ActionSchedulerQueue;
use SolrEngine\Indexing\Queue\CronQueue;
use SolrEngine\Indexing\QueueWorker;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaExporter;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Support\Config;
use SolrEngine\Support\Container;
use SolrEngine\Support\Credentials;
use SolrEngine\Support\DebugLogger;
use SolrEngine\Support\Encryption;
use SolrEngine\Support\NullLogger;

/**
 * Wires the plugin together.
 *
 * The only class that knows how every other class is constructed. Nothing here
 * does work; it registers factories and hooks, and the first hook that actually
 * fires is what causes anything to be built. A page load that never runs a
 * search or saves a post should cost the price of this file and nothing more.
 *
 * Replaces the 0.1.0 prototype's SolrEngine\Plugin, which constructed its
 * settings page, search controller and widget on every request whether or not
 * they were needed, and which registered activation hooks from inside
 * plugins_loaded where they can never fire.
 *
 * @since 0.1.0
 */
final class Bootstrap {

	/**
	 * Singleton instance.
	 *
	 * @var Bootstrap|null
	 */
	private static $instance = null;

	/**
	 * Service container.
	 *
	 * @var Container
	 */
	private $container;

	/**
	 * Whether boot() has run.
	 *
	 * @var bool
	 */
	private $booted = false;

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->container = new Container();
	}

	/**
	 * The shared instance.
	 *
	 * A singleton here and nowhere else. The plugin entry point is a global
	 * event ("WordPress loaded"), so it has exactly one instance by nature;
	 * every service below is an ordinary object with declared dependencies.
	 *
	 * @since 0.1.0
	 *
	 * @return Bootstrap
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register services and hooks.
	 *
	 * Safe to call more than once; subsequent calls do nothing.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function boot(): void {
		if ( $this->booted ) {
			return;
		}

		$this->booted = true;

		$this->register_services();
		$this->register_backends();
		$this->register_hooks();

		/**
		 * Fires once SolrEngine has registered its services.
		 *
		 * The place for an add-on to register a backend, a facet or an admin
		 * screen. Nothing has been constructed yet, so registering here costs
		 * nothing on requests that never use it.
		 *
		 * @since 0.1.0
		 *
		 * @param Container $container SolrEngine's service container.
		 */
		do_action( 'solrengine_booted', $this->container );
	}

	/**
	 * The service container.
	 *
	 * Exposed for tests and for WP-CLI, which needs to reach services outside a
	 * hook. Not intended as a general-purpose accessor: services should be
	 * injected, not fetched.
	 *
	 * @since 0.1.0
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}

	/**
	 * Register service factories.
	 *
	 * @return void
	 */
	private function register_services(): void {
		$container = $this->container;

		$container->share(
			'config',
			static function (): Config {
				return new Config();
			}
		);

		$container->share(
			'encryption',
			static function (): Encryption {
				return new Encryption();
			}
		);

		$container->share(
			'credentials',
			static function ( Container $c ): Credentials {
				return new Credentials( $c->get( 'encryption' ) );
			}
		);

		$container->share(
			'logger',
			static function ( Container $c ): Logger {
				$config = $c->get( 'config' );

				if ( ! $config instanceof Config ) {
					return new NullLogger();
				}

				return self::debugging( $config ) ? new DebugLogger() : new NullLogger();
			}
		);

		$container->share(
			'backends',
			static function ( Container $c ): BackendRegistry {
				return new BackendRegistry( $c->get( 'config' ) );
			}
		);

		$container->share(
			'schema.contracts',
			static function (): ContractRegistry {
				return new ContractRegistry();
			}
		);

		$container->share(
			'schema.planner',
			static function (): SchemaPlanner {
				return new SchemaPlanner();
			}
		);

		$container->share(
			'schema.exporter',
			static function (): SchemaExporter {
				return new SchemaExporter();
			}
		);

		$container->share(
			'solr.endpoint_validator',
			static function ( Container $c ): EndpointValidator {
				$config = $c->get( 'config' );

				/**
				 * Ports SolrEngine will connect to.
				 *
				 * Widening this widens the surface an administrator could use
				 * to probe internal infrastructure, so it is a filter rather
				 * than a setting: a deliberate act by a site owner, in code.
				 *
				 * @since 0.1.0
				 *
				 * @param int[] $ports Permitted ports.
				 */
				$ports = apply_filters(
					'solrengine_allowed_ports',
					$config->get_array( 'connection.allowed_ports', EndpointValidator::DEFAULT_PORTS )
				);

				return new EndpointValidator( array_map( 'intval', (array) $ports ) );
			}
		);

		$container->share(
			'site_id',
			static function (): int {
				// Written on every document and injected into every query, even
				// on a single-site install where it is always 1. That constant
				// cost is what lets a network move between one collection per
				// site and a shared collection without touching the document
				// model. See plan section 10.2.
				return get_current_blog_id();
			}
		);

		$container->share(
			'meta_allowlist',
			static function ( Container $c ): MetaAllowlist {
				$config = $c->get( 'config' );

				return MetaAllowlist::from_config(
					array_map( 'strval', $config->get_array( 'index.meta_allowlist', array() ) )
				);
			}
		);

		$container->share(
			'documents.post_builder',
			static function ( Container $c ): PostDocumentBuilder {
				return new PostDocumentBuilder(
					$c->get( 'meta_allowlist' ),
					(int) $c->get( 'site_id' )
				);
			}
		);

		$container->share(
			'indexing.dirty',
			static function (): DirtySet {
				return new DirtySet();
			}
		);

		$container->share(
			'indexing.queue',
			static function (): JobQueue {
				// Action Scheduler is preferred. The WP-Cron driver exists so a
				// site without it still indexes, weaker guarantees and all; the
				// dashboard reports which one is in use. See ADR-004.
				$scheduler = new ActionSchedulerQueue();

				return $scheduler->is_available() ? $scheduler : new CronQueue();
			}
		);

		$container->share(
			'indexing.indexer',
			static function ( Container $c ): ?Indexer {
				$backend = $c->get( 'backends' )->index();

				if ( null === $backend ) {
					return null;
				}

				return new Indexer(
					$backend,
					$c->get( 'documents.post_builder' ),
					$c->get( 'logger' )
				);
			}
		);

		$container->share(
			'indexing.full_sync',
			static function ( Container $c ): ?FullSync {
				$indexer = $c->get( 'indexing.indexer' );

				if ( ! $indexer instanceof Indexer ) {
					return null;
				}

				return new FullSync(
					$indexer,
					$c->get( 'indexing.queue' ),
					$c->get( 'config' ),
					$c->get( 'logger' ),
					(int) $c->get( 'site_id' )
				);
			}
		);

		$container->share(
			'diagnostics.connection',
			static function ( Container $c ): ConnectionDiagnostic {
				return new ConnectionDiagnostic(
					$c->get( 'config' ),
					$c->get( 'credentials' ),
					$c->get( 'solr.endpoint_validator' ),
					$c->get( 'schema.contracts' ),
					$c->get( 'logger' )
				);
			}
		);
	}

	/**
	 * Register the backends this plugin ships with.
	 *
	 * Registration is lazy: selecting one backend must not construct another,
	 * and nothing here opens a connection.
	 *
	 * @return void
	 */
	private function register_backends(): void {
		$container = $this->container;

		$registry = $container->get( 'backends' );

		$factory = static function () use ( $container ): ?SolrBackend {
			$config = $container->get( 'config' );

			$validator = $container->get( 'solr.endpoint_validator' );

			$endpoint = $validator->validate(
				$config->get_string( 'connection.endpoint' ),
				$config->get_string( 'connection.collection' ),
				$config->get_bool( 'connection.allow_private_ip' )
			);

			if ( $endpoint->is_failure() ) {
				return null;
			}

			return new SolrBackend(
				new HttpTransport(
					$endpoint->value(),
					$container->get( 'credentials' ),
					$container->get( 'logger' ),
					$config->get_int( 'connection.read_timeout', 10 ),
					$config->get_bool( 'connection.verify_tls', true )
				),
				$container->get( 'schema.planner' ),
				$container->get( 'schema.exporter' )
			);
		};

		$registry->register(
			'solr',
			__( 'Apache Solr (self-hosted)', 'solrengine' ),
			$factory,
			$factory
		);
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$container = $this->container;

		add_action( 'init', array( $this, 'load_textdomain' ) );

		$this->register_indexing( $container );

		if ( is_admin() ) {
			add_action(
				'admin_menu',
				static function () use ( $container ): void {
					// Constructed here rather than at registration time, so a
					// front-end request never builds an admin screen.
					( new ConnectionScreen(
						$container->get( 'config' ),
						$container->get( 'credentials' ),
						$container->get( 'diagnostics.connection' ),
						$container->get( 'schema.contracts' ),
						$container->get( 'schema.exporter' )
					) )->add_menu();
				},
				9
			);

			add_action(
				'admin_post_solrengine_save_connection',
				static function () use ( $container ): void {
					( new ConnectionScreen(
						$container->get( 'config' ),
						$container->get( 'credentials' ),
						$container->get( 'diagnostics.connection' ),
						$container->get( 'schema.contracts' ),
						$container->get( 'schema.exporter' )
					) )->handle_save();
				}
			);
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			$this->register_cli();
		}
	}

	/**
	 * Register WP-CLI commands.
	 *
	 * @return void
	 */
	private function register_cli(): void {
		$container = $this->container;

		$commands = new Commands(
			$container->get( 'diagnostics.connection' ),
			$container->get( 'backends' ),
			$container->get( 'schema.contracts' ),
			$container->get( 'schema.exporter' ),
			$container
		);

		\WP_CLI::add_command( 'solrengine test', array( $commands, 'test' ) );
		\WP_CLI::add_command( 'solrengine status', array( $commands, 'status' ) );
		\WP_CLI::add_command( 'solrengine schema', array( $commands, 'schema' ) );
		\WP_CLI::add_command( 'solrengine index', array( $commands, 'index' ) );
	}

	/**
	 * Load the translation files.
	 *
	 * On init rather than plugins_loaded, per current WordPress guidance.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function load_textdomain(): void {
		load_plugin_textdomain(
			'solrengine',
			false,
			dirname( plugin_basename( SOLRENGINE_PLUGIN_FILE ) ) . '/languages'
		);
	}

	/**
	 * Whether debug logging is switched on.
	 *
	 * @param Config $config Configuration.
	 * @return bool
	 */
	private static function debugging( Config $config ): bool {
		if ( $config->get_bool( 'runtime.debug', false ) ) {
			return true;
		}

		return defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG;
	}

	/**
	 * Attach the indexing pipeline.
	 *
	 * Listeners are attached unconditionally, because they are cheap: each one
	 * adds a reference to an in-memory set and nothing else. The expensive
	 * parts — building documents, talking to Solr — happen in a queued job, and
	 * only if something actually changed.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_indexing( Container $container ): void {
		$config = $container->get( 'config' );

		if ( ! $config instanceof Config ) {
			return;
		}

		$dirty = $container->get( 'indexing.dirty' );

		$listeners = new HookListeners(
			$dirty,
			$container->get( 'meta_allowlist' ),
			array_map( 'strval', $config->get_array( 'index.post_types', array( 'post', 'page' ) ) ),
			(int) $container->get( 'site_id' )
		);

		$listeners->register();

		// The worker resolves the real indexer lazily when a job runs, so that
		// registering these hooks never constructs a backend or validates an
		// endpoint on a request that indexes nothing.
		add_action(
			'shutdown',
			static function () use ( $container, $dirty ): void {
				if ( $dirty->is_empty() ) {
					return;
				}

				$real = self::worker_for( $container, $dirty );

				if ( null !== $real ) {
					$real->flush();
				}
			},
			100
		);

		add_action(
			QueueWorker::INDEX_HOOK,
			static function ( $encoded ) use ( $container, $dirty ): void {
				$real = self::worker_for( $container, $dirty );

				if ( null !== $real ) {
					$real->handle_index( $encoded );
				}
			}
		);

		add_action(
			QueueWorker::DELETE_HOOK,
			static function ( $encoded ) use ( $container, $dirty ): void {
				$real = self::worker_for( $container, $dirty );

				if ( null !== $real ) {
					$real->handle_delete( $encoded );
				}
			}
		);

		add_action(
			FullSync::HOOK,
			static function () use ( $container ): void {
				$sync = $container->get( 'indexing.full_sync' );

				if ( $sync instanceof FullSync ) {
					$sync->run_batch();
				}
			}
		);
	}

	/**
	 * Build a worker backed by the real indexer, or null when none is usable.
	 *
	 * @param Container $container Service container.
	 * @param DirtySet  $dirty     Dirty set.
	 * @return QueueWorker|null
	 */
	private static function worker_for( Container $container, DirtySet $dirty ): ?QueueWorker {
		$indexer = $container->get( 'indexing.indexer' );

		if ( ! $indexer instanceof Indexer ) {
			// No usable backend. Marked objects are dropped rather than queued,
			// because a queue filling up against a backend that does not exist
			// helps nobody; the reconciliation pass is what recovers once a
			// connection is configured.
			return null;
		}

		return new QueueWorker(
			$dirty,
			$container->get( 'indexing.queue' ),
			$indexer,
			$container->get( 'logger' ),
			defined( 'SOLRENGINE_SYNC_INDEXING' ) && SOLRENGINE_SYNC_INDEXING
		);
	}
}
