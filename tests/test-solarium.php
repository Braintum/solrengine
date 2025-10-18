<?php
/**
 * Test script to demonstrate Solarium client usage.
 *
 * @package SolrEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Test Solarium client functionality.
 */
function test_solarium_client() {
    if ( ! function_exists( 'solrengine_search_bootstrap' ) ) {
        return;
    }

    $plugin = solrengine_search_bootstrap();
    $client = $plugin->container()->get( 'solr.client' );
    
    echo '<h2>SolrEngine Client Test</h2>';
    
    // Show which client is being used
    if ( $client instanceof SolrEngine\Solr\SolariumClient ) {
        echo '<p style="color: green;">✅ Using <strong>Solarium Client</strong></p>';
    } else {
        echo '<p style="color: blue;">ℹ️ Using <strong>Default Client</strong></p>';
    }
    
    // Test search functionality
    try {
        $results = $client->search( '*:*', array( 'rows' => 5 ) );
        
        echo '<h3>Search Test Results:</h3>';
        echo '<pre>' . wp_json_encode( $results, JSON_PRETTY_PRINT ) . '</pre>';
        
        $doc_count = $results['body']['response']['numFound'] ?? 0;
        echo '<p>Found <strong>' . esc_html( $doc_count ) . '</strong> documents in Solr.</p>';
        
    } catch ( Exception $e ) {
        echo '<p style="color: red;">❌ Error: ' . esc_html( $e->getMessage() ) . '</p>';
    }
    
    // Show configuration
    echo '<h3>Current Configuration:</h3>';
    $config = $plugin->container()->get( 'config' );
    echo '<ul>';
    echo '<li><strong>Endpoint:</strong> ' . esc_html( $config->get( 'solr.endpoint' ) ) . '</li>';
    echo '<li><strong>Timeout:</strong> ' . esc_html( $config->get( 'solr.timeout' ) ) . 's</li>';
    echo '<li><strong>Client Type:</strong> ' . esc_html( $config->get( 'solr.client_type', 'default' ) ) . '</li>';
    echo '</ul>';
}

// Add admin page for testing (only for development)
if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
    add_action( 'admin_menu', function() {
        add_management_page(
            'SolrEngine Test',
            'SolrEngine Test',
            'manage_options',
            'solrengine-test',
            'test_solarium_client'
        );
    });
}