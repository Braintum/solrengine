<?php
/**
 * The query-string convention for selected filters.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

/**
 * Reads and writes the filter selection carried in a URL.
 *
 * Filters live in the query string rather than in a session or a cookie, and
 * that is the whole design: a filtered search is a page that can be
 * bookmarked, shared, linked to from an email, crawled, and reached with the
 * browser's back button. A filter panel whose state lived anywhere else would
 * break all six.
 *
 * The shape is `?s=laptop&se_f[category][]=1/12/34&se_f[post_type][]=post`. It
 * is namespaced so it cannot collide with a theme's or another plugin's query
 * variables, and every key and value is validated against the registries before
 * it reaches a query — what arrives here is a string somebody typed into a URL
 * bar, and it is treated as one.
 *
 * @since 0.6.0
 */
final class FacetUrls {

	/**
	 * Query variable holding the selection.
	 *
	 * @var string
	 */
	public const PARAM = 'se_f';

	/**
	 * Most keys read from one URL.
	 *
	 * @var int
	 */
	private const MAX_KEYS = 10;

	/**
	 * Most values read for one key.
	 *
	 * @var int
	 */
	private const MAX_VALUES = 20;

	/**
	 * The selection a request carries.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string,string[]>
	 */
	public static function selected(): array {
		// A public read of a public parameter on a public page. There is no
		// state change to protect, and requiring a nonce would make a filtered
		// search impossible to link to, which is the point of putting the
		// selection in the URL at all.
		// Unslashed and sanitised in one step, at the point of entry, because
		// what arrives is a nested array and sanitising it a level at a time is
		// how one level gets missed.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a public parameter from a public URL; nothing changes state.
		$raw = isset( $_GET[ self::PARAM ] )
			? map_deep( wp_unslash( $_GET[ self::PARAM ] ), 'sanitize_text_field' )
			: array();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( ! is_array( $raw ) ) {
			return array();
		}

		$out = array();

		foreach ( $raw as $key => $values ) {
			$key = sanitize_key( (string) $key );

			if ( '' === $key || count( $out ) >= self::MAX_KEYS ) {
				continue;
			}

			$clean = array();

			foreach ( (array) $values as $value ) {
				if ( ! is_scalar( $value ) || count( $clean ) >= self::MAX_VALUES ) {
					continue;
				}

				if ( '' !== (string) $value ) {
					$clean[] = (string) $value;
				}
			}

			if ( array() !== $clean ) {
				$out[ $key ] = $clean;
			}
		}

		return $out;
	}

	/**
	 * The URL that adds or removes one value.
	 *
	 * Pagination is dropped, always. Changing a filter changes how many results
	 * there are, and keeping a visitor on page four of a result set that now has
	 * two pages shows them nothing and reads as a broken filter.
	 *
	 * @since 0.6.0
	 *
	 * @param string                 $key      Facet key.
	 * @param string                 $value    Value to toggle.
	 * @param array<string,string[]> $selected Current selection.
	 * @return string
	 */
	public static function toggle_url( string $key, string $value, array $selected ): string {
		$values = $selected[ $key ] ?? array();

		$selected[ $key ] = in_array( $value, $values, true )
			? array_values( array_diff( $values, array( $value ) ) )
			: array_merge( $values, array( $value ) );

		if ( array() === $selected[ $key ] ) {
			unset( $selected[ $key ] );
		}

		return self::url_for( $selected );
	}

	/**
	 * The URL with nothing selected.
	 *
	 * @since 0.6.0
	 *
	 * @return string
	 */
	public static function clear_url(): string {
		return self::url_for( array() );
	}

	/**
	 * Build a URL carrying a selection.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,string[]> $selected Selection to carry.
	 * @return string
	 */
	public static function url_for( array $selected ): string {
		$base = remove_query_arg( array( self::PARAM, 'paged', 'page' ) );

		if ( array() === $selected ) {
			return $base;
		}

		return add_query_arg( array( self::PARAM => $selected ), $base );
	}
}
