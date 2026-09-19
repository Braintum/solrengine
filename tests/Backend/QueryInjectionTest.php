<?php
/**
 * Query injection resistance, against a real Solr.
 *
 * Unit tests can check that the generated parameters look right. Only a real
 * server can confirm that Solr agrees about what they mean, and this phase
 * produced two findings that prove the difference: filter queries encoded as
 * "fq[0]=" were silently ignored, and a stray defType made the whole query
 * match nothing. Both failed quietly, with a plausible-looking response.
 *
 * Every case below is something an attacker with nothing but a search box
 * would try.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\QueryTranslator;
use SolrEngine\Search\RelevanceProfile;
use SolrEngine\Search\ResponseParser;

/**
 * @covers \SolrEngine\Search\QueryTranslator
 * @covers \SolrEngine\Search\ResponseParser
 * @covers \SolrEngine\Search\FilterRegistry
 */
final class QueryInjectionTest extends TestCase {

	/**
	 * Base Solr URL.
	 *
	 * @var string
	 */
	private static $base = '';

	/**
	 * Collection under test.
	 *
	 * @var string
	 */
	private static $collection = '';

	/**
	 * Site this suite searches as.
	 *
	 * Each backend suite owns a site number, so suites sharing a core cannot
	 * change each other's result counts. That the isolation works at all is
	 * the product behaviour under test, so relying on it here is fitting.
	 *
	 * @var int
	 */
	private const SITE = 501;

	/**
	 * A different site, used to prove the boundary holds.
	 *
	 * @var int
	 */
	private const OTHER_SITE = 502;

	/**
	 * Title of the document that must never be returned.
	 *
	 * @var string
	 */
	private const SECRET_TITLE = 'Confidential internal memo';

	/**
	 * Title of the document belonging to another site.
	 *
	 * @var string
	 */
	private const OTHER_SITE_TITLE = 'Article belonging to site two';

	public static function setUpBeforeClass(): void {
		$base = getenv( 'SOLRENGINE_TEST_SOLR_URL' );

		if ( ! is_string( $base ) || '' === $base ) {
			return;
		}

		self::$base = rtrim( $base, '/' );

		$collection = getenv( 'SOLRENGINE_TEST_SOLR_COLLECTION' );

		self::$collection = is_string( $collection ) && '' !== $collection ? $collection : 'wordpress';

		$state = ( new SchemaStateFactory() )->from_response( self::call( 'GET', '/schema', null, array() ), true );

		foreach ( ( new SchemaPlanner() )->plan( ( new ContractRegistry() )->current(), $state )->operations() as $operation ) {
			self::call( 'POST', '/schema', array( $operation->command() => $operation->payload() ), array() );
		}

		self::call( 'POST', '/update', self::fixtures(), array( 'commit' => 'true' ) );
	}

	protected function setUp(): void {
		if ( '' === self::$base ) {
			$this->markTestSkipped( 'Set SOLRENGINE_TEST_SOLR_URL to run backend tests.' );
		}
	}

	public function test_an_ordinary_search_finds_the_public_document(): void {
		// The control. Without it, every assertion below would pass trivially
		// on a query that simply never matches anything, which is exactly the
		// failure mode a stray defType produced during development.
		$results = $this->search( 'laptops', self::SITE );

		$this->assertSame( 1, $results->total() );
		$this->assertStringContainsString(
			'laptop',
			strtolower( (string) $results->hits()[0]->field( DocumentFields::TITLE, '' ) )
		);
	}

	/**
	 * @dataProvider injection_attempts
	 */
	public function test_injection_attempts_never_return_hidden_documents( string $label, string $attack ): void {
		// assertNotContains rather than a loop, so an empty result set still
		// asserts. An attack returning nothing is the pass case and must not
		// be reported as a test that did nothing.
		$titles = $this->titles( $this->search( $attack, self::SITE ) );

		$this->assertNotContains( self::SECRET_TITLE, $titles, $label . ' exposed a private document' );
		$this->assertNotContains( self::OTHER_SITE_TITLE, $titles, $label . ' crossed the site boundary' );
	}

