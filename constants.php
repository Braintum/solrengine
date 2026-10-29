<?php
/**
 * Constants shared by every entry point.
 *
 * `solrengine.php` is the only entry point today. The version, the paths and
 * the schema contract version live here rather than beside the plugin header
 * so that anything else that ever has to start SolrEngine reads the same
 * values: defining them twice is how a User-Agent or a cache key ends up
 * reporting a release the code is not.
 *
 * Nothing here touches WordPress, so it costs nothing to include.
 * `SOLRENGINE_PLUGIN_URL` is deliberately absent: it needs `plugin_dir_url()`,
 * and solrengine.php defines it.
 *
 * Must parse on old PHP, for the same reason `solrengine.php` must: a syntax
 * error here is a white screen, not a notice.
 *
 * @package SolrEngine
 */

// Included by solrengine.php, which WordPress has already loaded.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'SOLRENGINE_VERSION' ) ) {
	define( 'SOLRENGINE_VERSION', '0.10.1' );
}

if ( ! defined( 'SOLRENGINE_PLUGIN_DIR' ) ) {
	// Computed rather than taken from plugin_dir_path(), which is a WordPress
	// function this file must not need.
	define( 'SOLRENGINE_PLUGIN_DIR', rtrim( str_replace( '\\', '/', __DIR__ ), '/' ) . '/' );
}

if ( ! defined( 'SOLRENGINE_PLUGIN_FILE' ) ) {
	define( 'SOLRENGINE_PLUGIN_FILE', SOLRENGINE_PLUGIN_DIR . 'solrengine.php' );
}

if ( ! defined( 'SOLRENGINE_MIN_PHP' ) ) {
	define( 'SOLRENGINE_MIN_PHP', '7.4' );
}

if ( ! defined( 'SOLRENGINE_MIN_WP' ) ) {
	define( 'SOLRENGINE_MIN_WP', '6.5' );
}

/**
 * Schema contract version.
 *
 * Deliberately independent of the plugin version: most releases change nothing
 * about the Solr schema, and coupling the two would force meaningless
 * migrations. See ADR-003.
 */
if ( ! defined( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION' ) ) {
	define( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION', 1 );
}
