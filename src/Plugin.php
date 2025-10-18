<?php
/**
 * Main plugin bootstrap class.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

use SolrEngine\Solr\SolrClient;
use SolrEngine\Solr\SolariumClient;
use SolrEngine\Solr\SolrIndexer;
use SolrEngine\Solr\SolrSearcher;
use SolrEngine\Index\IndexManager;
use SolrEngine\Utils\Mapper;
use SolrEngine\Admin\SettingsPage;
use SolrEngine\Front\SearchController;
use SolrEngine\WooCommerce\ProductIndexer;

/**
 * Main plugin bootstrap class.
 * Handles initialization, dependency injection, and WordPress integration.
 */
class Plugin {

	/**
	 * DI container.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * Config instance.
	 *
	 * @var Config
	 */
	protected Config $config;

	/**
	 * Plugin file.
	 *
	 * @var string pluginFile
	 */
	protected string $pluginFile;

	/**
	 * Constructor.
	 *
	 * @param string $pluginFile Main plugin file path.
	 */
	public function __construct( string $pluginFile ) {

		$this->pluginFile = $pluginFile;

		// Hook plugin lifecycle events.
		register_activation_hook( $this->pluginFile, array( $this, 'onActivate' ) );
		register_deactivation_hook( $this->pluginFile, array( $this, 'onDeactivate' ) );
	}

	/**
	 * Boot the plugin and initialize core services.
	 */
	public function boot(): void {

		add_action( 'init', array( $this, 'loadTextDomain' ) );

		// 1️⃣ Define default configuration.
		$defaults = array(
			'solr'  => array(
				'endpoint'    => 'http://127.0.0.1:8983',
				'timeout'     => 5,
				'client_type' => 'solarium', // Options: 'default' or 'solarium'.
			),
			'index' => array(
				'commit_on_update' => true,
				'batch_size'       => 100,
			),
		);

		// 2️⃣ Load config with overrides.
		$this->config = new Config( $defaults );
		$this->config->loadFromWpOptions(
			array(
				'solr.endpoint'          => 'solrengine_solr_endpoint',
				'index.commit_on_update' => 'solrengine_commit_on_update',
			)
		);
		$this->config->loadFromEnv(
			array(
				'solr.endpoint'    => 'WPSOLR_SOLR_ENDPOINT',
				'solr.client_type' => 'SOLRENGINE_CLIENT_TYPE',
			)
		);

		// 3️⃣ Build the DI container.
		$this->container = new Container();
		$this->registerServices( $this->container );

		// 4️⃣ Initialize admin and frontend features.
		if ( is_admin() ) {
			( new SettingsPage( $this->container ) )->register();
		}

		// Register SearchController for both admin and frontend (needed for query vars).
		( new SearchController( $this->container ) )->register();

		// Register widget for both admin and frontend.
		add_action( 'widgets_init', array( $this, 'registerWidget' ) );

		// 5️⃣ Register post save hooks for indexing.
		add_action( 'save_post', array( $this, 'onPostSave' ), 10, 3 );

		// Optional WooCommerce support.
		if ( class_exists( 'WooCommerce' ) ) {
			add_action( 'woocommerce_update_product', array( $this, 'onProductSave' ), 10, 1 );
		}
	}

	/**
	 * Register all services in the DI container.
	 *
	 * @param Container $c DI container.
	 */
	protected function registerServices( Container $c ): void {
		$c->set( 'config', $this->config );

		// Solr client.
		$c->set(
			'solr.client',
			function ( $c ) {
				$cfg         = $c->get( 'config' );
				$client_type = $cfg->get( 'solr.client_type', 'default' );
				$endpoint    = (string) $cfg->get( 'solr.endpoint' );
				$timeout     = (int) $cfg->get( 'solr.timeout', 5 );

				if ( 'solarium' === $client_type && class_exists( 'Solarium\Client' ) ) {
					return new SolariumClient( $endpoint, $timeout );
				}

				return new SolrClient( $endpoint, $timeout );
			}
		);

		// Mapper.
		$c->set(
			'mapper',
			function () {
				return new Mapper();
			}
		);

		// Indexer & Searcher.
		$c->set(
			'indexer',
			function ( $c ) {
				return new SolrIndexer( $c->get( 'solr.client' ), $c->get( 'mapper' ) );
			}
		);
		$c->set(
			'searcher',
			function ( $c ) {
				return new SolrSearcher( $c->get( 'solr.client' ) );
			}
		);

		// Index Manager.
		$c->set(
			'index_manager',
			function ( $c ) {
				$manager = new IndexManager();
				$manager->registerIndexer( 'post', $c->get( 'indexer' ) );

				if ( class_exists( 'WooCommerce' ) ) {
					$manager->registerIndexer(
						'product',
						new ProductIndexer(
							$c->get( 'indexer' ),
							$c->get( 'mapper' )
						)
					);
				}

				return $manager;
			}
		);
	}

	/**
	 * Fired when a post is saved — trigger indexing.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @param bool    $update  Whether this is an existing post being updated.
	 */
	public function onPostSave( int $post_id, \WP_Post $post, bool $update ): void { // phpcs:ignore
		if ( wp_is_post_revision( $post_id ) || 'publish' !== $post->post_status ) {
			return;
		}

		$manager = $this->container->get( 'index_manager' );
		if ( $manager ) {
			$manager->index( 'post', $post_id );
		}
	}

	/**
	 * Fired when a WooCommerce product is updated.
	 *
	 * @param int $product_id Product ID.
	 */
	public function onProductSave( int $product_id ): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}

		$manager = $this->container->get( 'index_manager' );
		if ( $manager ) {
			$manager->index( 'product', $product );
		}
	}

	/**
	 * Activation hook.
	 */
	public function onActivate(): void {
		// Setup default options or schema.
		add_option( 'solrengine_solr_endpoint', 'http://127.0.0.1:8983/solr/wordpress' );

		// Flush rewrite rules to ensure new rules are registered.
		flush_rewrite_rules();
	}

	/**
	 * Deactivation hook.
	 */
	public function onDeactivate(): void {
		// Optionally remove transient or cleanup scheduled jobs.
	}

	/**
	 * Load plugin text domain for translations.
	 */
	public function loadTextDomain(): void {
		load_plugin_textdomain( 'solrengine', false, dirname( plugin_basename( $this->pluginFile ) ) . '/languages' );
	}

	/**
	 * Register widget when translations are ready.
	 */
	public function registerWidget(): void {

		// Register widget class name.
		register_widget( 'SolrEngine\Front\SearchWidget' );
	}

	/**
	 * Access container from outside if needed.
	 *
	 * @return Container
	 */
	public function container(): Container {
		return $this->container;
	}
}
