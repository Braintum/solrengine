<?php
/**
 * WPML as a language source.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\Multilingual;

use SolrEngine\Contracts\LanguageProvider;
use SolrEngine\Language\LanguageTag;

/**
 * Reads languages from WPML.
 *
 * Through WPML's filter API — `wpml_active_languages`, `wpml_current_language`,
 * `wpml_post_language_details` — rather than through `$sitepress`, its global
 * object. The filters are documented and stable; the global is an internal class
 * whose methods have moved between releases, and reaching for it is how an
 * integration acquires a dependency on a version nobody is running any more.
 *
 * WPML reports both a code and a locale per language. The locale is used, for the
 * same reason as in the Polylang provider: a code of `pt` cannot distinguish
 * Brazilian from European Portuguese, and a site publishing in both needs them
 * kept apart in the index even though they share one analyser.
 *
 * @since 0.9.0
 */
final class WpmlProvider implements LanguageProvider {
	/*
	 * Every apply_filters() below reads a value WPML provides. The prefix sniff
	 * cannot tell a hook we fire from a hook we consume, and prefixing these
	 * with "solrengine_" would call hooks that do not exist. Disabled for this
	 * class alone, which contains nothing but calls into WPML's documented
	 * filter API.
	 *
	 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
	 */

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'wpml';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'WPML', 'solrengine' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * Tested by asking rather than by looking for a constant. WPML's constants
	 * have been renamed across major versions, and a site may have the plugin
	 * present but not configured — in which case its filters return nothing and
	 * reporting it active would give SolrEngine an empty language list to work
	 * from, which reads as "this site has no languages".
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		if ( ! defined( 'ICL_SITEPRESS_VERSION' ) ) {
			return false;
		}

		return array() !== $this->active_languages();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function languages(): array {
		$out = array();

		foreach ( $this->active_languages() as $code => $language ) {
			$locale = (string) ( $language['default_locale'] ?? $code );
			$tag    = LanguageTag::normalise( $locale );

			if ( LanguageTag::UNKNOWN === $tag ) {
				continue;
			}

			$out[ $tag ] = (string) ( $language['translated_name'] ?? $language['native_name'] ?? $tag );
		}

		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current(): string {
		$code = apply_filters( 'wpml_current_language', null );

		if ( ! is_string( $code ) || '' === $code || 'all' === $code ) {
			// "all" is WPML's own value for a request that spans every
			// language, which is exactly the case where nothing should be
			// filtered.
			return '';
		}

		return $this->locale_of( $code );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function default_language(): string {
		$code = apply_filters( 'wpml_default_language', null );

		return is_string( $code ) ? $this->locale_of( $code ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function of_post( int $post_id ): string {
		$details = apply_filters(
			'wpml_post_language_details',
			null,
			$post_id
		);

		if ( ! is_array( $details ) ) {
			return '';
		}

		if ( isset( $details['locale'] ) && is_string( $details['locale'] ) ) {
			$tag = LanguageTag::normalise( $details['locale'] );

			return LanguageTag::UNKNOWN === $tag ? '' : $tag;
		}

		return isset( $details['language_code'] ) && is_string( $details['language_code'] )
			? $this->locale_of( $details['language_code'] )
			: '';
	}

	/**
	 * WPML's active languages, keyed by its own code.
	 *
	 * @return array<string,array<string,mixed>>
	 */
	private function active_languages(): array {
		$languages = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) );

		return is_array( $languages ) ? $languages : array();
	}

	/**
	 * Turn a WPML language code into a normalised tag, using its locale.
	 *
	 * @param string $code WPML language code.
	 * @return string
	 */
	private function locale_of( string $code ): string {
		$languages = $this->active_languages();
		$locale    = (string) ( $languages[ $code ]['default_locale'] ?? $code );
		$tag       = LanguageTag::normalise( $locale );

		return LanguageTag::UNKNOWN === $tag ? '' : $tag;
	}
}
