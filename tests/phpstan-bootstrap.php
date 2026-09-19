<?php
/**
 * Constants PHPStan needs to know about.
 *
 * These are defined at runtime by solrengine.php. Declaring them here keeps
 * static analysis honest without the analyser having to execute the plugin.
 *
 * @package SolrEngine
 */

define( 'SOLRENGINE_VERSION', '0.1.0' );
define( 'SOLRENGINE_PLUGIN_FILE', __DIR__ . '/../solrengine.php' );
define( 'SOLRENGINE_PLUGIN_DIR', __DIR__ . '/../' );
define( 'SOLRENGINE_PLUGIN_URL', 'https://example.test/wp-content/plugins/solrengine/' );
define( 'SOLRENGINE_MIN_PHP', '7.4' );
define( 'SOLRENGINE_MIN_WP', '6.5' );
define( 'SOLRENGINE_SCHEMA_CONTRACT_VERSION', 1 );
