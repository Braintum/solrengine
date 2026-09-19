<?php
/**
 * Where a product is allowed to appear.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WooCommerce;

/**
 * Turns WooCommerce's catalogue visibility into something a query can filter on.
 *
 * WooCommerce has four visibility states and two places a product can show up,
 * and the mapping between them is not one-to-one: "catalog" means visible in
 * the shop and hidden from search, "search" means the reverse. Expressing that
 * as a multi-valued list of the contexts a product belongs in turns four states
 * and two contexts into one field and one clause.
 *
 * A hidden product gets no contexts at all, so it matches no context filter —
 * and the document builder refuses to index it in the first place. Two
 * independent guarantees for the case that costs a merchant money if it is
 * wrong.
 *
 * @since 0.7.0
 */
final class CatalogContext {

	/**
	 * A search: the site's search results, the REST search endpoint, suggest.
	 *
	 * @var string
	 */
	public const SEARCH = 'search';

	/**
	 * A catalogue listing: the shop page, a product archive, a collection block.
	 *
	 * @var string
	 */
	public const CATALOG = 'catalog';

	/**
	 * The contexts a catalogue visibility allows.
	 *
	 * @since 0.7.0
	 *
	 * @param string $visibility One of visible, catalog, search, hidden.
	 * @return string[]
	 */
	public static function for_visibility( string $visibility ): array {
		switch ( $visibility ) {
			case 'hidden':
				return array();

			case 'catalog':
				return array( self::CATALOG );

			case 'search':
				return array( self::SEARCH );

			case 'visible':
				return array( self::CATALOG, self::SEARCH );

			default:
				// An unrecognised value is treated as fully visible, matching
				// WooCommerce, which falls back to "visible" for anything it
				// does not know. Being wrong in the other direction would hide
				// a merchant's whole catalogue on a plugin conflict.
				return array( self::CATALOG, self::SEARCH );
		}
	}

	/**
	 * The contexts a catalogue visibility keeps a product out of.
	 *
	 * The inverse of for_visibility(), and the form the index actually stores:
	 * a query excludes what a product is excluded from, so a document with
	 * nothing to hide carries no field and passes every context filter.
	 *
	 * @since 0.7.0
	 *
	 * @param string $visibility One of visible, catalog, search, hidden.
	 * @return string[]
	 */
	public static function exclusions_for( string $visibility ): array {
		$allowed = self::for_visibility( $visibility );

		return array_values( array_diff( array( self::SEARCH, self::CATALOG ), $allowed ) );
	}

	/**
	 * Whether a visibility means the product should not be indexed at all.
	 *
	 * @since 0.7.0
	 *
	 * @param string $visibility Catalogue visibility.
	 * @return bool
	 */
	public static function is_hidden( string $visibility ): bool {
		return array() === self::for_visibility( $visibility );
	}

	/**
	 * Whether a context name is one of the two.
	 *
	 * @since 0.7.0
	 *
	 * @param string $context Context name.
	 * @return bool
	 */
	public static function is_valid( string $context ): bool {
		return in_array( $context, array( self::SEARCH, self::CATALOG ), true );
	}
}
