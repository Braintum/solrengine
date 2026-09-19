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
define( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION', 1 );

require_once __DIR__ . '/wp-shims.php';

/*
 * The integration and backend suites need the WordPress test library. It is
 * loaded only when one of those suites is actually selected, so the unit suite
 * stays runnable on a machine with no WordPress checkout.
 */
$solrengine_suite = getenv( 'SOLRENGINE_TEST_SUITE' );

/*
 * The backend suite talks to a real Solr but needs no WordPress: schema
 * conformance is a property of the contract and the server, and loading
 * WordPress to check it would make the suite slower and harder to run without
 * proving anything more. Indexing tests, which do need WordPress, will live in
 * the integration suite alongside a Solr.
 */
if ( in_array( $solrengine_suite, array( 'integration' ), true ) ) {
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

	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			require dirname( __DIR__ ) . '/solrengine.php';
		}
	);

	require $solrengine_wp_tests . '/includes/bootstrap.php';
}
