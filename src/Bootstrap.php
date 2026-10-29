<?php
/**
 * Composition root.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

use SolrEngine\Admin\ConnectionScreen;
use SolrEngine\Admin\ContentScreen;
use SolrEngine\Admin\DashboardScreen;
use SolrEngine\Admin\NetworkScreen;
use SolrEngine\Admin\SearchScreen;
use SolrEngine\Admin\ToolsScreen;
use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Backend\Solr\EndpointValidator;
use SolrEngine\Backend\Solr\HttpTransport;
use SolrEngine\Backend\Memory\MemoryBackend;
use SolrEngine\Backend\Solr\SolrBackend;
use SolrEngine\Contracts\IndexBackend;
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
use SolrEngine\Indexing\Rebuild;
use SolrEngine\Indexing\Reconciler;
use SolrEngine\Indexing\ReindexNotice;
use SolrEngine\Indexing\RunHistory;
use SolrEngine\Integration\WordPress\InstantSearch;
use SolrEngine\Integration\WordPress\QueryIntegration;
use SolrEngine\Integration\WordPress\SearchFilters;
use SolrEngine\Integration\WordPress\SearchModal;
use SolrEngine\Integration\Multilingual\CoreProvider;
use SolrEngine\Integration\Multilingual\PolylangProvider;
use SolrEngine\Integration\Multilingual\WpmlProvider;
use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Language\Languages;
use SolrEngine\Multisite\NetworkConfig;
use SolrEngine\Multisite\SiteScope;
use SolrEngine\Rest\InstantResultsController;
use SolrEngine\Rest\RateLimiter;
use SolrEngine\Rest\SearchController;
use SolrEngine\Rest\StatusController;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\InstantResults;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\ResponseParser;
use SolrEngine\Contracts\Breaker;
use SolrEngine\Contracts\Cache;
use SolrEngine\Contracts\Viewer;
use SolrEngine\Search\SearchService;
use SolrEngine\Schema\AppliedSchema;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaExporter;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Support\Config;
use SolrEngine\Support\Container;
use SolrEngine\Support\ObjectCache;
use SolrEngine\Support\OptionBreaker;
use SolrEngine\Support\Upgrade;
use SolrEngine\Support\WpViewer;
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
	 * Whether service factories have been registered.
	 *
	 * Separate from $booted, because registering services and attaching hooks
	 * are separate things: instant-search needs the first without the second.
	 *
	 * @var bool
	 */
	private $services_registered = false;

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

		$this->services();
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
	 * The container, with every service factory registered and no hooks attached.
	 *
	 * `boot()` is the entry point for a WordPress request: it registers these
	 * same factories and then attaches the plugin's hooks. A caller that must not
	 * attach anything uses this instead.
	 *
	 * Registration is lazy throughout, so this constructs nothing: the caller
	 * resolves the one or two services it needs and pays for those alone. That
	 * is what lets any such caller share this composition rather than assemble a
	 * parallel one, which is how the two would drift apart on a timeout, a TLS
	 * setting, or a relevance default.
	 *
	 * Callers in a reduced environment may override a factory before resolving
	 * anything, for the few services whose definition assumes a full boot.
	 *
	 * Safe to call more than once.
	 *
	 * @since 0.10.2
	 *
	 * @return Container
	 */
	public function services(): Container {
		if ( ! $this->services_registered ) {
			$this->services_registered = true;

			$this->register_services();
		}

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
			'network',
			static function (): NetworkConfig {
				return new NetworkConfig();
			}
		);

		$container->share(
			'config',
			static function ( Container $c ): Config {
				return new Config( array(), array(), $c->get( 'network' ) );
			}
		);

		$container->share(
			'site_scope',
			static function ( Container $c ): SiteScope {
				return new SiteScope(
					$c->get( 'network' ),
					$c->get( 'config' ),
					(int) $c->get( 'site_id' )
				);
			}
		);

		$container->share(
			'languages',
			static function (): Languages {
				// Preference order, most specific first. The core provider is
				// last and always active, so every site has an answer: a
				// monolingual site reports one language, which switches every
				// language-aware behaviour off without a special case for it.
				return new Languages(
					array(
						new WpmlProvider(),
						new PolylangProvider(),
						new CoreProvider(),
					)
				);
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
				// Keyed to the configured backend, read from configuration
				// rather than from the registry: backends register on init, and
				// asking the registry before that would answer "none" and hand
				// this site another backend's secret.
				return new Credentials(
					$c->get( 'encryption' ),
					$c->get( 'config' )->get_string( 'connection.backend', 'solr' )
				);
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
			'backend.memory',
			static function ( Container $c ): MemoryBackend {
				// Shared, not per-call. Search and indexing resolve separately
				// through the registry, and two instances would mean documents
				// written by one and searched for in the other.
				return new MemoryBackend(
					$c->get( 'search.filters' ),
					$c->get( 'search.facets' )
				);
			}
		);

		$container->share(
			'schema.contracts',
			static function ( Container $c ): ContractRegistry {
				// The contract carries this site's analysed languages, so the
				// plan, the export, the diagnostic and the CLI all describe the
				// same schema. A contract that meant something different
				// depending on who asked would produce a plan that disagreed
				// with the schema it was compared against.
				return new ContractRegistry( self::analysed_languages( $c ) );
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
					(int) $c->get( 'site_id' ),
					524288,
					$c->get( 'languages' ),
					self::analysed_languages( $c )
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
			'indexing.history',
			static function ( Container $c ): RunHistory {
				return new RunHistory( (int) $c->get( 'site_id' ) );
			}
		);

		$container->share(
			'indexing.reindex_notice',
			static function ( Container $c ): ReindexNotice {
				return new ReindexNotice( $c->get( 'config' ) );
			}
		);

		$container->share(
			'schema.applied',
			static function ( Container $c ): AppliedSchema {
				return new AppliedSchema( $c->get( 'config' ) );
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
					(int) $c->get( 'site_id' ),
					$c->get( 'indexing.history' )
				);
			}
		);

		$container->share(
			'indexing.rebuild',
			static function ( Container $c ): ?Rebuild {
				if ( null === $c->get( 'backends' )->lifecycle() ) {
					return null;
				}

				return new Rebuild(
					$c->get( 'backends' ),
					$c->get( 'config' ),
					$c->get( 'logger' ),
					$c->get( 'indexing.reconciler' ),
					$c->get( 'indexing.reindex_notice' ),
					static function ( string $target ) use ( $c ): ?FullSync {
						// A second synchronisation, writing to a second index,
						// on its own channel so its progress cannot be mistaken
						// for the live one's. Everything else about it — the
						// cursor, the adaptive batch size, the failure handling —
						// is the code that already indexes this site.
						$backend = self::backend_for( $c, $target );

						if ( null === $backend ) {
							return null;
						}

						return new FullSync(
							new Indexer( $backend, $c->get( 'documents.post_builder' ), $c->get( 'logger' ) ),
							$c->get( 'indexing.queue' ),
							$c->get( 'config' ),
							$c->get( 'logger' ),
							(int) $c->get( 'site_id' ),
							$c->get( 'indexing.history' ),
							Rebuild::CHANNEL,
							$target
						);
					}
				);
			}
		);

		// Its own service so that a process which loads no plugins can answer
		// it another way.
		$container->share(
			'woocommerce.active',
			static function (): bool {
				return WooCommerce::is_active();
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
					$c->get( 'woocommerce.active' ) ? ProductFields::relevance_boosts() : array()
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
				return FilterRegistry::defaults(
					(array) $c->get( 'search.taxonomies' ),
					$c->get( 'languages' )->all()
				);
			}
		);

		$container->share(
			'search.facets',
			static function ( Container $c ): FacetRegistry {
				return FacetRegistry::defaults(
					(array) $c->get( 'search.taxonomies' ),
					$c->get( 'languages' )->all()
				);
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
					$c->get( 'search.facets' ),
					self::analysed_languages( $c )
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
			'viewer',
			static function (): Viewer {
				return new WpViewer();
			}
		);

		$container->share(
			'cache',
			static function (): Cache {
				return new ObjectCache();
			}
		);

		$container->share(
			'breaker',
			static function ( Container $c ): Breaker {
				return new OptionBreaker( $c->get( 'logger' ) );
			}
		);

		$container->share(
			'search.service',
			static function ( Container $c ): SearchService {
				return new SearchService(
					$c->get( 'backends' )->search(),
					$c->get( 'logger' ),
					$c->get( 'config' )->get_int( 'search.cache_ttl', 300 ),
					$c->get( 'viewer' ),
					$c->get( 'cache' ),
					$c->get( 'breaker' )
				);
			}
		);

		$container->share(
			'search.instant_results',
			static function ( Container $c ): InstantResults {
				// Not search.instant_limit: that is how many suggestions the
				// suggestion list shows, and the popup's pages and sections are
				// sized for its card grid instead.
				return new InstantResults(
					$c->get( 'search.service' ),
					IndexedTypes::all( $c->get( 'config' ) )
				);
			}
		);

		// Who the rate limiter exempts. Its own service rather than 'viewer',
		// because the two questions part company where a login cannot be
		// verified: a cache may trust an unvalidated cookie, an exemption may
		// not.
		$container->share(
			'rest.rate_limiter.viewer',
			static function ( Container $c ): Viewer {
				return $c->get( 'viewer' );
			}
		);

		$container->share(
			'rest.rate_limiter',
			static function ( Container $c ): RateLimiter {
				$config = $c->get( 'config' );

				return new RateLimiter(
					$config->get_int( 'search.rate_limit', 60 ),
					$config->get_int( 'search.rate_window', 60 ),
					$c->get( 'rest.rate_limiter.viewer' )
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
			return self::solr_backend( $container );
		};

		$registry->register(
			'solr',
			// Deferred: this runs on every request, and instant-search registers
			// backends in a process with no translation stack loaded.
			static function (): string {
				return __( 'Apache Solr (self-hosted)', 'solrengine' );
			},
			$factory,
			$factory
		);

		/*
		 * The in-memory backend, behind a constant.
		 *
		 * It exists to prove the abstraction and to let the WordPress side be
		 * developed without a Solr running, and it holds documents for exactly
		 * one request — so a site that selected it would index everything and
		 * find nothing on the next page load. Registered only where somebody has
		 * asked for it in wp-config.php, which nobody does by accident.
		 */
		if ( defined( 'SOLRENGINE_MEMORY_BACKEND' ) && SOLRENGINE_MEMORY_BACKEND ) {
			$memory = static function () use ( $container ): MemoryBackend {
				return $container->get( 'backend.memory' );
			};

			$registry->register(
				MemoryBackend::ID,
				static function (): string {
					return __( 'In-memory (development only)', 'solrengine' );
				},
				$memory,
				$memory
			);
		}
	}

	/**
	 * The primary subtags this site has analysed fields for.
	 *
	 * Read from configuration rather than detected, because detecting means
	 * reading the live schema and this is asked on every request that builds a
	 * document or a query. The Search screen is what puts a language in here, and
	 * it only does so after confirming the server has the analyser and applying
	 * the field that uses it — so a value here is a value the index can take.
	 *
	 * @param Container $container Service container.
	 * @return string[]
	 */
	private static function analysed_languages( Container $container ): array {
		$config = $container->get( 'config' );

		if ( ! $config instanceof Config ) {
			return array();
		}

		return array_values( array_map( 'strval', $config->get_array( 'index.analysed_languages', array() ) ) );
	}

	/**
	 * Build a Solr backend, optionally pointed at a collection of our choosing.
	 *
	 * One builder for both uses. A rebuild needs a backend addressing the
	 * replacement index rather than the live one, and the alternative — a second
	 * construction path — is how the two end up differing in a timeout or a TLS
	 * setting that nobody notices until a rebuild behaves unlike everything else.
	 *
	 * The endpoint is validated either way. A collection name reaching here has
	 * come from Solr's own listing or from a form, and neither is a reason to
	 * skip the check that every other request goes through.
	 *
	 * @param Container $container  Service container.
	 * @param string    $collection Collection to address, or empty for the
	 *                              configured one.
	 * @return SolrBackend|null
	 */
	private static function solr_backend( Container $container, string $collection = '' ): ?SolrBackend {
		$config = $container->get( 'config' );

		$endpoint = $container->get( 'solr.endpoint_validator' )->validate(
			$config->get_string( 'connection.endpoint' ),
			// Through the site scope rather than straight from configuration: on
			// a network using one collection per site, the name is derived from
			// the network's pattern and the site's identifier, and a caller
			// reading the raw setting would address the wrong collection.
			'' !== $collection ? $collection : $container->get( 'site_scope' )->collection(),
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
	}

	/**
	 * An index backend addressing one named index.
	 *
	 * @param Container $container Service container.
	 * @param string    $target    Index name.
	 * @return IndexBackend|null
	 */
	private static function backend_for( Container $container, string $target ): ?IndexBackend {
		return self::solr_backend( $container, $target );
	}

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		$container = $this->container;

		// No load_plugin_textdomain() call. WordPress has loaded a plugin's
		// translations on demand since 4.6, reading the Domain Path header for
		// where to look, and the floor here is 6.5. Calling it is discouraged,
		// Plugin Check says so, and on a directory-hosted plugin it does nothing
		// that would not have happened anyway.

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			// Before the opt-in gate, deliberately. "wp solrengine network enable"
			// has to work from a site that is switched off, which is every site
			// the first time somebody runs it.
			$this->register_cli();
		}

		if ( ! $container->get( 'site_scope' )->is_enabled() ) {
			// A site in a network where SolrEngine is activated network-wide and
			// this site has not been switched on. Nothing is indexed, nothing is
			// searched, and WordPress behaves exactly as it did before.
			//
			// A site where somebody activated the plugin themselves is never in
			// this branch: doing that *is* the opt-in.
			//
			// This is the whole point of per-site opt-in: activating a plugin
			// across four hundred sites must not start four hundred full syncs
			// against one Solr cluster. §20.
			//
			// The network screen is still registered, because a network
			// administrator visiting a site in the network needs somewhere to go.
			if ( is_admin() ) {
				$this->register_network_admin( $container );

				add_action( 'admin_notices', array( $this, 'render_disabled_notice' ) );
			}

			return;
		}

		if ( is_admin() ) {
			// Corrections for a site that updated rather than activated. Admin
			// only: nothing here may cost a visitor a query.
			add_action( 'admin_init', array( Upgrade::class, 'run' ) );
		}

		$this->register_indexing( $container );
		$this->register_search( $container );
		$this->register_filters_ui( $container );
		$this->register_woocommerce( $container );
		$this->register_rest( $container );

		if ( is_admin() ) {
			$this->register_admin( $container );
			$this->register_network_admin( $container );
		}
	}

	/**
	 * Register the admin screens and the actions their forms post to.
	 *
	 * Screens are constructed inside the callbacks, not here. Registering a
	 * handler must not build the screen that handles it: admin_post fires once
	 * per request for one action, and constructing five screens to run one of
	 * them would resolve a backend, a diagnostic and a schema planner for
	 * nothing on every admin page load.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_admin( Container $container ): void {
		$connection = static function () use ( $container ): ConnectionScreen {
			return new ConnectionScreen(
				$container->get( 'config' ),
				$container->get( 'credentials' ),
				$container->get( 'diagnostics.connection' ),
				$container->get( 'site_scope' ),
				$container->get( 'backends' )
			);
		};

		$content = static function () use ( $container ): ContentScreen {
			return new ContentScreen(
				$container->get( 'config' ),
				$container->get( 'indexing.reindex_notice' )
			);
		};

		$search = static function () use ( $container ): SearchScreen {
			return new SearchScreen(
				$container->get( 'config' ),
				$container->get( 'search.facets' ),
				$container->get( 'languages' ),
				$container->get( 'backends' ),
				$container->get( 'schema.contracts' )
			);
		};

		add_action(
			'admin_menu',
			static function () use ( $container, $connection, $content, $search ): void {
				// Order is the setup order from the plan's section 17.3, not
				// alphabetical: connect, see whether it worked, choose what to
				// index, decide how it ranks, then run it.
				$connection()->add_menu();

				( new DashboardScreen( $container ) )->add_menu();

				$content()->add_menu();
				$search()->add_menu();

				( new ToolsScreen( $container ) )->add_menu();
			},
			9
		);

		add_action(
			'admin_post_solrengine_save_connection',
			static function () use ( $connection ): void {
				$connection()->handle_save();
			}
		);

		add_action(
			'admin_post_' . ContentScreen::ACTION,
			static function () use ( $content ): void {
				$content()->handle_save();
			}
		);

		add_action(
			'admin_post_' . SearchScreen::ACTION,
			static function () use ( $search ): void {
				$search()->handle_save();
			}
		);

		add_action(
			'admin_post_' . SearchScreen::ACTION . '_reset',
			static function () use ( $search ): void {
				$search()->handle_reset();
			}
		);

		add_action(
			'admin_post_' . SearchScreen::ACTION . '_languages',
			static function () use ( $search ): void {
				$search()->handle_languages();
			}
		);

		add_action(
			'admin_post_' . ToolsScreen::ACTION_SCHEMA,
			static function () use ( $container ): void {
				( new ToolsScreen( $container ) )->handle_schema();
			}
		);

		add_action(
			'admin_post_' . ToolsScreen::ACTION_INDEX,
			static function () use ( $container ): void {
				( new ToolsScreen( $container ) )->handle_index();
			}
		);

		add_action(
			'admin_post_' . ToolsScreen::ACTION_REBUILD,
			static function () use ( $container ): void {
				( new ToolsScreen( $container ) )->handle_rebuild();
			}
		);
	}

	/**
	 * Tell an administrator why SolrEngine is doing nothing here.
	 *
	 * An inert plugin with a full settings screen looks broken. This says which
	 * it is, and who can change it.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function render_disabled_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="notice notice-info"><p>'
			. esc_html__( 'SolrEngine is not switched on for this site. Searches are answered by WordPress and nothing is being indexed. A network administrator can switch it on under Network Admin, Settings, SolrEngine.', 'solrengine' )
			. '</p></div>';
	}

	/**
	 * Register the network administration screen.
	 *
	 * Registered from every site as well as from the network dashboard, because
	 * network_admin_menu fires on network requests only and a site request needs
	 * to be able to link to it.
	 *
	 * @param Container $container Service container.
	 * @return void
	 */
	private function register_network_admin( Container $container ): void {
		if ( ! is_multisite() ) {
			return;
		}

		$screen = static function () use ( $container ): NetworkScreen {
			return new NetworkScreen(
				$container->get( 'network' ),
				$container->get( 'credentials' ),
				$container->get( 'config' )
			);
		};

		add_action(
			'network_admin_menu',
			static function () use ( $screen ): void {
				$screen()->add_menu();
			}
		);

		add_action(
			'network_admin_edit_' . NetworkScreen::ACTION,
			static function () use ( $screen ): void {
				$screen()->handle_save();
			}
		);
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
		\WP_CLI::add_command( 'solrengine rebuild', array( $commands, 'rebuild' ) );
		\WP_CLI::add_command( 'solrengine history', array( $commands, 'history' ) );
		\WP_CLI::add_command( 'solrengine backends', array( $commands, 'backends' ) );

		if ( is_multisite() ) {
			\WP_CLI::add_command( 'solrengine network', array( $commands, 'network' ) );
		}
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

		// The rebuild channel's own hook. A separate one so that a queued batch
		// is always handled by the synchronisation that scheduled it: one hook
		// shared between two destinations would let a rebuild's batch be run by
		// the instance writing to the live index.
		add_action(
			FullSync::HOOK . '_' . Rebuild::CHANNEL,
			static function () use ( $container ): void {
				$rebuild = $container->get( 'indexing.rebuild' );

				if ( ! $rebuild instanceof Rebuild ) {
					return;
				}

				// verify() runs the outstanding batch's continuation through the
				// same channel and records where the rebuild has got to, so the
				// admin screen is accurate without polling anything itself.
				$rebuild->run_batch();
			}
		);

		add_action(
			'solrengine_full_sync_complete',
			static function ( $run ) use ( $container ): void {
				if ( is_array( $run ) && '' !== (string) ( $run['target'] ?? '' ) ) {
					// A rebuild finished building. Nothing is serving from it
					// yet, so the live index is still missing whatever the
					// notice is about; switching over is what clears it.
					return;
				}

				// Everything the index was missing, it now has. Cleared here
				// rather than on each screen, so a run started from WP-CLI
				// clears it too.
				$notice = $container->get( 'indexing.reindex_notice' );

				if ( $notice instanceof ReindexNotice ) {
					$notice->clear();
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
			if ( 'suggest' === $config->get_string( 'search.instant_mode', 'modal' ) ) {
				( new InstantSearch( $config ) )->register();
			} else {
				( new SearchModal( $config ) )->register();
			}
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
					$c->get( 'search.facets' ),
					$c->get( 'languages' )
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

				( new InstantResultsController(
					static function () use ( $container ) {
						return $container->get( 'search.instant_results' );
					},
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
