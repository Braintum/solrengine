<?php
/**
 * Plugin Name:       SolrEngine - A Better Search for WordPress
 * Plugin URI:        https://www.solrengine.com
 * Description:       Integrate Apache Solr search engine with WordPress for fast and relevant search results.
 * Version:           0.1.0
 * Author:            SolrEngine
 * Author URI:        https://www.solrengine.com
 * License:           GPL2
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       solrengine
 * Domain Path:       /languages
 *
 * @package SolrEngine
 */

use SolrEngine\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require __DIR__ . '/vendor/autoload.php';

define( 'SOLRENGINE_VERSION', '0.1.0' );

// --- Plugin Bootstrap ---
/**
 * Bootstrap the SolrEngine plugin.
 *
 * @return Plugin The plugin instance.
 */
function solrengine_search_bootstrap() {
	static $plugin = null;

	if ( null === $plugin ) {
		$plugin = new Plugin( __FILE__ );
		$plugin->boot();
	}

	return $plugin;
}
add_action( 'plugins_loaded', 'solrengine_search_bootstrap' );

// --- Activation Hook ---
register_activation_hook(
	__FILE__,
	function () {
		// Optionally flush rewrite rules or setup Solr schema here.
		solrengine_search_bootstrap();
		flush_rewrite_rules();
	}
);

// --- Deactivation Hook ---
register_deactivation_hook(
	__FILE__,
	function () {
		flush_rewrite_rules();
	}
);
