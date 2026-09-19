<?php
/**
 * Turns a SearchQuery into Solr request parameters.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;

/**
 * Builds Solr parameters from a structured query.
 *
 * The only place in the plugin that produces Solr query syntax, which is what
 * makes the injection guarantees checkable: there is one file to audit.
 *
 * Two defences, and they are different things:
 *
 * 1. **Parameter substitution.** The visitor's text is never concatenated into
 *    a query. It is sent as its own request parameter and referenced by name,
 *    so `{!` in user input is a literal brace rather than the start of a local
 *    parameter block. The 0.1.0 prototype passed the raw text as `q`, which let
 *    a visitor rewrite the query.
 * 2. **Server-built everything else.** `fq`, `sort`, `fl`, `rows` and `start`
 *    are assembled here from typed values and a filter registry. No caller can
 *    supply them, and no hook exposes them.
 *
 * A third rule sits above both: filter *values* are escaped even though they
 * come from a registry, because a taxonomy term containing a colon or a quote
 * would otherwise change the meaning of a filter query.
 *
 * @since 0.4.0
 */
final class QueryTranslator {

	/**
	 * Name of the request parameter holding the visitor's text.
	 *
	 * Referenced from the query template as `$se_terms`. Solr dereferences it
	 * as a value, not as syntax, which is the whole mechanism.
	 *
	 * @var string
	 */
	private const TERMS_PARAM = 'se_terms';

	/**
	 * Longest search text accepted.
	 *
	 * A very long query is either a mistake or an attempt to make Solr work
	 * hard. Truncating costs a pathological visitor nothing real.
	 *
	 * @var int
	 */
	private const MAX_TERM_LENGTH = 512;

	/**
	 * Relevance settings.
	 *
	 * @var RelevanceProfile
	 */
	private $relevance;

	/**
	 * Which filters may be applied, and to which fields.
	 *
	 * @var FilterRegistry
	 */
	private $filters;

	/**
	 * Fields returned to callers.
	 *
	 * @var string[]
	 */
	private $return_fields;

	/**
	 * Constructor.
	 *
	 * @param RelevanceProfile $relevance     Relevance settings.
	 * @param FilterRegistry   $filters       Registered filters.
	 * @param string[]         $return_fields Fields to return.
	 */
	public function __construct( RelevanceProfile $relevance, FilterRegistry $filters, array $return_fields = array() ) {
		$this->relevance     = $relevance;
		$this->filters       = $filters;
		$this->return_fields = array() !== $return_fields ? $return_fields : self::default_return_fields();
	}

	/**
	 * Build request parameters.
	 *
	 * @since 0.4.0
	 *
	 * @param SearchQuery $query    Query to translate.
	 * @param string      $strategy One of "standard", "fuzzy" or "loosened".
	 * @return array<string,mixed>
	 */
	public function translate( SearchQuery $query, string $strategy = 'standard' ): array {
		$params = array(
			// No defType. The parser is selected by the local parameter on q
			// below, and setting defType as well makes edismax parse that
			// local parameter as literal text, so the query searches for the
			// string "{!edismax v=$se_terms}" and finds nothing. Verified
			// against Solr 10; it fails silently with zero results rather than
			// erroring, which is why only a live test caught it.
			'rows'       => $query->per_page(),
			'start'      => $query->offset(),
			'fl'         => implode( ',', $this->return_fields ) . ',score',
			'qf'         => $this->relevance->query_fields(),
			'pf'         => $this->relevance->phrase_fields(),
			'tie'        => $this->relevance->tie(),
			// Nothing is gained by echoing our own parameters back, and an
			// echoed query is one more place an internal field name could leak
			// into a response a visitor can read.
			'echoParams' => 'none',
		);

		if ( $query->has_terms() ) {
			// The template references the parameter; the parameter carries the
			// text. Solr substitutes it as a value, so user input cannot become
			// syntax at the top level.
			$params['q']                 = '{!edismax v=$' . self::TERMS_PARAM . '}';
			$params[ self::TERMS_PARAM ] = $this->prepare_terms( $query->terms(), $strategy );
			$params['mm']                = $this->relevance->minimum_match( $strategy );
		} else {
			// A browse rather than a search. Matching everything is correct
			// here and is not user-influenced.
			$params['q']    = '*:*';
			$params['sort'] = $this->sort_clause( 'date_desc' );
		}

		$boost = $this->relevance->boost_function();

		if ( '' !== $boost ) {
			$params['boost'] = $boost;
		}

		$sort = $this->sort_clause( $query->sort() );

		if ( '' !== $sort ) {
			$params['sort'] = $sort;
		}

		$params['fq'] = $this->filter_queries( $query );

		if ( $query->wants_highlight() ) {
			$params['hl']             = 'true';
			$params['hl.fl']          = implode( ',', $this->relevance->highlight_fields() );
			$params['hl.snippets']    = 2;
			$params['hl.fragsize']    = 150;
			$params['hl.simple.pre']  = '<mark>';
			$params['hl.simple.post'] = '</mark>';
		}

		return $params;
	}

	/**
	 * Prepare the visitor's text.
	 *
	 * Even with parameter substitution doing the real work, the text is
	 * normalised: length-capped, whitespace-collapsed, and stripped of a
	 * leading local-parameter marker. That last one is belt and braces — the
	 * substitution already prevents it being parsed — but it costs nothing and
	 * removes the question entirely.
	 *
	 * @param string $terms    Raw text.
	 * @param string $strategy Retrieval strategy.
	 * @return string
	 */
	private function prepare_terms( string $terms, string $strategy ): string {
		$clean = (string) preg_replace( '/\s+/u', ' ', trim( $terms ) );

		if ( strlen( $clean ) > self::MAX_TERM_LENGTH ) {
			$clean = (string) mb_strcut( $clean, 0, self::MAX_TERM_LENGTH, 'UTF-8' );
		}

		// Strip any leading "{!...}" so the text cannot even look like a local
		// parameter block to a reader of the logs.
		$clean = (string) preg_replace( '/^\s*\{![^}]*\}/', '', $clean );

		if ( 'fuzzy' !== $strategy ) {
			return trim( $clean );
		}

		return $this->fuzzify( trim( $clean ) );
	}

