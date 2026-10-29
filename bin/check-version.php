<?php
/**
 * Verify that every declared version agrees.
 *
 * Three declarations must agree: the plugin header in solrengine.php, the
 * SOLRENGINE_VERSION constant in constants.php, and the readme.txt stable tag.
 * They drift silently otherwise, and a mismatched stable tag is one of the more
 * common reasons a WordPress.org release ships the wrong code. Enforced in CI
 * rather than by discipline.
 *
 * The constant lives in constants.php rather than beside the header so that any
 * other entry point reads the same value, and a second define() is exactly the
 * kind of drift this script exists to catch.
 *
 * Usage: php bin/check-version.php
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

$root = dirname( __DIR__ );

$plugin_src = (string) file_get_contents( $root . '/solrengine.php' );
$constants  = (string) file_get_contents( $root . '/constants.php' );
$readme     = (string) file_get_contents( $root . '/readme.txt' );

$found = array();

if ( preg_match( '/^\s*\*\s*Version:\s*(\S+)\s*$/m', $plugin_src, $m ) ) {
	$found['plugin header'] = $m[1];
}

if ( preg_match( "/define\(\s*'SOLRENGINE_VERSION',\s*'([^']+)'\s*\)/", $constants, $m ) ) {
	$found['SOLRENGINE_VERSION'] = $m[1];
}

if ( preg_match( '/^Stable tag:\s*(\S+)\s*$/m', $readme, $m ) ) {
	$found['readme stable tag'] = $m[1];
}

$expected = array( 'plugin header', 'SOLRENGINE_VERSION', 'readme stable tag' );
$missing  = array_diff( $expected, array_keys( $found ) );

if ( array() !== $missing ) {
	fwrite( STDERR, 'Could not find: ' . implode( ', ', $missing ) . "\n" );
	exit( 1 );
}

if ( 1 !== count( array_unique( $found ) ) ) {
	fwrite( STDERR, "Version mismatch:\n" );

	foreach ( $found as $where => $version ) {
		fwrite( STDERR, sprintf( "  %-20s %s\n", $where, $version ) );
	}

	exit( 1 );
}

/*
 * The header's "Requires PHP" and "Requires at least" must match readme.txt
 * too, for the same reason.
 */
$pairs = array(
	'Requires PHP'      => '/^Requires PHP:\s*(\S+)\s*$/m',
	'Requires at least' => '/^Requires at least:\s*(\S+)\s*$/m',
);

foreach ( $pairs as $label => $readme_pattern ) {
	$header_pattern = '/^\s*\*\s*' . preg_quote( $label, '/' ) . ':\s*(\S+)\s*$/m';

	$in_header = preg_match( $header_pattern, $plugin_src, $a ) ? $a[1] : null;
	$in_readme = preg_match( $readme_pattern, $readme, $b ) ? $b[1] : null;

	if ( $in_header !== $in_readme ) {
		fwrite(
			STDERR,
			sprintf(
				"\"%s\" differs: plugin header says %s, readme.txt says %s\n",
				$label,
				var_export( $in_header, true ),
				var_export( $in_readme, true )
			)
		);
		exit( 1 );
	}
}

echo 'Versions agree: ' . reset( $found ) . "\n";
exit( 0 );
