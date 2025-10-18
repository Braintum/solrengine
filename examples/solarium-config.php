<?php
/**
 * Example configuration for using Solarium client.
 *
 * @package SolrEngine
 */

// Example 1: Enable Solarium client via WordPress filter
add_filter( 'solrengine_config', function( $config ) {
    $config['solr']['client_type'] = 'solarium';
    return $config;
});

// Example 2: Enable Solarium client via environment variable
// Set in wp-config.php or .env file:
// define( 'SOLRENGINE_CLIENT_TYPE', 'solarium' );

// Example 3: Enable Solarium client programmatically
add_action( 'plugins_loaded', function() {
    if ( function_exists( 'solrengine_search_bootstrap' ) ) {
        $plugin = solrengine_search_bootstrap();
        $config = $plugin->container()->get( 'config' );
        $config->set( 'solr.client_type', 'solarium' );
    }
});

/**
 * Benefits of using Solarium:
 * 
 * 1. More robust and feature-rich than the default client
 * 2. Better error handling and debugging
 * 3. Built-in support for advanced Solr features:
 *    - Faceting
 *    - Highlighting
 *    - Suggestions
 *    - More like this
 *    - Statistics
 *    - Terms
 *    - Spatial search
 * 
 * 4. Active maintenance and community support
 * 5. Comprehensive documentation and examples
 * 
 * Installation:
 * 1. Run: composer require solarium/solarium
 * 2. Enable Solarium client using one of the methods above
 * 3. The plugin will automatically use Solarium when available
 */