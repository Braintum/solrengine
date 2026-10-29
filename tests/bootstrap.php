<?php
/**
 * PHPUnit bootstrap.
 *
 * The unit suite loads no WordPress at all. If a unit test ever needs a
 * WordPress function, that is a signal a seam has leaked, not a reason to load
 * WordPress here. See plan section 11.1.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

define( 'SOLRENGINE_TESTING', true );

/*
 * Which suite is running decides whether WordPress is loaded, and that one
 * answer settles everything else below.
 *
 * The backend suite talks to a real Solr but needs no WordPress: schema
 * conformance is a property of the contract and the server, and loading
 * WordPress to check it would make the suite slower and harder to run without
 * proving anything more. The unit suite loads none by design (section 11.1).
 * Only the integration suite does.
 */
$solrengine_suite = getenv( 'SOLRENGINE_TEST_SUITE' );

$solrengine_with_wordpress = in_array( $solrengine_suite, array( 'integration' ), true );

if ( ! $solrengine_with_wordpress ) {
	/*
	 * Stand-ins, and the constants the plugin file would otherwise define.
	 *
	 * Both are conditional on WordPress being absent, and that condition is the
	 * whole point rather than an optimisation. The shims are guarded with
	 * function_exists(), which protects nothing here: they load before the
	 * WordPress test library does, so every guard passes, apply_filters() is
	 * declared, and wp-includes/plugin.php then fatals redeclaring its own.
	 *
	 * The constant has the same shape. solrengine.php defines
	 * SOLRENGINE_SCHEMA_CONTRACT_VERSION itself, so pre-defining it means the
	 * plugin's own define() warns about a constant that is already there.
	 */
	define( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION', 1 );

	require_once __DIR__ . '/wp-shims.php';
}

if ( $solrengine_with_wordpress ) {
	$solrengine_wp_tests = getenv( 'WP_TESTS_DIR' );

	if ( ! is_string( $solrengine_wp_tests ) || '' === $solrengine_wp_tests ) {
		$solrengine_wp_tests = rtrim( sys_get_temp_dir(), '/\\' ) . '/wordpress-tests-lib';
	}

	if ( ! is_readable( $solrengine_wp_tests . '/includes/functions.php' ) ) {
		fwrite(
			STDERR,
			"WordPress test library not found at {$solrengine_wp_tests}.\n"
			. "Run bin/install-wp-tests.sh first, or set WP_TESTS_DIR.\n"
		);
		exit( 1 );
	}

	require_once $solrengine_wp_tests . '/includes/functions.php';

	/*
	 * WooCommerce, when SOLRENGINE_TEST_WOOCOMMERCE names its directory.
	 *
	 * Optional so that the plain WordPress jobs keep proving the plugin works
	 * without it. Loaded before SolrEngine, as a site's plugin order would, and
	 * installed once the theme is set up — WooCommerce creates its tables and
	 * roles there, the same way its own test suite does.
	 */
	$solrengine_woocommerce = getenv( 'SOLRENGINE_TEST_WOOCOMMERCE' );
	$solrengine_woocommerce = is_string( $solrengine_woocommerce ) && '' !== $solrengine_woocommerce
		? rtrim( $solrengine_woocommerce, '/\\' ) . '/woocommerce.php'
		: '';

	if ( '' !== $solrengine_woocommerce && ! is_readable( $solrengine_woocommerce ) ) {
		fwrite( STDERR, "SOLRENGINE_TEST_WOOCOMMERCE is set, but {$solrengine_woocommerce} is not readable.\n" );
		exit( 1 );
	}

	tests_add_filter(
		'muplugins_loaded',
		static function () use ( $solrengine_woocommerce ): void {
			if ( '' !== $solrengine_woocommerce ) {
				require $solrengine_woocommerce;
			}

			require dirname( __DIR__ ) . '/solrengine.php';
		}
	);

	if ( '' !== $solrengine_woocommerce ) {
		tests_add_filter(
			'setup_theme',
			static function (): void {
				\WC_Install::install();

				// Roles are cached before WooCommerce adds its own.
				$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
				wp_roles();
			}
		);
	}

	require $solrengine_wp_tests . '/includes/bootstrap.php';
}
