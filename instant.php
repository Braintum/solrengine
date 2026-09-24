<?php
/**
 * Instant-search entry point.
 *
 * Answers autocomplete on a WordPress boot small enough to keep up with typing.
 * Not a REST route: `rest_api_init` is where every active plugin registers its
 * routes, and on a WooCommerce site that cost 830ms before the suggest callback
 * ran. See {@see \SolrEngine\Rest\InstantEndpoint} for the reasoning and for what
 * this bootstrap does and does not provide.
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
 * point: this plugin is not loaded as a plugin either, so the services below are
 * registered explicitly rather than on a hook.
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

/**
 * Answer with an empty list and give up.
 *
 * A broken install must not put an error in a dropdown, and must not fatal on a
 * visitor's keystroke. The field keeps working; the REST route and the admin
 * screens are where a misconfiguration gets reported to someone who can act.
 *
 * @param int $status HTTP status to send.
 * @return void
 */
function solrengine_instant_give_up( $status ) {
	if ( ! headers_sent() ) {
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		http_response_code( $status );
	}

	echo '{"suggestions":[],"prefix":"","took_ms":0}';
	exit;
}

if ( '' === $solrengine_load ) {
	solrengine_instant_give_up( 503 );
}

require_once $solrengine_load;

$solrengine_constants = dirname( __FILE__ ) . '/constants.php';
$solrengine_autoload  = dirname( __FILE__ ) . '/vendor/autoload.php';

// Mirrors the requirement gate in solrengine.php: a missing autoloader is a
// broken install, not something to fatal over on a visitor's keystroke.
if ( ! is_readable( $solrengine_constants ) || ! is_readable( $solrengine_autoload ) ) {
	solrengine_instant_give_up( 503 );
}

/*
 * The version, the paths and the schema contract version, from the same file
 * solrengine.php reads them from. Not solrengine.php itself: that one is a
 * WordPress plugin entry point, and reaching its register_activation_hook() call
 * in a SHORTINIT process means rebuilding the plugin-directory constants, which
 * costs more than this whole request has to spend.
 */
require_once $solrengine_constants;
require_once $solrengine_autoload;

/*
 * The WordPress surface SolrEngine's services expect.
 *
 * SHORTINIT stops before these, and the alternative to loading them is editing
 * the domain layer to avoid `wp_parse_url()` and `__()` — which would mean this
 * endpoint's requirements dictating how the rest of the plugin is written. Three
 * files and one global cost about 1.3ms measured, so the services stay as they
 * are and this process brings itself up to their level instead.
 *
 * `l10n.php` and the text-domain registry give `__()` something to answer with:
 * no translations are loaded here, so it returns the original string, which is
 * the right answer for a message nobody will read.
 *
 * `http.php` is deliberately NOT among them. It would define
 * `wp_remote_request()` without the WP_Http class behind it, and
 * {@see \SolrEngine\Backend\Solr\HttpTransport} chooses its sender by asking
 * whether that function exists — so loading it would route this request through
 * an HTTP API that cannot answer.
 */
require_once ABSPATH . WPINC . '/l10n.php';
require_once ABSPATH . WPINC . '/class-wp-textdomain-registry.php';
require_once ABSPATH . WPINC . '/pomo/translations.php';
require_once ABSPATH . WPINC . '/pomo/entry.php';

if ( ! isset( $GLOBALS['wp_textdomain_registry'] ) ) {
	$GLOBALS['wp_textdomain_registry'] = new WP_Textdomain_Registry();
}

/**
 * Resolve the site being searched.
 *
 * On multisite the blog is chosen by the host and path WordPress itself resolved
 * during SHORTINIT, never by a request parameter: a `site` argument would let a
 * caller read another site's index, and the site id is the filter that keeps
 * tenants apart.
 */
$solrengine_site = function_exists( 'get_current_blog_id' ) ? (int) get_current_blog_id() : 1;

if ( $solrengine_site < 1 ) {
	$solrengine_site = 1;
}

\SolrEngine\Rest\InstantEndpoint::create()->handle(
	is_array( $_GET ) ? wp_unslash( $_GET ) : array(),
	$solrengine_site
);
