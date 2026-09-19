<?php
/**
 * Naming rules for collections and cores.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Backend\Solr;

/**
 * Validates and derives collection names.
 *
 * Two jobs, both small and both worth isolating. The first is a guard: a
 * collection name becomes a path segment in a URL, so a name carrying a slash or
 * a dot-dot would reach a different handler than the one intended. Names arrive
 * from configuration and from Solr itself, neither of which is user input today,
 * which is exactly the condition under which a check gets skipped and then stops
 * being true later.
 *
 * The second is generation naming for a rebuild. The live name is generation one
 * whether or not it says so, so the first replacement is "_v2" and an
 * administrator can read the history of a collection from the cluster listing
 * without consulting SolrEngine.
 *
 * Pure. No network, no WordPress.
 *
 * @since 0.8.0
 */
final class CollectionName {

	/**
	 * Longest name accepted.
	 *
	 * Solr imposes no documented limit, but a collection name becomes part of a
	 * ZooKeeper path and of a directory name on disk, and the generation suffix
	 * has to fit as well.
	 *
	 * @var int
	 */
	public const MAX_LENGTH = 100;

	/**
	 * Whether a name is safe to use as a path segment and acceptable to Solr.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Candidate name.
	 * @return bool
	 */
	public static function is_valid( string $name ): bool {
		if ( '' === $name || strlen( $name ) > self::MAX_LENGTH ) {
			return false;
		}

		// A leading dot or hyphen is refused as well as the obvious characters:
		// Solr reserves names beginning with a dot, and a leading hyphen is read
		// as a flag by enough tooling to be worth avoiding.
		return 1 === preg_match( '/^[A-Za-z0-9_][A-Za-z0-9_.-]*$/', $name );
	}

	/**
	 * The base name, with any generation suffix removed.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Collection name.
	 * @return string
	 */
	public static function base( string $name ): string {
		return (string) preg_replace( '/_v[0-9]+$/', '', $name );
	}

	/**
	 * The generation a name declares. Names without a suffix are generation one.
	 *
	 * @since 0.8.0
	 *
	 * @param string $name Collection name.
	 * @return int
	 */
	public static function generation( string $name ): int {
		if ( 1 === preg_match( '/_v([0-9]+)$/', $name, $matches ) ) {
			return (int) $matches[1];
		}

		return 1;
	}

	/**
	 * The name the next rebuild should write to.
	 *
	 * Derived from every existing name that shares the base, not from the live
	 * one alone. A rebuild that was abandoned halfway leaves its collection
	 * behind, and reusing that name would index into a half-built index that
	 * somebody may still be looking at.
	 *
	 * @since 0.8.0
	 *
	 * @param string   $current  The live collection or alias name.
	 * @param string[] $existing Names already present on the server.
	 * @return string
	 */
	public static function next( string $current, array $existing = array() ): string {
		$base   = self::base( $current );
		$latest = self::generation( $current );

		foreach ( $existing as $name ) {
			if ( self::base( (string) $name ) !== $base ) {
				continue;
			}

			$latest = max( $latest, self::generation( (string) $name ) );
		}

		return $base . '_v' . ( $latest + 1 );
	}
}
