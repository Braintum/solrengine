<?php
/**
 * Turns a SuggestQuery into Solr request parameters.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\DocumentFields;

/**
 * Builds a prefix query for autocomplete.
 *
 * **Why not Solr's Suggester component.** The Suggester needs a
 * `searchComponent` and a request handler in `solrconfig.xml`, and SolrEngine
 * cannot apply those: the Schema API reaches fields, not configuration, and
 * changing a configset means uploading and reloading it. Measured against a
 * stock Solr 10, the default configset has no `/suggest` handler at all, so a
 * Suggester-based autocomplete would work only for customers who created their
 * collection from SolrEngine's own configset — a minority, and silently broken
 * for everyone else.
 *
 * A prefix query needs no configuration whatsoever and works on any collection
 * that has the schema contract applied. It also returns documents rather than
 * word completions, which is what a visitor typing "macb" actually wants: the
 * MacBook review, not the word "macbook" and a second keypress.
 *
 * The same injection defences as the search translator apply, because the same
 * mechanism carries the text: parameter substitution, server-built filters,
 * and no route for engine syntax.
 *
 * @since 0.5.0
 */
final class SuggestTranslator {

	/**
	 * Which filters may be applied, and to which fields.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Constructor.
	 *
	 * @param FilterRegistry|null $filters Registered filters.
	 */
	public function __construct( ?FilterRegistry $filters = null ) {
		$this->filters = $filters ?? new FilterRegistry();
	}

	/**
	 * Name of the request parameter holding the visitor's text.
	 *
	 * @var string
	 */
	private const TERMS_PARAM = 'se_terms';

	/**
	 * Longest prefix accepted.
	 *
	 * Autocomplete prefixes are short by nature. Anything longer is not
	 * somebody typing.
	 *
	 * @var int
	 */
	private const MAX_PREFIX_LENGTH = 128;

	/**
	 * Fields a suggestion may be matched against, with weights.
	 *
	 * Titles only. Matching content would return documents whose title has
	 * nothing to do with what was typed, which reads as noise in a list of
	 * eight short lines.
	 *
	 * @var string
	 */
	private const FIELDS = 'se_txt_title^10 se_taxterms^2';

	/**
	 * Build request parameters.
	 *
	 * @since 0.5.0
	 *
	 * @param SuggestQuery $query Query to translate.
	 * @return array<string,mixed>
	 */
	public function translate( SuggestQuery $query ): array {
		$params = array(
			'q'               => '{!edismax v=$' . self::TERMS_PARAM . '}',
			self::TERMS_PARAM => $this->to_prefix_query( $query->prefix() ),
			'qf'              => self::FIELDS,
			'rows'            => $query->limit(),
			'start'           => 0,
			'fl'              => implode(
				',',
				array(
					DocumentFields::OBJECT_ID,
					DocumentFields::OBJECT_TYPE,
					DocumentFields::POST_TYPE,
					DocumentFields::TITLE,
					DocumentFields::URL,
				)
			),
			// Every word must match. Autocomplete narrows as you type, so a
			// result matching only the first of three words is wrong.
			'mm'              => '100%',
			'echoParams'      => 'none',
			// Bounded so a pathological prefix cannot make Solr work hard on
			// the one request type that fires on every keystroke.
			'timeAllowed'     => 250,
		);

		$params['fq'] = $this->filter_queries( $query );

		return $params;
	}

	/**
	 * Turn typed text into a prefix query.
	 *
	 * Only the last word gets a wildcard, because it is the only one still
	 * being typed. Earlier words are complete and should match as written.
	 *
	 * @param string $prefix Raw typed text.
	 * @return string
	 */
	private function to_prefix_query( string $prefix ): string {
		$clean = (string) preg_replace( '/\s+/u', ' ', trim( $prefix ) );

		if ( mb_strlen( $clean ) > self::MAX_PREFIX_LENGTH ) {
			$clean = mb_substr( $clean, 0, self::MAX_PREFIX_LENGTH );
		}

		// Belt and braces alongside the parameter substitution, which already
		// prevents this being parsed as a local parameter.
		$clean = (string) preg_replace( '/^\s*\{![^}]*\}/', '', trim( $clean ) );

		$words = preg_split( '/\s+/u', $clean );

		if ( ! is_array( $words ) || array() === $words ) {
			return '';
		}

		// Leading wildcards are stripped from every word. Solr accepts one and
		// answers it by walking the whole term dictionary, which is expensive
		// on the one endpoint that fires on every keystroke. A visitor typing
		// "*mac" is not asking for that; they are typing an asterisk.
		$words = array_map(
			static function ( string $word ): string {
				return ltrim( $word, '*?' );
			},
			$words
		);

		$words = array_values(
			array_filter(
				$words,
				static function ( string $word ): bool {
					return '' !== $word;
				}
			)
		);

		if ( array() === $words ) {
			return '';
		}

		$last = (string) array_pop( $words );

		// A wildcard is added only to a plain word. Anything else is passed
		// through, so this never assembles syntax out of punctuation the
		// visitor typed.
		if ( 1 === preg_match( '/^[\p{L}\p{N}]{2,}$/u', $last ) ) {
			$last .= '*';
		}

		$words[] = $last;

		return implode( ' ', $words );
	}

	/**
	 * Build the filter queries.
	 *
	 * Identical guarantees to a full search: site scope and public visibility
	 * are injected here and cannot be removed by a caller or a hook. An
	 * autocomplete that leaked a private title would leak it just as
	 * completely as a search result page.
	 *
	 * @param SuggestQuery $query Query being translated.
	 * @return string[]
	 */
	private function filter_queries( SuggestQuery $query ): array {
		$clauses = array(
			DocumentFields::SITE . ':' . $query->site_id(),
			DocumentFields::VISIBILITY . ':public',
		);

		foreach ( $query->filters() as $key => $values ) {
			$clause = $this->filters->clause_for( (string) $key, (array) $values );

			if ( '' !== $clause ) {
				$clauses[] = $clause;
			}
		}

		$post_types = $query->post_types();

		if ( array() !== $post_types ) {
			$escaped = array();

			foreach ( $post_types as $type ) {
				$escaped[] = QueryTranslator::escape( $type );
			}

			$clauses[] = DocumentFields::POST_TYPE . ':(' . implode( ' OR ', $escaped ) . ')';
		}

		return $clauses;
	}
}
