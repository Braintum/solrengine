<?php
/**
 * Print the WooCommerce version a CI job should test against.
 *
 * The compatibility document promises the current stable WooCommerce and the
 * previous minor. "latest" is the directory's stable version; "previous" is the
 * newest stable release of the minor before it — for 11.2.1, the last 11.1.x.
 * Read from the plugin directory's API on every run, so the matrix follows
 * WooCommerce's releases without anybody editing a version by hand.
 *
 * Usage: php bin/woocommerce-version.php latest|previous
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

$which = $argv[1] ?? 'latest';

if ( ! in_array( $which, array( 'latest', 'previous' ), true ) ) {
	fwrite( STDERR, "Usage: php bin/woocommerce-version.php latest|previous\n" );
	exit( 1 );
}

$response = file_get_contents( 'https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&request[slug]=woocommerce&request[fields][versions]=1' );
$info     = is_string( $response ) ? json_decode( $response, true ) : null;

if ( ! is_array( $info ) || empty( $info['version'] ) || empty( $info['versions'] ) || ! is_array( $info['versions'] ) ) {
	fwrite( STDERR, "Could not read WooCommerce's versions from the plugin directory.\n" );
	exit( 1 );
}

$latest = (string) $info['version'];

if ( 'latest' === $which ) {
	echo $latest . "\n";
	exit( 0 );
}

/**
 * The major.minor part of a version.
 *
 * @param string $version Version.
 * @return string
 */
function solrengine_minor( string $version ): string {
	return implode( '.', array_slice( explode( '.', $version ), 0, 2 ) );
}

$earlier = array_filter(
	array_map( 'strval', array_keys( $info['versions'] ) ),
	static function ( string $version ) use ( $latest ): bool {
		// Stable releases only: no betas, release candidates or "trunk".
		return 1 === preg_match( '/^\d+\.\d+\.\d+$/', $version )
			&& version_compare( solrengine_minor( $version ), solrengine_minor( $latest ), '<' );
	}
);

if ( array() === $earlier ) {
	fwrite( STDERR, "WooCommerce has no stable release before {$latest}'s minor.\n" );
	exit( 1 );
}

usort( $earlier, 'version_compare' );

echo end( $earlier ) . "\n";
exit( 0 );
