<?php
/**
 * Meta key discovery.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

/**
 * Finds the meta keys a site actually uses.
 *
 * The allowlist is the right design — the 0.1.0 prototype indexed every meta key
 * including private ones, which puts licence keys and editorial notes into a
 * search index — but an allowlist is only usable if you can see what there is to
 * allow. Nobody remembers the meta key their events plugin writes.
 *
 * Cached, because the query is a grouped scan of the biggest table on most
 * WordPress sites. An hour is the right window: new meta keys appear when a
 * plugin is installed, which is not something anybody does twice in an afternoon
 * while staring at this screen.
 *
 * @since 0.8.0
 */
final class MetaKeys {

	/**
	 * Cache key.
	 *
	 * @var string
	 */
	private const TRANSIENT = 'solrengine_meta_keys';

	/**
	 * How long the discovery is cached, in seconds.
	 *
	 * @var int
	 */
	private const TTL = 3600;

	/**
	 * Keys discovered, most used first.
	 *
	 * @since 0.8.0
	 *
	 * @param int  $limit         How many keys to return.
	 * @param bool $with_private  Whether to include keys beginning with "_".
	 * @return array<string,int> Post count keyed by meta key.
	 */
	public static function discover( int $limit = 150, bool $with_private = false ): array {
		$all = self::all( $limit );

		if ( $with_private ) {
			return $all;
		}

		return array_filter(
			$all,
			static function ( $count, $key ): bool {
				unset( $count );

				return ! MetaAllowlist::is_private( (string) $key );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}

	/**
	 * Forget the cached discovery.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public static function forget(): void {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Every key, cached.
	 *
	 * Private keys are fetched as well as public ones, and filtered afterwards,
	 * so that switching the "show private keys" disclosure open does not cost a
	 * second scan of the postmeta table.
	 *
	 * @param int $limit How many keys to return.
	 * @return array<string,int>
	 */
	private static function all( int $limit ): array {
		$limit  = max( 1, min( 500, $limit ) );
		$cached = get_transient( self::TRANSIENT );

		if ( is_array( $cached ) ) {
			return array_slice( $cached, 0, $limit, true );
		}

		global $wpdb;

		/*
		 * Ordered by frequency rather than alphabetically: the key used by
		 * forty thousand posts is the one somebody wants to index, and the key
		 * used by one is usually a leftover from a plugin they removed.
		 *
		 * The cap is applied in SQL as well as in PHP. A site with a hundred
		 * thousand distinct meta keys exists, and reading them all into memory
		 * to throw most away would be the expensive way to find that out.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_key AS mkey, COUNT(*) AS total
				 FROM {$wpdb->postmeta}
				 GROUP BY meta_key
				 ORDER BY total DESC
				 LIMIT %d",
				500
			),
			ARRAY_A
		);

		$found = array();

		foreach ( (array) $rows as $row ) {
			$key = (string) ( $row['mkey'] ?? '' );

			if ( '' === $key ) {
				continue;
			}

			$found[ $key ] = (int) ( $row['total'] ?? 0 );
		}

		set_transient( self::TRANSIENT, $found, self::TTL );

		return array_slice( $found, 0, $limit, true );
	}
}
