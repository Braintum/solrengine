<?php
/**
 * Prefix matching for text that is still being typed.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

/**
 * Which word of typed text may be matched as a prefix, and how.
 *
 * Someone typing "hoo" is part-way through "hoodie". Only the last word is
 * completed, because it is the only one still being typed: the earlier words
 * are finished and match as written.
 *
 * A wildcard is added only to a plain word of letters and digits, at least
 * {@see self::MIN_LENGTH} long. Anything else is left as the visitor typed it,
 * so this never assembles query syntax out of punctuation. One character is
 * refused because it expands to most of the term dictionary: measured on the
 * development index, a one-letter prefix took several times as long as a
 * two-letter one.
 *
 * The one place this rule lives. Autocomplete ({@see SuggestTranslator}) and
 * the search popup ({@see QueryTranslator}, for a query that asks for
 * completion) both apply it.
 *
 * @since 0.11.0
 */
final class PrefixTerms {

	/**
	 * Shortest word completed.
	 *
	 * @var int
	 */
	public const MIN_LENGTH = 2;

	/**
	 * Whether a word may be completed with a wildcard.
	 *
	 * @since 0.11.0
	 *
	 * @param string $word One word.
	 * @return bool
	 */
	public static function completable( string $word ): bool {
		return 1 === preg_match( '/^[\p{L}\p{N}]{' . self::MIN_LENGTH . ',}$/u', $word );
	}

	/**
	 * The text with its last word completed, or unchanged when it cannot be.
	 *
	 * @since 0.11.0
	 *
	 * @param string $text Cleaned text: trimmed, spaces collapsed.
	 * @return string
	 */
	public static function complete_last( string $text ): string {
		$space = strrpos( $text, ' ' );
		$last  = false === $space ? $text : substr( $text, $space + 1 );

		return self::completable( $last ) ? $text . '*' : $text;
	}
}
