<?php
/**
 * Composition root.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

use SolrEngine\Admin\ConnectionScreen;
use SolrEngine\Admin\DashboardScreen;
use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Backend\Solr\EndpointValidator;
use SolrEngine\Backend\Solr\HttpTransport;
use SolrEngine\Backend\Solr\SolrBackend;
use SolrEngine\Cli\Commands;
use SolrEngine\Contracts\JobQueue;
use SolrEngine\Contracts\Logger;
use SolrEngine\Diagnostics\ConnectionDiagnostic;
use SolrEngine\Documents\IndexedTypes;
use SolrEngine\Documents\MetaAllowlist;
use SolrEngine\Documents\PostDocumentBuilder;
use SolrEngine\Indexing\DirtySet;
use SolrEngine\Indexing\FullSync;
use SolrEngine\Indexing\HookListeners;
use SolrEngine\Indexing\Indexer;
use SolrEngine\Indexing\Queue\ActionSchedulerQueue;
use SolrEngine\Indexing\Queue\CronQueue;
use SolrEngine\Indexing\QueueWorker;
use SolrEngine\Indexing\Reconciler;
use SolrEngine\Integration\WordPress\InstantSearch;
use SolrEngine\Integration\WordPress\QueryIntegration;
use SolrEngine\Integration\WordPress\SearchFilters;
use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Rest\RateLimiter;
use SolrEngine\Rest\SearchController;
use SolrEngine\Rest\StatusController;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\ResponseParser;
use SolrEngine\Search\SearchService;
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
		$this->register_hooks();

		// Backends are registered on init, not here. Registering one needs its
		// display name, and translating a string before init makes WordPress
		// load the text domain early, which 6.7 warns about on every request
		// and is right to. Priority 0, so registration still happens before
		// anything that searches or indexes.
		add_action( 'init', array( $this, 'register_backends' ), 0 );

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
			'search.relevance',
			static function ( Container $c ): RelevanceProfile {
				return RelevanceProfile::from_config(
					$c->get( 'config' )->get_array( 'search.relevance', array() ),
					// A shop searches for things a blog does not: a SKU is an
					// exact answer to an exact question, and an attribute value
					// is what a shopper types when they do not know the product
					// name. Neither field exists on a site without WooCommerce.
					WooCommerce::is_active() ? ProductFields::relevance_boosts() : array()
				);
			}
		);

		$container->share(
			'search.taxonomies',
			static function (): array {
				$out = array();

				foreach ( get_taxonomies( array( 'public' => true ), 'objects' ) as $taxonomy ) {
					$out[ (string) $taxonomy->name ] = array(
						'label'        => (string) ( $taxonomy->labels->name ?? $taxonomy->label ),
						'hierarchical' => (bool) $taxonomy->hierarchical,
					);
				}

				return $out;
			}
		);

		$container->share(
			'search.filters',
			static function ( Container $c ): FilterRegistry {
				return FilterRegistry::defaults( (array) $c->get( 'search.taxonomies' ) );
			}
		);

		$container->share(
			'search.facets',
			static function ( Container $c ): FacetRegistry {
				return FacetRegistry::defaults( (array) $c->get( 'search.taxonomies' ) );
			}
		);

		$container->share(
			'search.translator',
			static function ( Container $c ): QueryTranslator {
				// Built from the configured relevance profile and the site's own
				// registries. Until 0.6.0 the backend quietly built its own with
				// neither, so a site's relevance settings were ignored and a
				// taxonomy filter the REST endpoint had just accepted was
				// dropped without trace on the way to Solr.
				return new QueryTranslator(
					$c->get( 'search.relevance' ),
					$c->get( 'search.filters' ),
					array(),
					$c->get( 'search.facets' )
				);
			}
		);

		$container->share(
			'search.parser',
			static function ( Container $c ): ResponseParser {
				return new ResponseParser( $c->get( 'search.facets' ) );
			}
		);

		$container->share(
			'search.filters_ui',
			static function ( Container $c ): SearchFilters {
				return new SearchFilters(
					$c->get( 'search.service' ),
					$c->get( 'config' ),
					$c->get( 'search.facets' ),
					$c->get( 'search.filters' ),
					(int) $c->get( 'site_id' )
				);
			}
		);

		$container->share(
			'search.service',
			static function ( Container $c ): SearchService {
				return new SearchService(
					$c->get( 'backends' )->search(),
					$c->get( 'logger' ),
					$c->get( 'config' )->get_int( 'search.cache_ttl', 300 )
				);
			}
		);

		$container->share(
			'rest.rate_limiter',
			static function ( Container $c ): RateLimiter {
				$config = $c->get( 'config' );

				return new RateLimiter(
					$config->get_int( 'search.rate_limit', 60 ),
					$config->get_int( 'search.rate_window', 60 )
				);
			}
		);

		$container->share(
			'indexing.reconciler',
			static function ( Container $c ): Reconciler {
				return new Reconciler(
					$c->get( 'backends' ),
					$c->get( 'config' ),
					$c->get( 'logger' )
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
	 * Public because it runs on init rather than inline during boot. An add-on
	 * registering its own backend on `solrengine_booted` still works: the
	 * registry is one shared object, and registration order does not matter as
	 * long as every backend is registered before the first search.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function register_backends(): void {
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
				$container->get( 'schema.exporter' ),
				$config->get_int( 'index.commit_within_ms', 60000 ),
				$container->get( 'search.translator' ),
				$container->get( 'search.parser' )
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
		$this->register_search( $container );
		$this->register_filters_ui( $container );
		$this->register_woocommerce( $container );
		$this->register_rest( $container );

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
						$container->get( 'schema.exporter' ),
						$container->get( 'search.facets' )
					) )->add_menu();

					( new DashboardScreen( $container ) )->add_menu();
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
			IndexedTypes::all( $config ),
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

	/**
	 * Attach the search integration.
	 *
	 * Registered on the front end only. An admin request never searches
	 * through this path, and attaching a pre_get_posts listener to every
	 * wp-admin screen would be a cost paid on every page for nothing.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_search( Container $container ): void {
		if ( is_admin() ) {
			return;
		}

		$config = $container->get( 'config' );

		if ( ! $config instanceof Config ) {
			return;
		}

		// Instant search is independent of search replacement, so a site can
		// try suggestions before switching its search over. Registering it
		// only enqueues an asset; nothing is constructed unless a visitor
		// actually types.
		if ( $config->get_bool( 'search.instant_enabled', false ) ) {
			( new InstantSearch( $config ) )->register();
		}

		// Checked before anything else is constructed. A site that has not
		// turned search on should not pay for a backend, an endpoint validator
		// or a query translator on every front-end request.
		if ( ! $config->get_bool( 'search.enabled', false ) ) {
			return;
		}

		$container->share(
			'search.integration',
			static function ( Container $c ) use ( $config ): QueryIntegration {
				return new QueryIntegration(
					$c->get( 'search.service' ),
					$config,
					(int) $c->get( 'site_id' ),
					$c->get( 'search.filters' ),
					$c->get( 'search.facets' )
				);
			}
		);

		// The listeners are attached immediately — pre_get_posts can fire
		// before "wp" on some request shapes, and a listener attached after the
		// query it was meant to intercept is worse than no listener — but the
		// integration behind them is built on first use.
		//
		// That matters for more than laziness. Both registries are built from
		// the site's public taxonomies, and taxonomies do not exist until init.
		// Building them here would hand every site a filter registry holding
		// nothing, on the one code path where a visitor would notice.
		$resolve = static function () use ( $container ): ?QueryIntegration {
			$integration = $container->get( 'search.integration' );

			return $integration instanceof QueryIntegration ? $integration : null;
		};

		add_action(
			'pre_get_posts',
			static function ( $query ) use ( $resolve ): void {
				// The cheap half of the eligibility test, before anything is
				// constructed. pre_get_posts fires for every menu, widget and
				// related-posts query on a page; building a search backend for
				// each of them would cost an endpoint validation apiece, and
				// building one *during* another query is how the container
				// ends up resolving itself.
				if ( ! $query instanceof \WP_Query || ! $query->is_main_query() || ! $query->is_search() ) {
					return;
				}

				$integration = $resolve();

				if ( null !== $integration ) {
					$integration->mark_query( $query );
				}
			}
		);

		add_filter(
			'posts_pre_query',
			static function ( $posts, $query ) use ( $resolve ) {
				$integration = $resolve();

				return null === $integration ? $posts : $integration->maybe_substitute( $posts, $query );
			},
			10,
			2
		);
	}

	/**
	 * Attach the WooCommerce integration.
	 *
	 * On init, and for two reasons. WooCommerce loads its own functions during
	 * plugins_loaded, so asking whether it is there any earlier is a race this
	 * plugin would sometimes lose. And its attribute taxonomies, which the
	 * facet registry is built from, do not exist until init either.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_woocommerce( Container $container ): void {
		add_action(
			'init',
			static function () use ( $container ): void {
				if ( ! WooCommerce::is_active() ) {
					return;
				}

				$config = $container->get( 'config' );

				if ( ! $config instanceof Config ) {
					return;
				}

				WooCommerce::attach_registries();

				WooCommerce::attach(
					$config,
					$container->get( 'indexing.dirty' ),
					static function () use ( $container ) {
						return $container->get( 'search.service' );
					},
					static function () use ( $container ) {
						return $container->get( 'search.filters' );
					},
					(int) $container->get( 'site_id' )
				);
			},
			0
		);
	}

	/**
	 * Register the filter panel's block and shortcode.
	 *
	 * Registered in the admin as well as on the front end, because the block
	 * has to exist for the editor to offer it. Only the registration happens
	 * here: the renderer is resolved inside the callbacks, so a page that
	 * contains no filter panel never constructs a search backend.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_filters_ui( Container $container ): void {
		add_action(
			'init',
			static function () use ( $container ): void {
				SearchFilters::register_assets();

				$ui = static function () use ( $container ): ?SearchFilters {
					$service = $container->get( 'search.filters_ui' );

					return $service instanceof SearchFilters ? $service : null;
				};

				add_shortcode(
					SearchFilters::SHORTCODE,
					static function ( $atts ) use ( $ui ): string {
						$panel = $ui();

						return null === $panel ? '' : $panel->shortcode( is_array( $atts ) ? $atts : array() );
					}
				);

				register_block_type(
					SOLRENGINE_PLUGIN_DIR . 'blocks/search-filters',
					array(
						'render_callback' => static function ( $attributes ) use ( $ui ): string {
							$panel = $ui();

							return null === $panel ? '' : $panel->render_block( (array) $attributes );
						},
					)
				);
			}
		);
	}

	/**
	 * Register the REST routes.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_rest( Container $container ): void {
		add_action(
			'rest_api_init',
			static function () use ( $container ): void {
				$config = $container->get( 'config' );

				if ( ! $config instanceof Config ) {
					return;
				}

				( new SearchController(
					static function () use ( $container ) {
						return $container->get( 'search.service' );
					},
					$container->get( 'search.filters' ),
					$container->get( 'search.facets' ),
					$config,
					$container->get( 'rest.rate_limiter' ),
					(int) $container->get( 'site_id' )
				) )->register();

				( new StatusController( $container ) )->register();
			}
		);

		add_action(
			Reconciler::HOOK,
			static function () use ( $container ): void {
				$reconciler = $container->get( 'indexing.reconciler' );

				if ( $reconciler instanceof Reconciler ) {
					$reconciler->run();
				}
			}
		);
	}
}
