<?php
/**
 * Query construction tests.
 *
 * The translator is the only place in the plugin that produces Solr query
 * syntax, so these are the unit-level security tests. The companion backend
 * suite proves Solr agrees with what is asserted here.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;

/**
 * @covers \SolrEngine\Search\QueryTranslator
 * @covers \SolrEngine\Search\RelevanceProfile
 */
final class QueryTranslatorTest extends TestCase {

	private function translator(): QueryTranslator {
		return new QueryTranslator( new RelevanceProfile(), FilterRegistry::defaults() );
	}

	// -- Injection resistance ----------------------------------------------

	/**
	 * @dataProvider hostile_input
	 */
	public function test_user_text_never_becomes_query_syntax( string $attack ): void {
		$params = $this->translator()->translate( new SearchQuery( $attack, 1 ) );

		// The q template is fixed and references a parameter. Whatever the
		// visitor typed goes in that parameter as a value, so it cannot be
		// parsed as syntax at the top level.
		$this->assertSame( '{!edismax v=$se_terms}', $params['q'] );
		$this->assertArrayHasKey( 'se_terms', $params );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function hostile_input(): array {
		return array(
			array( '*:* OR se_s_visibility:private' ),
			array( '{!lucene}se_s_visibility:private' ),
			array( '{!xmlparser v="x"}' ),
			array( '{!join from=id to=id fromIndex=other}*:*' ),
			array( 'laptops") OR (se_i_site:2' ),
		);
	}

	public function test_a_leading_local_parameter_block_is_stripped(): void {
		$params = $this->translator()->translate( new SearchQuery( '{!lucene}laptops', 1 ) );

		$this->assertStringNotContainsString( '{!', $params['se_terms'] );
		$this->assertSame( 'laptops', $params['se_terms'] );
	}

	public function test_no_deftype_is_sent(): void {
		// defType plus a local parameter on q makes edismax parse the local
		// parameter as literal text, so the query matches nothing. It fails
		// silently, which is why this is pinned by a test.
		$this->assertArrayNotHasKey( 'defType', $this->translator()->translate( new SearchQuery( 'x', 1 ) ) );
	}

	public function test_very_long_input_is_truncated(): void {
		$params = $this->translator()->translate( new SearchQuery( str_repeat( 'a', 5000 ), 1 ) );

		$this->assertLessThanOrEqual( 512, strlen( $params['se_terms'] ) );
	}

	// -- Mandatory filters --------------------------------------------------

	public function test_the_site_scope_is_always_applied(): void {
		$params = $this->translator()->translate( new SearchQuery( 'x', 42 ) );

		$this->assertContains( DocumentFields::SITE . ':42', $params['fq'] );
	}

	public function test_only_public_documents_are_ever_requested(): void {
		$params = $this->translator()->translate( new SearchQuery( 'x', 1 ) );

		$this->assertContains( DocumentFields::VISIBILITY . ':public', $params['fq'] );
	}

	public function test_filter_queries_are_a_list_so_they_can_be_repeated(): void {
		// Solr expresses repeated parameters by repeating the key. Returning a
		// list is what lets the transport encode two "fq=" pairs; a single
		// joined string would change the meaning.
		$params = $this->translator()->translate( new SearchQuery( 'x', 1 ) );

		$this->assertIsArray( $params['fq'] );
		$this->assertGreaterThanOrEqual( 2, count( $params['fq'] ) );
	}

	// -- Sorting -------------------------------------------------------------

	public function test_an_unknown_sort_key_falls_back_to_relevance(): void {
		// A caller-supplied field name would leak the schema and could sort on
		// a field without docValues.
		$params = $this->translator()->translate( ( new SearchQuery( 'x', 1 ) )->with_sort( 'se_txt_content asc' ) );

		$this->assertArrayNotHasKey( 'sort', $params );
	}

	public function test_a_known_sort_key_maps_to_a_field(): void {
		$params = $this->translator()->translate( ( new SearchQuery( 'x', 1 ) )->with_sort( 'date_desc' ) );

		$this->assertSame( DocumentFields::DATE . ' desc', $params['sort'] );
	}

	// -- Strategies ----------------------------------------------------------

	public function test_the_standard_strategy_is_not_fuzzy(): void {
		$params = $this->translator()->translate( new SearchQuery( 'laptops', 1 ), 'standard' );

		$this->assertStringNotContainsString( '~', $params['se_terms'] );
	}

	public function test_the_fuzzy_strategy_marks_long_words_only(): void {
		$params = $this->translator()->translate( new SearchQuery( 'the laptops', 1 ), 'fuzzy' );

		// A single edit on a three-letter word matches most three-letter words,
		// so short words are left alone.
		$this->assertStringContainsString( 'laptops~1', $params['se_terms'] );
		$this->assertStringNotContainsString( 'the~', $params['se_terms'] );
	}

	public function test_the_loosened_strategy_requires_only_one_term(): void {
		$params = $this->translator()->translate( new SearchQuery( 'a b c', 1 ), 'loosened' );

		$this->assertSame( '1', $params['mm'] );
	}

	// -- Returned fields -----------------------------------------------------

	public function test_returned_fields_are_an_allowlist(): void {
		// A field can be searchable and filterable without being readable.
		// Returning everything would publish indexed meta.
		$params = $this->translator()->translate( new SearchQuery( 'x', 1 ) );

		$this->assertStringNotContainsString( '*', $params['fl'] );
		$this->assertStringContainsString( DocumentFields::TITLE, $params['fl'] );
		$this->assertStringNotContainsString( DocumentFields::VISIBILITY, $params['fl'] );
	}

	public function test_pagination_maps_to_rows_and_start(): void {
		$params = $this->translator()->translate( ( new SearchQuery( 'x', 1 ) )->with_pagination( 3, 20 ) );

		$this->assertSame( 20, $params['rows'] );
		$this->assertSame( 40, $params['start'] );
	}

	// -- Escaping ------------------------------------------------------------

	/**
	 * @dataProvider values_needing_escape
	 */
	public function test_special_characters_are_escaped( string $raw, string $needle ): void {
		$this->assertStringContainsString( $needle, QueryTranslator::escape( $raw ) );
	}

	/**
	 * @return array<int,array{0:string,1:string}>
	 */
	public function values_needing_escape(): array {
		return array(
			array( 'a:b', '\\:' ),
			array( 'a b', '\\ ' ),
			array( 'a"b', '\\"' ),
			array( 'a(b', '\\(' ),
			array( 'a*b', '\\*' ),
		);
	}

	public function test_relevance_uses_an_explicit_taxonomy_field(): void {
		// qf takes explicit field names. The contract aggregates every
		// taxonomy's terms into one field precisely so it can be weighted
		// without a wildcard.
		$qf = ( new RelevanceProfile() )->query_fields();

		$this->assertStringNotContainsString( '*', $qf );
		$this->assertStringContainsString( 'se_taxterms', $qf );
	}

	public function test_recency_boost_rounds_now_to_the_hour(): void {
		// An unrounded NOW makes every query unique, so Solr's caches never
		// hit and the boost costs far more than it is worth.
		$profile = new RelevanceProfile( array(), array(), '2<75%', 0.1, true );

		$this->assertStringContainsString( 'NOW/HOUR', $profile->boost_function() );
	}

	// -- Language scoping --------------------------------------------------

	public function test_a_language_scope_still_matches_documents_with_no_language(): void {
		$params = $this->translator()->translate(
			( new SearchQuery( 'laptop', 1 ) )->with_language( 'de-DE' )
		);

		$clause = $this->clause_mentioning( $params['fq'], DocumentFields::LANG );

		// Read as "not (has a language that is not this one)". The obvious
		// positive filter would delete every document indexed before 0.9.0 from
		// the results the moment a multilingual plugin was activated, and keep
		// doing so until somebody reindexed. Phase 6 learned this about
		// catalogue visibility; the shape of the fix is the same.
		$this->assertStringStartsWith( '*:* -(', $clause );
		$this->assertStringContainsString( DocumentFields::LANG . ':[* TO *]', $clause );
		$this->assertStringContainsString( DocumentFields::LANG . ':de\\-DE', $clause );
	}

	public function test_no_language_scope_adds_no_clause(): void {
		$params = $this->translator()->translate( new SearchQuery( 'laptop', 1 ) );

		$this->assertSame( '', $this->clause_mentioning( $params['fq'], DocumentFields::LANG ) );
	}

	public function test_analysed_fields_are_searched_only_when_the_index_has_them(): void {
		$without = new QueryTranslator( new RelevanceProfile(), FilterRegistry::defaults() );
		$with    = new QueryTranslator( new RelevanceProfile(), FilterRegistry::defaults(), array(), null, array( 'de' ) );

		$query = ( new SearchQuery( 'haus', 1 ) )->with_language( 'de-DE' );

		// A field name in qf that no schema defines is not a degraded search:
		// Solr rejects the request outright, so every search on the site fails.
		$this->assertStringNotContainsString( 'se_txtde_', $without->translate( $query )['qf'] );
		$this->assertStringContainsString( 'se_txtde_title', $with->translate( $query )['qf'] );
	}

	public function test_analysed_fields_follow_the_language_of_the_query(): void {
		$translator = new QueryTranslator(
			new RelevanceProfile(),
			FilterRegistry::defaults(),
			array(),
			null,
			array( 'de', 'fr' )
		);

		$params = $translator->translate( ( new SearchQuery( 'maison', 1 ) )->with_language( 'fr-FR' ) );

		$this->assertStringContainsString( 'se_txtfr_title', $params['qf'] );
		$this->assertStringNotContainsString( 'se_txtde_', $params['qf'] );
	}

	public function test_analysed_fields_mirror_the_neutral_weights(): void {
		$profile = RelevanceProfile::from_config(
			array(
				'field_boosts' => array(
					'se_txt_title'   => 5.0,
					'se_txt_content' => 0,
				),
			)
		);

		$translator = new QueryTranslator( $profile, FilterRegistry::defaults(), array(), null, array( 'de' ) );
		$params     = $translator->translate( ( new SearchQuery( 'haus', 1 ) )->with_language( 'de' ) );

		$this->assertStringContainsString( 'se_txtde_title^5', $params['qf'] );

		// A field switched off stays off in every language. Re-adding it here
		// would make "do not search the content" mean "do not search the
		// content, except in German".
		$this->assertStringNotContainsString( 'se_txtde_content', $params['qf'] );
	}

	/**
	 * The first filter query mentioning a field, or an empty string.
	 *
	 * @param array<int,string> $clauses Filter queries.
	 * @param string            $field   Field name.
	 * @return string
	 */
	private function clause_mentioning( array $clauses, string $field ): string {
		foreach ( $clauses as $clause ) {
			if ( false !== strpos( $clause, $field ) ) {
				return $clause;
			}
		}

		return '';
	}
}
