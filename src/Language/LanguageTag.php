<?php
/**
 * Language tag normalisation.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Language;

/**
 * Turns the many ways a language is spelled into one.
 *
 * WordPress says `en_US`, Polylang says `en`, WPML says `en` with a locale of
 * `en_US` beside it, and the plan's section 10.1 says the index holds a BCP-47
 * tag. Four spellings of one fact is three too many, and the failure mode is
 * quiet: a document indexed as `en_US` and a query filtered on `en-US` simply do
 * not match, and search returns nothing with no error anywhere.
 *
 * So every language reaching the index or a query passes through here first.
 *
 * Pure. No WordPress, no plugin, no network.
 *
 * @since 0.9.0
 */
final class LanguageTag {

	/**
	 * The tag used when a language is unknown.
	 *
	 * BCP-47 reserves "und" for exactly this. It is written rather than left
	 * absent so that "we do not know" and "nobody has indexed this yet" stay
	 * distinguishable in the index.
	 *
	 * @var string
	 */
	public const UNKNOWN = 'und';

	/**
	 * Normalise a locale or language code into a BCP-47 tag.
	 *
	 * @since 0.9.0
	 *
	 * @param string $locale Locale or language code, in any of the spellings above.
	 * @return string BCP-47 tag, or "und".
	 */
	public static function normalise( string $locale ): string {
		$locale = trim( str_replace( '_', '-', $locale ) );

		if ( '' === $locale ) {
			return self::UNKNOWN;
		}

		$parts = array_values(
			array_filter(
				explode( '-', $locale ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);

		if ( array() === $parts ) {
			return self::UNKNOWN;
		}

		$language = strtolower( $parts[0] );

		if ( 1 !== preg_match( '/^[a-z]{2,3}$/', $language ) ) {
			return self::UNKNOWN;
		}

		$tag = $language;

		// Only the two subtags that change how text is read are kept. WordPress
		// ships locales like de_DE_formal, where "formal" is a WordPress
		// convention rather than a BCP-47 subtag and describes how the interface
		// addresses the reader, not how German is written. Formal and informal
		// German analyse identically, so carrying it would split one language
		// into two for no gain.
		foreach ( array_slice( $parts, 1, 2 ) as $part ) {
			if ( 1 === preg_match( '/^[A-Za-z]{4}$/', $part ) ) {
				// A script, such as the Hans in zh_Hans. Title case by
				// convention: zh-Hans, not zh-hans.
				$tag .= '-' . ucfirst( strtolower( $part ) );

				continue;
			}

			if ( 1 === preg_match( '/^([A-Za-z]{2}|[0-9]{3})$/', $part ) ) {
				// A region, such as the BR in pt_BR. Upper case by convention.
				$tag .= '-' . strtoupper( $part );

				break;
			}

			break;
		}

		return $tag;
	}

	/**
	 * The language subtag alone, which is what an analyser is chosen by.
	 *
	 * Solr ships one analyser per language, not per locale: there is a `text_pt`
	 * and no `text_pt_BR`, because Brazilian and European Portuguese stem the
	 * same way. Regional variants therefore share an analyser and are still
	 * filtered apart by their full tag.
	 *
	 * @since 0.9.0
	 *
	 * @param string $tag Tag or locale.
	 * @return string Two- or three-letter subtag, or an empty string when unknown.
	 */
	public static function primary( string $tag ): string {
		$normalised = self::normalise( $tag );

		if ( self::UNKNOWN === $normalised ) {
			return '';
		}

		$parts = explode( '-', $normalised );

		return (string) $parts[0];
	}

	/**
	 * Whether a tag names a language SolrEngine can work with.
	 *
	 * @since 0.9.0
	 *
	 * @param string $tag Tag to check.
	 * @return bool
	 */
	public static function is_known( string $tag ): bool {
		return self::UNKNOWN !== self::normalise( $tag );
	}

	/**
	 * The Solr field type an analysed field for this language would need.
	 *
	 * @since 0.9.0
	 *
	 * @param string $tag Tag or locale.
	 * @return string Field type name, or an empty string when unknown.
	 */
	public static function field_type( string $tag ): string {
		$primary = self::primary( $tag );

		return '' === $primary ? '' : 'text_' . $primary;
	}

	/**
	 * The type prefix an analysed field for this language uses.
	 *
	 * Note the absence of a separator: `se_txten_title`, not `se_txt_en_title`.
	 * Solr's dynamic patterns match on a literal prefix, and `se_txt_*` matches
	 * `se_txt_en_title` — so a per-language field written with a separator lands
	 * in the language-neutral field type whenever its own pattern is missing,
	 * silently and with no error. Without the separator the two families cannot
	 * overlap: no existing pattern matches `se_txten_`.
	 *
	 * @since 0.9.0
	 *
	 * @param string $tag Tag or locale.
	 * @return string Type prefix, or an empty string when unknown.
	 */
	public static function field_prefix( string $tag ): string {
		$primary = self::primary( $tag );

		return '' === $primary ? '' : 'txt' . $primary;
	}

	/**
	 * The dynamic field pattern for a language's analysed fields.
	 *
	 * @since 0.9.0
	 *
	 * @param string $tag Tag or locale.
	 * @return string Pattern, or an empty string when unknown.
	 */
	public static function field_pattern( string $tag ): string {
		$prefix = self::field_prefix( $tag );

		return '' === $prefix ? '' : 'se_' . $prefix . '_*';
	}
}
