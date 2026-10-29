<?php
/**
 * WordPress object cache.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Cache;

/**
 * Stores values in WordPress's object cache.
 *
 * Without a persistent backend that means for the length of one request, which
 * is still worth having: a results page runs the same query more than once when a
 * theme asks for a count and then for the rows. With Redis or Memcached behind
 * it, entries outlive the request and several visitors share one Solr query.
 *
 * @since 1.0.0
 */
final class ObjectCache implements Cache {

	/**
	 * Cache group.
	 *
	 * @var string
	 */
	private $group;

	/**
	 * Constructor.
	 *
	 * @param string $group Cache group.
	 */
	public function __construct( string $group = 'solrengine' ) {
		$this->group = $group;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key Cache key.
	 * @return mixed The value, or null on a miss.
	 */
	public function get( string $key ) {
		$found = null;
		$value = wp_cache_get( $key, $this->group, false, $found );

		// wp_cache_get() reports a miss as false, which a stored false is
		// indistinguishable from. The $found flag is the only reliable answer,
		// and older drop-ins do not always set it — hence the fallback.
		if ( null !== $found ) {
			return $found ? $value : null;
		}

		return false === $value ? null : $value;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Seconds to keep it.
	 * @return void
	 */
	public function set( string $key, $value, int $ttl ): void {
		wp_cache_set( $key, $value, $this->group, max( 0, $ttl ) );
	}
}
