# SolrEngine - Solarium Integration

## Overview

SolrEngine now supports **two Solr client implementations**:

1. **Default Client** - Built-in WordPress HTTP API based client
2. **Solarium Client** - Advanced PHP Solr client library

## Why Use Solarium?

[Solarium](https://solarium.readthedocs.io/) is a mature, feature-rich PHP library for Apache Solr with:

- ✅ **Advanced Features**: Faceting, highlighting, suggestions, spatial search
- ✅ **Better Error Handling**: Comprehensive exception handling and debugging
- ✅ **Active Development**: Regular updates and community support
- ✅ **Extensive Documentation**: Well-documented API and examples
- ✅ **Performance**: Optimized for high-performance applications

## Installation

### Step 1: Install Solarium

```bash
# Navigate to your plugin directory
cd /path/to/wp-content/plugins/solrengine

# Install Solarium via Composer
composer require solarium/solarium
```

### Step 2: Enable Solarium Client

Choose one of these methods:

#### Method 1: WordPress Filter (Recommended)

Add to your theme's `functions.php` or a mu-plugin:

```php
add_filter( 'solrengine_config', function( $config ) {
    $config['solr']['client_type'] = 'solarium';
    return $config;
});
```

#### Method 2: Environment Variable

Add to `wp-config.php`:

```php
define( 'SOLRENGINE_CLIENT_TYPE', 'solarium' );
```

#### Method 3: Programmatically

```php
add_action( 'plugins_loaded', function() {
    if ( function_exists( 'solrengine_search_bootstrap' ) ) {
        $plugin = solrengine_search_bootstrap();
        $config = $plugin->container()->get( 'config' );
        $config->set( 'solr.client_type', 'solarium' );
    }
});
```

## Configuration

The Solarium client uses the same configuration as the default client:

```php
$defaults = array(
    'solr' => array(
        'endpoint'    => 'http://127.0.0.1:8983/solr/wordpress',
        'timeout'     => 5,
        'client_type' => 'solarium', // Enable Solarium
    ),
);
```

## Client Detection

SolrEngine automatically detects which client to use:

1. If `client_type` is set to `'solarium'` AND Solarium is installed → **SolariumClient**
2. Otherwise → **Default SolrClient**

## API Compatibility

Both clients implement the same `SolrClientInterface`, so switching between them requires no code changes:

```php
interface SolrClientInterface {
    public function update( array $docs, array $options = array() );
    public function deleteById( array $ids );
    public function search( string $q, array $params = array() );
}
```

## Advanced Features (Future)

With Solarium as the foundation, future versions can support:

- **Faceted Search**: Category and attribute filtering
- **Auto-suggestions**: Search-as-you-type functionality
- **Highlighting**: Search term highlighting in results
- **Spatial Search**: Geographic and location-based search
- **Statistics**: Advanced analytics and reporting
- **More Like This**: Related content suggestions

## Troubleshooting

### Solarium Not Loading

1. **Check Composer**: Ensure `vendor/autoload.php` is loaded
2. **Check Installation**: Run `composer show solarium/solarium`
3. **Check PHP Version**: Solarium requires PHP 7.3+
4. **Check Configuration**: Verify client_type setting

### Debugging

Enable WordPress debug logging to see which client is being used:

```php
// Check current client type
$plugin = solrengine_search_bootstrap();
$container = $plugin->container();
$client = $container->get( 'solr.client' );

if ( $client instanceof SolrEngine\\Solr\\SolariumClient ) {
    error_log( 'Using Solarium client' );
} else {
    error_log( 'Using default client' );
}
```

## Performance

Solarium typically offers better performance due to:
- Connection pooling and reuse
- Optimized request building
- Efficient response parsing
- Built-in caching mechanisms

## Migration

Switching between clients is seamless:
- No data migration required
- Same search results and functionality
- Configuration remains unchanged
- Existing indexed data works with both clients