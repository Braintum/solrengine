<?php
/**
 * Instant-search entry point.
 *
 * Answers autocomplete on a WordPress boot small enough to keep up with typing.
 * Not a REST route: `rest_api_init` is where every active plugin registers its
 * routes, and on a WooCommerce site that cost 830ms before the suggest callback
 * ran. See {@see \SolrEngine\Rest\InstantEndpoint} for the full reasoning.
 *
 * This file is reached directly over HTTP, so it assumes nothing. It must parse
 * on old PHP for the same reason `solrengine.php` must: a syntax error here is a
 * white screen, not a notice.
 *
 * @package SolrEngine
 */

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- A public read-only endpoint. No state changes, nothing to forge.

/**
 * Boot WordPress with the smallest surface that still has options and the
 * database. SHORTINIT loads neither plugins nor the theme, which is the entire
 * point: this plugin's own bootstrap is not loaded either, so everything below
 * resolves what it needs by hand.
 */
define( 'SHORTINIT', true );

/**
 * Locate wp-load.php by walking up from this file.
 *
 * Never from the request. `$_SERVER['DOCUMENT_ROOT']` and friends are
 * attacker-influenced on some stacks, and a path taken from a request is how a
 * file-inclusion bug gets written. The plugin directory's position relative to
 * wp-load.php is fixed by WordPress, so walking up is both correct and
 * unforgeable.
 */
$solrengine_root = dirname( __FILE__ );
$solrengine_load = '';

for ( $solrengine_depth = 0; $solrengine_depth < 10; $solrengine_depth++ ) {
	$solrengine_root = dirname( $solrengine_root );

	if ( '' === $solrengine_root || '/' === $solrengine_root || '.' === $solrengine_root ) {
		break;
	}

	if ( is_readable( $solrengine_root . '/wp-load.php' ) ) {
		$solrengine_load = $solrengine_root . '/wp-load.php';
		break;
	}
}

if ( '' === $solrengine_load ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	http_response_code( 503 );
	echo '{"suggestions":[],"prefix":"","took_ms":0}';
	exit;
}

require_once $solrengine_load;

$solrengine_dir      = dirname( __FILE__ ) . '/';
$solrengine_autoload = $solrengine_dir . 'vendor/autoload.php';

// Mirrors the requirement gate in solrengine.php. A missing autoloader is a
// broken install, not something to fatal over on a visitor's keystroke.
if ( ! is_readable( $solrengine_autoload ) ) {
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'Cache-Control: no-store' );
	http_response_code( 503 );
	echo '{"suggestions":[],"prefix":"","took_ms":0}';
	exit;
}

require_once $solrengine_autoload;

// SHORTINIT skips wp-settings.php, so the constants solrengine.php defines are
// not defined. The classes below read SOLRENGINE_VERSION for the user agent.
if ( ! defined( 'SOLRENGINE_VERSION' ) ) {
	define( 'SOLRENGINE_VERSION', '0.10.1' );
}

if ( ! defined( 'SOLRENGINE_PLUGIN_DIR' ) ) {
	define( 'SOLRENGINE_PLUGIN_DIR', $solrengine_dir );
}

/**
 * Resolve the site being searched.
 *
 * On multisite the blog is chosen by the host and path WordPress itself
 * resolved during SHORTINIT, never by a request parameter: a `site` argument
 * would let a caller read another site's index, and the site id is the filter
 * that keeps tenants apart.
 */
$solrengine_site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

if ( $solrengine_site < 1 ) {
	$solrengine_site = 1;
}

\SolrEngine\Rest\InstantEndpoint::create()->handle(
	is_array( $_GET ) ? wp_unslash( $_GET ) : array(),
	$solrengine_site
);