	/**
	 * Add a fuzziness marker to each word.
	 *
	 * Applied only on the retry after a search found nothing. Always-on
	 * fuzziness degrades precision for every visitor in order to rescue the
	 * small fraction of queries that need it, and it is measurably slower.
	 *
	 * Short words are left alone: a single edit on a three-letter word matches
	 * most three-letter words.
	 *
	 * @param string $terms Cleaned text.
	 * @return string
	 */
	private function fuzzify( string $terms ): string {
		$words = preg_split( '/\s+/u', $terms );

		if ( ! is_array( $words ) ) {
			return $terms;
		}

		$out = array();

		foreach ( $words as $word ) {
			// Anything with query-ish punctuation is passed through untouched
			// rather than decorated, so this never assembles syntax.
			if ( strlen( $word ) < 4 || 1 !== preg_match( '/^[\p{L}\p{N}]+$/u', $word ) ) {
				$out[] = $word;

				continue;
			}

			$out[] = $word . '~1';
		}

		return implode( ' ', $out );
	}

	/**
	 * Build the filter queries.
	 *
	 * Every clause here is server-built. The site scope in particular is
	 * injected unconditionally and cannot be removed by a caller or a hook: on
	 * a shared collection it is the only thing separating one site's documents
	 * from another's, which makes it a security control rather than a scoping
	 * convenience.
	 *
	 * @param SearchQuery $query Query being translated.
	 * @return string[]
	 */
	private function filter_queries( SearchQuery $query ): array {
		$clauses = array(
			DocumentFields::SITE . ':' . $query->site_id(),
			// Only ever public documents. The builder already refuses to index
			// anything else, so this is a second independent guarantee rather
			// than the only one.
			DocumentFields::VISIBILITY . ':public',
		);

		$post_types = $query->post_types();

		if ( array() !== $post_types ) {
			$escaped = array();

			foreach ( $post_types as $type ) {
				$escaped[] = self::escape( $type );
			}

			$clauses[] = DocumentFields::POST_TYPE . ':(' . implode( ' OR ', $escaped ) . ')';
		}

		if ( '' !== $query->language() ) {
			$clauses[] = DocumentFields::LANG . ':' . self::escape( $query->language() );
		}

		foreach ( $query->filters() as $key => $values ) {
			$clause = $this->filters->clause_for( (string) $key, (array) $values );

			if ( '' !== $clause ) {
				$clauses[] = $clause;
			}
		}

		return $clauses;
	}

	/**
	 * Translate a sort key into a Solr sort clause.
	 *
	 * An enum, not a pass-through. A caller-supplied sort string could name any
	 * field, which leaks the schema and permits sorting on fields without
	 * docValues, which is slow.
	 *
	 * @param string $sort Sort key.
	 * @return string
	 */
	private function sort_clause( string $sort ): string {
		$map = array(
			'relevance'  => '',
			'date_desc'  => DocumentFields::DATE . ' desc',
			'date_asc'   => DocumentFields::DATE . ' asc',
			'modified'   => DocumentFields::MODIFIED . ' desc',
			'title_asc'  => DocumentFields::TITLE_SORT . ' asc',
			'title_desc' => DocumentFields::TITLE_SORT . ' desc',
			'menu_order' => DocumentFields::MENU_ORDER . ' asc',
		);

		return $map[ $sort ] ?? '';
	}

	/**
	 * Sort keys a caller may ask for.
	 *
	 * @since 0.4.0
	 *
	 * @return string[]
	 */
	public static function sort_keys(): array {
		return array( 'relevance', 'date_desc', 'date_asc', 'modified', 'title_asc', 'title_desc', 'menu_order' );
	}

	/**
	 * Escape a value so Solr treats it as a literal.
	 *
	 * Applied to every value that reaches a filter query, including ones from
	 * the registry. A taxonomy term containing a colon, a quote or a space
	 * would otherwise change what a filter matches.
	 *
	 * @since 0.4.0
	 *
	 * @param string $value Value to escape.
	 * @return string
	 */
	public static function escape( string $value ): string {
		$specials = array(
			'\\',
			'+',
			'-',
			'&&',
			'||',
			'!',
			'(',
			')',
			'{',
			'}',
			'[',
			']',
			'^',
			'"',
			'~',
			'*',
			'?',
			':',
			'/',
		);

		$escaped = $value;

		foreach ( $specials as $special ) {
			$escaped = str_replace( $special, '\\' . $special, $escaped );
		}

		// A space would end the term and start a new clause.
		return str_replace( ' ', '\\ ', $escaped );
	}

	/**
	 * Fields returned by default.
	 *
	 * An allowlist. A field can be searchable and filterable without being
	 * readable, and returning everything would expose indexed meta that an
	 * administrator never meant to publish.
	 *
	 * @return string[]
	 */
	private static function default_return_fields(): array {
		return array(
			'id',
			DocumentFields::OBJECT_ID,
			DocumentFields::OBJECT_TYPE,
			DocumentFields::POST_TYPE,
			DocumentFields::TITLE,
			DocumentFields::EXCERPT,
			DocumentFields::URL,
			DocumentFields::DATE,
			DocumentFields::AUTHOR_NAME,
			DocumentFields::THUMBNAIL,
		);
	}
}
