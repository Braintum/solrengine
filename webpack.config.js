/**
 * Build configuration.
 *
 * Extends the @wordpress/scripts default rather than replacing it, so the
 * project inherits WordPress's Babel targets, externals and asset-manifest
 * generation. Only the entry point is declared, because the default convention
 * expects src/index.js and the file is more usefully named after what it does.
 *
 * @package SolrEngine
 */

const defaults = require( '@wordpress/scripts/config/webpack.config' );
const path = require( 'path' );

module.exports = {
	...defaults,
	entry: {
		'instant-search': path.resolve( __dirname, 'assets/src/instant-search.js' ),
		filters: path.resolve( __dirname, 'assets/src/filters.js' ),
	},
	output: {
		...defaults.output,
		path: path.resolve( __dirname, 'assets/build' ),
	},
};
