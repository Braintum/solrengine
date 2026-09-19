<?php
/**
 * The materialised taxonomy path format.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

use WP_Term;

/**
 * Reads and writes the paths a hierarchical taxonomy is indexed under.
 *
 * One place for the format, because it is written by the document builder and
 * read by the facet layer, and those two disagreeing is a bug that shows up as
 * a category filter that quietly matches nothing.
 *
 * A term at 12 > 34 > 56 is indexed under "0/12", "1/12/34" and "2/12/34/56":
 * the lineage of identifiers, prefixed with its depth. The depth is what makes
 * one level of the tree selectable with a prefix — "0/" is the top level,
 * "1/12/" is the children of 12 — and matching on a path rather than on a term
 * identifier is what makes "in Guides" mean "in Guides or anything below it",
 * which is what a visitor ticking that box means by it.
 *
 * @since 0.6.0
 */
final class TermPaths {

	/**
	 * Every path a term should be indexed under.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_Term|int $term     Term or term identifier.
	 * @param string      $taxonomy Taxonomy name.
	 * @return string[] Ordered from the root down to the term itself.
	 */
	public static function lineage( $term, string $taxonomy ): array {
		$term_id = $term instanceof WP_Term ? (int) $term->term_id : (int) $term;

		if ( $term_id <= 0 ) {
			return array();
		}

		$ancestors = get_ancestors( $term_id, $taxonomy, 'taxonomy' );
		$lineage   = array_reverse( array_map( 'intval', (array) $ancestors ) );
		$lineage[] = $term_id;

		$paths = array();
		$built = array();

		foreach ( $lineage as $depth => $id ) {
			$built[] = (string) $id;
			$paths[] = $depth . '/' . implode( '/', $built );
		}

		return $paths;
	}

	/**
	 * The path identifying one term.
	 *
	 * @since 0.6.0
	 *
	 * @param WP_Term|int $term     Term or term identifier.
	 * @param string      $taxonomy Taxonomy name.
	 * @return string Empty when the term has no usable lineage.
	 */
	public static function path_for( $term, string $taxonomy ): string {
		$lineage = self::lineage( $term, $taxonomy );

		return array() === $lineage ? '' : (string) end( $lineage );
	}

	/**
	 * The term a path names.
	 *
	 * @since 0.6.0
	 *
	 * @param string $path Materialised path.
	 * @return int Term identifier, or 0 when the path is not one.
	 */
	public static function term_id( string $path ): int {
		if ( 1 !== preg_match( '#^[0-9]+(/[0-9]+)+$#', $path ) ) {
			return 0;
		}

		$segments = explode( '/', $path );

		return (int) end( $segments );
	}

	/**
	 * The path of the node one level above.
	 *
	 * @since 0.6.0
	 *
	 * @param string $path Materialised path.
	 * @return string Empty when the path is at the top level.
	 */
	public static function parent_of( string $path ): string {
		$segments = explode( '/', $path );

		if ( count( $segments ) <= 2 ) {
			return '';
		}

		array_shift( $segments );
		array_pop( $segments );

		return ( count( $segments ) - 1 ) . '/' . implode( '/', $segments );
	}

	/**
	 * Whether a string is shaped like a path rather than like a term id.
	 *
	 * Used where a caller may legitimately send either: a REST client filtering
	 * by "12" means the term, and one filtering by "1/12/34" is echoing a
	 * bucket it was given.
	 *
	 * @since 0.6.0
	 *
	 * @param string $value Value to inspect.
	 * @return bool
	 */
	public static function is_path( string $value ): bool {
		return 1 === preg_match( '#^[0-9]+(/[0-9]+)+$#', $value );
	}
}
