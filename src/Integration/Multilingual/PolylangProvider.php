<?php
/**
 * Polylang as a language source.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\Multilingual;

use SolrEngine\Contracts\LanguageProvider;
use SolrEngine\Language\LanguageTag;

/**
 * Reads languages from Polylang.
 *
 * Through Polylang's own public functions, never through its tables or its
 * taxonomy terms. Polylang models a language as a term in a private `language`
 * taxonomy with its locale in the term description, which is an implementation
 * detail that has changed between major versions; `pll_get_post_language()` has
 * not. This is the same reasoning ADR-006 applies to WooCommerce, where reading
 * post meta instead of the CRUD layer is the mistake that makes an integration
 * break on every upgrade.
 *
 * Locales are asked for rather than slugs. Polylang's slug for a language is
 * often bare — `en`, `pt` — while its locale carries the region, and a site
 * publishing in both Brazilian and European Portuguese needs the two kept apart.
 *
 * @since 0.9.0
 */
final class PolylangProvider implements LanguageProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'polylang';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'Polylang', 'solrengine' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Every function used below is checked, not just one. Polylang Pro, the free
	 * plugin and the "Polylang for WooCommerce" add-on expose overlapping subsets,
	 * and a partial load is how an integration ends up fataling on a site that
	 * merely has an unusual combination installed.
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_get_post_language' )
			&& function_exists( 'pll_default_language' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function languages(): array {
		$locales = pll_languages_list( array( 'fields' => 'locale' ) );
		$names   = pll_languages_list( array( 'fields' => 'name' ) );

		if ( ! is_array( $locales ) ) {
			return array();
		}

		$names = is_array( $names ) ? array_values( $names ) : array();
		$out   = array();

		foreach ( array_values( $locales ) as $index => $locale ) {
			$tag = LanguageTag::normalise( (string) $locale );

			if ( LanguageTag::UNKNOWN === $tag ) {
				continue;
			}

			$out[ $tag ] = isset( $names[ $index ] ) ? (string) $names[ $index ] : $tag;
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current(): string {
		if ( ! function_exists( 'pll_current_language' ) ) {
			return '';
		}

		return $this->tag( (string) pll_current_language( 'locale' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language(): string {
		return $this->tag( (string) pll_default_language( 'locale' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function of_post( int $post_id ): string {
		$locale = pll_get_post_language( $post_id, 'locale' );

		return is_string( $locale ) ? $this->tag( $locale ) : '';
	}

	/**
	 * Normalise, mapping the unknown tag to an empty string.
	 *
	 * @param string $locale Locale from Polylang.
	 * @return string
	 */
	private function tag( string $locale ): string {
		$tag = LanguageTag::normalise( $locale );

		return LanguageTag::UNKNOWN === $tag ? '' : $tag;
	}
}
