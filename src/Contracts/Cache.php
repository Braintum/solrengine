<?php
/**
 * Short-lived storage for computed results.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A place to keep a result for a little while.
 *
 * {@see \SolrEngine\Search\SearchService} caches result sets and suggestion
 * lists, which is the difference between one Solr query per visitor and one per
 * keystroke on a page several visitors are typing into at once.
 *
 * Extracted so the service holds no WordPress call of its own. Reaching
 * `wp_cache_get()` from inside it was the last reason its unit tests needed
 * WordPress loaded, and the last thing stopping it from running in a process that
 * has no object cache started.
 *
 * Implementations must treat a miss as `null` rather than `false`, because a
 * cached value may legitimately be falsey.
 *
 * @since 0.10.2
 */
interface Cache {

	/**
	 * Read a value.
	 *
	 * @since 0.10.2
	 *
	 * @param string $key Cache key.
	 * @return mixed The value, or null on a miss.
	 */
	public function get( string $key );

	/**
	 * Store a value.
	 *
	 * @since 0.10.2
	 *
	 * @param string $key   Cache key.
	 * @param mixed  $value Value to store.
	 * @param int    $ttl   Seconds to keep it.
	 * @return void
	 */
	public function set( string $key, $value, int $ttl ): void;
}
