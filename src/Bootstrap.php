<?php
/**
 * Composition root.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Contracts\Logger;
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
	}

	/**
	 * Register WordPress hooks.
	 *
	 * Phase 0 registers only what the foundation itself needs. Indexing hooks
	 * arrive in Phase 2, query integration in Phase 3.
	 *
	 * @return void
	 */
	private function register_hooks(): void {
		add_action( 'init', array( $this, 'load_textdomain' ) );
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
}
