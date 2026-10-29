<?php
/**
 * Turns a SearchQuery into Solr request parameters.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Integration\WooCommerce\ProductFields;
use SolrEngine\Language\LanguageTag;

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
 * 2. **Server-built everything else.** `fq`, `sort`, `fl`, `rows`, `start` and
 *    `json.facet` are assembled here from typed values and two registries. No
 *    caller can supply them, and no hook exposes them. A facet request in
 *    particular is named by key and built here; facet JSON from a client is
 *    never accepted, which is what keeps this endpoint from being a Solr proxy
 *    (ADR-008).
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
	 * Name of the request parameter holding the text as typed, when the last
	 * word is completed.
	 *
	 * @var string
	 */
	private const EXACT_PARAM = 'se_exact';

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
	 * Which facets may be counted.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Builds the JSON Facet request.
	 *
	 * @var FacetTranslator
	 */
	private $facet_translator;

	/**
	 * Primary subtags whose analysed fields exist in this index.
	 *
	 * @var string[]
	 */
	private $analysed;

	/**
	 * Constructor.
	 *
	 * @param RelevanceProfile   $relevance     Relevance settings.
	 * @param FilterRegistry     $filters       Registered filters.
	 * @param string[]           $return_fields Fields to return.
	 * @param FacetRegistry|null $facets        Registered facets.
	 * @param string[]           $analysed      Subtags with analysed fields in the index.
	 */
	public function __construct(
		RelevanceProfile $relevance,
		FilterRegistry $filters,
		array $return_fields = array(),
		?FacetRegistry $facets = null,
		array $analysed = array()
	) {
		$this->relevance        = $relevance;
		$this->filters          = $filters;
		$this->return_fields    = array() !== $return_fields ? $return_fields : self::default_return_fields();
		$this->facets           = $facets ?? new FacetRegistry();
		$this->facet_translator = new FacetTranslator( $this->facets );
		$this->analysed         = array_map( 'strval', $analysed );
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
			//
			// A facets-only request asks for no documents at all. Fetching ten
			// in order to discard them costs Solr the stored-field retrieval on
			// the request a visitor repeats most, once per tick of a filter box.
			'rows'       => $query->wants_documents() ? $query->per_page() : 0,
			'start'      => $query->wants_documents() ? $query->offset() : 0,
			'fl'         => implode( ',', $this->return_fields ) . ',score',
			'qf'         => $this->query_fields( $query ),
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
			$terms = $this->prepare_terms( $query->terms(), $strategy );

			$params['q']                 = '{!edismax v=$' . self::TERMS_PARAM . '}';
			$params[ self::TERMS_PARAM ] = $terms;
			$params['mm']                = $this->relevance->minimum_match( $strategy );

			$completed = $query->completes_last_word() ? PrefixTerms::complete_last( $terms ) : $terms;

			if ( $completed !== $terms ) {
				// A wildcard match scores every document alike, so on its own
				// "hoodie*" ranks "Hoodie" level with "Hoodie with Logo". The
				// text as typed rides along as a boost query, which only
				// reorders what the main query matched and never adds to it.
				// Its own bq and boost are blanked: the parser reads the
				// request's, and would otherwise apply this boost to itself.
				// Measured on the development index: 8–26 ms per query.
				$params[ self::TERMS_PARAM ] = $completed;
				$params[ self::EXACT_PARAM ] = $terms;
				$params['bq']                = "{!edismax bq='' boost='' v=$" . self::EXACT_PARAM . '}';
			}
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

		$facets = $this->facet_translator->translate( $query );

		if ( array() !== $facets ) {
			// Sent as a parameter rather than as a JSON request body, because
			// the rest of the query is already parameters and one request
			// shape is easier to audit than two. Solr parses json.facet
			// identically either way.
			$params['json.facet'] = (string) wp_json_encode( $facets );
		}

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
	 * The qf expression for a query, including its language's analysed fields.
	 *
	 * The analysed fields are added only when the index actually has them. A
	 * field name in qf that no schema defines is not a degraded search: Solr
	 * rejects the request with "undefined field", so every search on the site
	 * would fail.
	 *
	 * @param SearchQuery $query Query being translated.
	 * @return string
	 */
	private function query_fields( SearchQuery $query ): string {
		$fields  = $this->relevance->query_fields();
		$primary = LanguageTag::primary( $query->language() );

		if ( '' === $primary || ! in_array( $primary, $this->analysed, true ) ) {
			return $fields;
		}

		$extra = $this->relevance->analysed_query_fields( $primary );

		return '' === $extra ? $fields : $fields . ' ' . $extra;
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

		$language = $query->language();

		if ( '' !== $language ) {
			// Read this as "not (has a language that is not this one)". The
			// straightforward positive clause would be wrong in a way that is
			// invisible until it matters: every document indexed before 0.9.0
			// carries no language field at all, so a positive filter deletes the
			// entire existing index from the results the moment a multilingual
			// plugin is activated, and keeps doing so until somebody reindexes.
			//
			// Phase 6 learned exactly this about catalogue visibility, and the
			// shape of the fix is the same one: filter on what a document is
			// excluded by, so a document with nothing to say matches.
			$clauses[] = sprintf(
				'*:* -(%1$s:[* TO *] -%1$s:%2$s)',
				DocumentFields::LANG,
				self::escape( $language )
			);
		}

		foreach ( $query->filters() as $key => $values ) {
			$key    = (string) $key;
			$clause = $this->filters->clause_for( $key, (array) $values, $query->filter_mode( $key ) );

			if ( '' === $clause ) {
				continue;
			}

			// A filter that a facet also counts is tagged, so that facet can
			// exclude it from its own domain and keep the unselected counts
			// visible. Untagged, a filter panel becomes a one-way door: every
			// alternative reads zero the moment one value is ticked.
			$clauses[] = $this->facets->has( $key )
				? '{!tag=' . FacetRegistry::tag_for( $key ) . '}' . $clause
				: $clause;
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

			// Shop orderings. The fields behind them are written by the
			// WooCommerce integration, and sorting by one on a site that sells
			// nothing is harmless: the dynamic field pattern exists whether or
			// not any document uses it, so Solr sorts rather than erroring.
			'price_asc'  => ProductFields::PRICE_MIN . ' asc',
			'price_desc' => ProductFields::PRICE_MAX . ' desc',
			'popularity' => ProductFields::SALES . ' desc',
			'rating'     => ProductFields::RATING . ' desc',
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
		return array(
			'relevance',
			'date_desc',
			'date_asc',
			'modified',
			'title_asc',
			'title_desc',
			'menu_order',
			'price_asc',
			'price_desc',
			'popularity',
			'rating',
		);
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
	 * The product prices are on it because the search popup shows them, read
	 * from the index rather than from WooCommerce. They are what the product
	 * page already publishes, and a document that is not a product simply has
	 * none of them, so asking costs nothing on a site that sells nothing.
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
			ProductFields::PRICE,
			ProductFields::PRICE_MIN,
			ProductFields::PRICE_MAX,
			ProductFields::REGULAR_PRICE,
			ProductFields::PRICE_TAXED,
		);
	}
}
