<?php
/**
 * The site's languages, from whichever plugin provides them.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Language;

use SolrEngine\Contracts\LanguageProvider;

/**
 * One answer to "what language is this", for everything that needs to ask.
 *
 * Four places need it: the document builder, the search integration, the filter
 * registry and the admin screens. Phase 6 and Phase 7 each found a defect caused
 * by two copies of the same question — content that reached the index on save and
 * vanished on the next full sync, and drift reported for ever — so this exists as
 * one object from the start rather than after the third time.
 *
 * It holds the providers in preference order and answers from the first that is
 * active. A site with both WPML and Polylang installed is broken in ways
 * SolrEngine cannot fix, but it will at least be consistently wrong rather than
 * differently wrong on each code path.
 *
 * Every answer is normalised through LanguageTag, so no caller has to know which
 * plugin spells a locale which way.
 *
 * @since 0.9.0
 */
final class Languages {

	/**
	 * Providers in preference order.
	 *
	 * @var LanguageProvider[]
	 */
	private $providers;

	/**
	 * Memoised active provider. False before it has been resolved.
	 *
	 * @var LanguageProvider|null|false
	 */
	private $active = false;

	/**
	 * Constructor.
	 *
	 * @param LanguageProvider[] $providers Providers in preference order.
	 */
	public function __construct( array $providers = array() ) {
		$this->providers = array();

		foreach ( $providers as $provider ) {
			if ( $provider instanceof LanguageProvider ) {
				$this->providers[] = $provider;
			}
		}
	}

	/**
	 * The provider answering for this site, or null when none is.
	 *
	 * @since 0.9.0
	 *
	 * @return LanguageProvider|null
	 */
	public function provider(): ?LanguageProvider {
		if ( false !== $this->active ) {
			return $this->active;
		}

		$this->active = null;

		foreach ( $this->providers as $provider ) {
			if ( $provider->is_active() ) {
				$this->active = $provider;

				break;
			}
		}

		/**
		 * Filters which provider SolrEngine reads languages from.
		 *
		 * The extension point for a multilingual plugin SolrEngine does not know
		 * about. Return an object implementing the LanguageProvider contract.
		 *
		 * @since 0.9.0
		 *
		 * @param LanguageProvider|null $provider  The provider chosen, or null.
		 * @param LanguageProvider[]    $available Every provider considered.
		 */
		$chosen = apply_filters( 'solrengine_language_provider', $this->active, $this->providers );

		if ( $chosen instanceof LanguageProvider ) {
			$this->active = $chosen;
		}

		return $this->active;
	}

	/**
	 * Whether this site publishes in more than one language.
	 *
	 * The test that gates every language-aware behaviour. A single-language site
	 * pays nothing: no field is written, no filter is applied, and no screen
	 * mentions languages.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function is_multilingual(): bool {
		return count( $this->all() ) > 1;
	}

	/**
	 * Every language this site publishes in, label keyed by BCP-47 tag.
	 *
	 * @since 0.9.0
	 *
	 * @return array<string,string>
	 */
	public function all(): array {
		$provider = $this->provider();

		if ( null === $provider ) {
			return array();
		}

		$out = array();

		foreach ( $provider->languages() as $tag => $label ) {
			$normalised = LanguageTag::normalise( (string) $tag );

			if ( LanguageTag::UNKNOWN === $normalised ) {
				continue;
			}

			$out[ $normalised ] = (string) $label;
		}

		return $out;
	}

	/**
	 * The language of the current request, or an empty string.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function current(): string {
		$provider = $this->provider();

		if ( null === $provider ) {
			return '';
		}

		return $this->known( $provider->current() );
	}

	/**
	 * The site's default language, or an empty string.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function default_language(): string {
		$provider = $this->provider();

		if ( null === $provider ) {
			return '';
		}

		return $this->known( $provider->default_language() );
	}

	/**
	 * The language of one post.
	 *
	 * Falls back to the site default rather than to "und" when the provider does
	 * not know. A post with no translation record on a multilingual site is
	 * almost always in the default language — it predates the plugin — and
	 * marking it unknown would exclude it from every language-scoped search,
	 * which is every search on such a site.
	 *
	 * @since 0.9.0
	 *
	 * @param int $post_id Post identifier.
	 * @return string BCP-47 tag, "und" when there is nothing to go on.
	 */
	public function of_post( int $post_id ): string {
		$provider = $this->provider();

		if ( null === $provider ) {
			return LanguageTag::UNKNOWN;
		}

		$tag = $this->known( $provider->of_post( $post_id ) );

		if ( '' !== $tag ) {
			return $tag;
		}

		$fallback = $this->default_language();

		return '' === $fallback ? LanguageTag::UNKNOWN : $fallback;
	}

	/**
	 * The primary subtags of every language here, for analyser selection.
	 *
	 * Deduplicated: a site publishing in pt-BR and pt-PT needs one Portuguese
	 * analyser, not two.
	 *
	 * @since 0.9.0
	 *
	 * @return string[]
	 */
	public function primary_subtags(): array {
		$out = array();

		foreach ( array_keys( $this->all() ) as $tag ) {
			$primary = LanguageTag::primary( (string) $tag );

			if ( '' !== $primary ) {
				$out[ $primary ] = $primary;
			}
		}

		return array_values( $out );
	}

	/**
	 * Normalise a tag, returning an empty string rather than "und".
	 *
	 * The distinction matters for a request and for a default, where "unknown"
	 * must mean "do not filter" rather than "filter on the unknown language".
	 *
	 * @param string $tag Tag to normalise.
	 * @return string
	 */
	private function known( string $tag ): string {
		$normalised = LanguageTag::normalise( $tag );

		return LanguageTag::UNKNOWN === $normalised ? '' : $normalised;
	}
}