	/**
	 * Each of these tries to turn the search box into a query editor.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function injection_attempts(): array {
		return array(
			array( 'match-all disjunction', '*:* OR se_s_visibility:private' ),
			array( 'local parameter block', '{!lucene}se_s_visibility:private' ),
			array( 'xmlparser local parameter', '{!xmlparser v="<!DOCTYPE a SYSTEM \'http://example.invalid/x\'>"}' ),
			array( 'direct field probe', 'se_txt_content:salaries' ),
			array( 'private visibility probe', 'se_s_visibility:private' ),
			array( 'boolean escape', 'laptops") OR (se_i_site:502' ),
			array( 'negating the site filter', 'laptops -se_i_site:501' ),
			array( 'cross-core join', '{!join from=id to=id fromIndex=other}*:*' ),
			array( 'function query', '{!func}1' ),
			array( 'terms parser', '{!terms f=se_s_visibility}private' ),
			array( 'bare wildcard', '*' ),
			array( 'regex over a field', 'se_txt_title:/.*memo.*/' ),
		);
	}

	public function test_a_visitor_cannot_reach_another_sites_documents(): void {
		// Site scope is injected by the translator and cannot be removed by a
		// caller or a hook. On a shared collection it is the only thing
		// separating tenants.
		$this->assertNotContains(
			self::OTHER_SITE_TITLE,
			$this->titles( $this->search( 'site two', self::SITE ) )
		);

		// And the document really is there, so the assertion above is not
		// passing because nothing matches.
		$from_site_two = $this->search( 'site two', self::OTHER_SITE );

		$this->assertSame( 1, $from_site_two->total() );
	}

	public function test_private_documents_are_excluded_even_when_named_exactly(): void {
		$this->assertNotContains(
			self::SECRET_TITLE,
			$this->titles( $this->search( self::SECRET_TITLE, self::SITE ) )
		);
	}

	public function test_filter_queries_actually_reach_solr(): void {
		// The regression test for the "fq[0]=" encoding bug. If filters are
		// dropped, this returns documents from every site rather than none,
		// and the security filters would be equally absent.
		$results = $this->search( '', 999999 );

		$this->assertSame( 0, $results->total(), 'Filter queries are not being applied at all.' );
	}

	public function test_an_unregistered_filter_key_contributes_nothing(): void {
		$registry = FilterRegistry::defaults();

		$this->assertSame( '', $registry->clause_for( 'se_s_visibility', array( 'private' ) ) );
		$this->assertSame( '', $registry->clause_for( 'anything_at_all', array( 'x' ) ) );
	}

	public function test_a_registered_filter_escapes_its_values(): void {
		$registry = FilterRegistry::defaults();

		$registry->register( 'sku', 'se_s_meta_sku', 's', 'SKU' );

		$clause = $registry->clause_for( 'sku', array( 'ABC:123 OR se_i_site:502' ) );

		// The colon, the space and the parentheses must all be neutralised, so
		// the value cannot end the term and start a new clause.
		$this->assertStringNotContainsString( ' OR ', $clause );
		$this->assertStringContainsString( '\\:', $clause );
	}

	public function test_the_result_of_a_no_match_query_is_empty_rather_than_everything(): void {
		$results = $this->search( 'zzzznothingmatchesthis', self::SITE );

		$this->assertTrue( $results->is_empty() );
	}

	// -- Plumbing ----------------------------------------------------------

	/**
	 * Titles of every hit.
	 *
	 * @param \SolrEngine\Contracts\SearchResults $results Results.
	 * @return string[]
	 */
	private function titles( $results ): array {
		$titles = array();

		foreach ( $results->hits() as $hit ) {
			$titles[] = (string) $hit->field( DocumentFields::TITLE, '' );
		}

		return $titles;
	}

	/**
	 * Run a search through the real translator and parser.
	 *
	 * @param string $terms   Search text.
	 * @param int    $site_id Site to search within.
	 * @return \SolrEngine\Contracts\SearchResults
	 */
	private function search( string $terms, int $site_id ) {
		$query      = new SearchQuery( $terms, $site_id );
		$translator = new QueryTranslator( new RelevanceProfile(), FilterRegistry::defaults() );

		$response = self::call( 'GET', '/select', null, $translator->translate( $query ) );

		return ( new ResponseParser() )->parse( $response, $query );
	}

	/**
	 * Documents the assertions above depend on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function fixtures(): array {
		return array(
			array(
				'id'                        => 'se:501:post:8001',
				DocumentFields::SITE        => self::SITE,
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::OBJECT_ID   => 8001,
				DocumentFields::POST_TYPE   => 'post',
				DocumentFields::VISIBILITY  => 'public',
				DocumentFields::TITLE       => 'Public review of laptops',
				DocumentFields::CONTENT     => 'Laptops are portable computers.',
			),
			array(
				'id'                        => 'se:501:post:8002',
				DocumentFields::SITE        => self::SITE,
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::OBJECT_ID   => 8002,
				DocumentFields::POST_TYPE   => 'post',
				// Indexed as private only so this test has something to try to
				// reach. The document builder refuses to index private content
				// in the first place, so in production this document would not
				// exist at all.
				DocumentFields::VISIBILITY  => 'private',
				DocumentFields::TITLE       => self::SECRET_TITLE,
				DocumentFields::CONTENT     => 'Confidential salaries and bonuses.',
			),
			array(
				'id'                        => 'se:502:post:8003',
				DocumentFields::SITE        => self::OTHER_SITE,
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::OBJECT_ID   => 8003,
				DocumentFields::POST_TYPE   => 'post',
				DocumentFields::VISIBILITY  => 'public',
				DocumentFields::TITLE       => self::OTHER_SITE_TITLE,
				DocumentFields::CONTENT     => 'This belongs to site two.',
			),
		);
	}

	/**
	 * Perform an HTTP request, encoding repeated parameters the way Solr needs.
	 *
	 * @param string              $method HTTP method.
	 * @param string              $path   Path within the collection.
	 * @param mixed               $body   JSON payload, or null.
	 * @param array<string,mixed> $query  Query parameters.
	 * @return array<string,mixed>
	 */
	private static function call( string $method, string $path, $body, array $query ): array {
		$query['wt'] = 'json';
		$pairs       = array();

		foreach ( $query as $key => $value ) {
			foreach ( (array) $value as $item ) {
				$rendered = is_bool( $item ) ? ( $item ? 'true' : 'false' ) : (string) $item;
				$pairs[]  = rawurlencode( (string) $key ) . '=' . rawurlencode( $rendered );
			}
		}

		$url = self::$base . '/' . self::$collection . $path . '?' . implode( '&', $pairs );

		$options = array(
			'http' => array(
				'method'        => $method,
				'timeout'       => 30,
				'ignore_errors' => true,
				'header'        => "Accept: application/json\r\n",
			),
		);

		if ( null !== $body ) {
			$options['http']['header'] .= "Content-Type: application/json\r\n";
			$options['http']['content'] = (string) wp_json_encode( $body );
		}

		set_error_handler( static fn(): bool => true );
		$raw = file_get_contents( $url, false, stream_context_create( $options ) );
		restore_error_handler();

		if ( false === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true, 512, JSON_INVALID_UTF8_SUBSTITUTE );

		return is_array( $decoded ) ? $decoded : array();
	}
}
