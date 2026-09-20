<?php
/**
 * Per-language analysed fields.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Contracts\SchemaField;
use SolrEngine\Contracts\SchemaState;
use SolrEngine\Language\LanguageTag;

/**
 * The dynamic field patterns a language-aware index needs, and which of them a
 * given Solr can actually support.
 *
 * Language-aware search means analysing text with an analyser that knows the
 * language: English stems "running" to "run", German splits compound words,
 * Turkish lower-cases a dotted capital I differently from everyone else. None of
 * that can be done by the language-neutral analyser, because it has to be right
 * for every site at once.
 *
 * Each language therefore needs its own Solr field type, and **SolrEngine does
 * not create field types** — rule 2 of ADR-003, because an analyser is a far
 * larger commitment than a field and a wrong one is not recoverable by adding
 * another field. Solr's own `_default` configset ships `text_general` and
 * `text_en` and nothing else; the full set of language analysers lives in its
 * `sample_techproducts` configset and in SolrEngine's own.
 *
 * So the question is not "which languages does this site have" but "which of
 * them can this server analyse", and the answer is read from the live schema.
 * A language whose analyser is absent is not an error: its content is still
 * indexed and still searchable through the neutral fields, and the only thing
 * missing is stemming.
 *
 * These are written per document rather than declared as copyField rules, and
 * that is not an oversight. A copyField is unconditional: `copyField
 * source="se_txt_title" dest="se_txten_title"` would analyse every document as
 * English, German ones included, and a German post run through an English
 * stemmer produces terms nobody will ever search for. The copy has to depend on
 * the document's own language, which only the document builder knows.
 *
 * Pure. Takes a SchemaState, which is a value object, and returns values.
 *
 * @since 0.9.0
 */
final class LanguageFields {

	/**
	 * The parts of a post that are analysed per language.
	 *
	 * Title, excerpt and content. Not taxonomy terms, which are already
	 * aggregated into one boostable field, and not the catch-all, which exists
	 * to be language-neutral.
	 *
	 * @var string[]
	 */
	public const PARTS = array( 'title', 'excerpt', 'content' );

	/**
	 * Of the subtags asked about, those this schema can analyse.
	 *
	 * @since 0.9.0
	 *
	 * @param SchemaState $state   Live schema.
	 * @param string[]    $subtags Primary subtags, for example "en", "de".
	 * @return string[] The supportable ones, in the order given.
	 */
	public static function available( SchemaState $state, array $subtags ): array {
		$out = array();

		foreach ( $subtags as $subtag ) {
			$primary = LanguageTag::primary( (string) $subtag );

			if ( '' === $primary || isset( $out[ $primary ] ) ) {
				continue;
			}

			if ( $state->has_field_type( LanguageTag::field_type( $primary ) ) ) {
				$out[ $primary ] = $primary;
			}
		}

		return array_values( $out );
	}

	/**
	 * Of the subtags asked about, those whose fields this schema already has.
	 *
	 * What the document builder is allowed to write. Writing an analysed field
	 * into a schema with no pattern for it is not a degraded index: Solr rejects
	 * the whole document, so one unconfigured language would stop a site
	 * indexing entirely.
	 *
	 * @since 0.9.0
	 *
	 * @param SchemaState $state   Live schema.
	 * @param string[]    $subtags Primary subtags.
	 * @return string[]
	 */
	public static function present( SchemaState $state, array $subtags ): array {
		$out = array();

		foreach ( $subtags as $subtag ) {
			$primary = LanguageTag::primary( (string) $subtag );

			if ( '' === $primary || isset( $out[ $primary ] ) ) {
				continue;
			}

			if ( null !== $state->dynamic_field( LanguageTag::field_pattern( $primary ) ) ) {
				$out[ $primary ] = $primary;
			}
		}

		return array_values( $out );
	}

	/**
	 * The dynamic field patterns for a set of subtags.
	 *
	 * One pattern per language, not one field per language per part, because a
	 * pattern covers the title, the excerpt, the content and anything an
	 * integration adds later without a further schema change. That is the same
	 * reasoning that makes the rest of the contract mostly patterns.
	 *
	 * Deliberately unstored. These fields exist to be searched; the text they
	 * hold is already stored in the neutral field beside them, and storing it
	 * twice would double the stored size of every post for nothing.
	 *
	 * @since 0.9.0
	 *
	 * @param string[] $subtags Primary subtags.
	 * @return SchemaField[]
	 */
	public static function fields( array $subtags ): array {
		$fields = array();
		$seen   = array();

		foreach ( $subtags as $subtag ) {
			$primary = LanguageTag::primary( (string) $subtag );

			if ( '' === $primary || isset( $seen[ $primary ] ) ) {
				continue;
			}

			$seen[ $primary ] = true;

			$fields[] = new SchemaField(
				LanguageTag::field_pattern( $primary ),
				LanguageTag::field_type( $primary ),
				array(
					'stored'    => false,
					'docValues' => false,
				),
				sprintf(
					/* translators: %s: language subtag, for example "de". */
					__( 'Text analysed as %s, so searches in that language match stems and compounds.', 'solrengine' ),
					$primary
				)
			);
		}

		return $fields;
	}
}
