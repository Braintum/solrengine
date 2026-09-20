<?php
/**
 * WordPress's own locale as a language source.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\Multilingual;

use SolrEngine\Contracts\LanguageProvider;
use SolrEngine\Language\LanguageTag;

/**
 * The fallback: one site, one language, the one WordPress is set to.
 *
 * Always active and always last in the preference order, so that a site with no
 * multilingual plugin still records what language its content is in. That costs
 * one string per document and buys two things: a shared collection across a
 * network of single-language sites can be searched per language, and a site that
 * installs Polylang later has a language already on every document rather than
 * `und`.
 *
 * It reports exactly one language, so `Languages::is_multilingual()` is false and
 * every language-aware behaviour stays switched off. That is the correct answer
 * for a monolingual site, not a limitation.
 *
 * @since 0.9.0
 */
final class CoreProvider implements LanguageProvider {

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function id(): string {
		return 'core';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function label(): string {
		return __( 'WordPress site language', 'solrengine' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_active(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return array<string,string>
	 */
	public function languages(): array {
		$tag = $this->default_language();

		if ( '' === $tag ) {
			return array();
		}

		return array( $tag => $tag );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function current(): string {
		return $this->default_language();
	}

	/**
	 * {@inheritDoc}
	 *
	 * Deliberately `get_locale()` rather than `determine_locale()` or the user's
	 * own locale. A document's language is a property of the content, and an
	 * administrator reading wp-admin in French does not make an English post
	 * French.
	 *
	 * @return string
	 */
	public function default_language(): string {
		$tag = LanguageTag::normalise( (string) get_locale() );

		return LanguageTag::UNKNOWN === $tag ? '' : $tag;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param int $post_id Post identifier.
	 * @return string
	 */
	public function of_post( int $post_id ): string {
		unset( $post_id );

		return $this->default_language();
	}
}
