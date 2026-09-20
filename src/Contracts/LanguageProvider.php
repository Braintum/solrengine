<?php
/**
 * Language provider contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Where a site's languages come from.
 *
 * WordPress has no multilingual model. It has a locale, and everything above
 * that is a plugin — WPML, Polylang, TranslatePress, and several more, each with
 * its own idea of what a language is attached to. The plan's risk 11 is explicit
 * that SolrEngine should "integrate with WPML and Polylang rather than inventing
 * a language model", and this is the seam that keeps that promise: SolrEngine
 * asks three questions and does not care who answers them.
 *
 * It is a contract rather than a filter because the three questions are
 * different in kind. "What language is this post in" could be a filter returning
 * a string; "what languages does this site have" and "which one is this request"
 * cannot be, and a site that answered one without the others would index
 * languages it could never search for.
 *
 * Implementations must not throw, and must return an empty language rather than
 * guessing. A wrong language is worse than no language: it filters a document
 * out of the only search that would have found it.
 *
 * @since 0.9.0 Unstable until 1.0.
 */
interface LanguageProvider {

	/**
	 * Stable identifier, for example "core", "polylang" or "wpml".
	 *
	 * Shown in the admin so an administrator can see which plugin SolrEngine is
	 * reading languages from, which is the first thing to check when a
	 * translation is missing from search.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Human-readable name of the source.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function label(): string;

	/**
	 * Whether this provider can answer for this site right now.
	 *
	 * False when the plugin it reads is not active. Checked before every other
	 * method, so implementations may assume it when answering the rest.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function is_active(): bool;

	/**
	 * The languages this site publishes in.
	 *
	 * Keyed by BCP-47 tag — see Language\LanguageTag, which every implementation
	 * must normalise through, because the plugins disagree about spelling and a
	 * document indexed as "en_US" never matches a query filtered on "en-US".
	 *
	 * @since 0.9.0
	 *
	 * @return array<string,string> Label keyed by tag.
	 */
	public function languages(): array;

	/**
	 * The language of the current request.
	 *
	 * An empty string when it cannot be determined, which must be treated as
	 * "do not filter by language" rather than as a language of its own.
	 *
	 * @since 0.9.0
	 *
	 * @return string BCP-47 tag, or an empty string.
	 */
	public function current(): string;

	/**
	 * The site's default language.
	 *
	 * @since 0.9.0
	 *
	 * @return string BCP-47 tag, or an empty string.
	 */
	public function default_language(): string;

	/**
	 * The language of one post.
	 *
	 * @since 0.9.0
	 *
	 * @param int $post_id Post identifier.
	 * @return string BCP-47 tag, or an empty string when unknown.
	 */
	public function of_post( int $post_id ): string;
}
