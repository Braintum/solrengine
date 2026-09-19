<?php
/**
 * Autocomplete against a real Solr.
 *
 * Prefix matching is the whole mechanism here, and whether Solr agrees that a
 * trailing wildcard on an analysed field matches what we expect is not
 * something a unit test can answer.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Backend;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;
use SolrEngine\Schema\SchemaStateFactory;
use SolrEngine\Search\SuggestTranslator;

/**
 * @covers \SolrEngine\Search\SuggestTranslator
 */
final class SuggestTest extends TestCase {

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
	 * Site this suite owns, so other backend suites cannot affect its counts.
	 *
	 * @var int
	 */
	private const SITE = 601;

	/**
	 * A neighbouring site in the same collection, which must never be suggested.
	 *
	 * Not `SITE + 1`, which is another suite's site: the two sets of fixtures
	 * then land in one place and each suite's counts depend on which ran last.
	 *
	 * @var int
	 */
	private const OTHER_SITE = 602;

	/**
	 * Title that must never be suggested.
	 *
	 * @var string
	 */
	private const PRIVATE_TITLE = 'Macbeth confidential board minutes';

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

	public function test_a_partial_word_matches_by_prefix(): void {
		// The control, and the whole mechanism: "macb" must reach "MacBook"
		// without the visitor finishing the word.
		$titles = $this->suggest( 'macb' );

		$this->assertContains( 'MacBook Pro review', $titles );
		$this->assertContains( 'MacBook Air comparison', $titles );
	}

	public function test_typing_more_narrows_the_list(): void {
		$this->assertGreaterThan(
			count( $this->suggest( 'macbook air' ) ),
			count( $this->suggest( 'macb' ) )
		);
	}

	public function test_every_word_must_match(): void {
		// "macbook zzz" matches a document containing "macbook" only if the
		// second word is ignored, which would make the list stop narrowing.
		$this->assertSame( array(), $this->suggest( 'macbook zzzznothing' ) );
	}

	public function test_a_complete_earlier_word_still_matches(): void {
		$this->assertContains( 'MacBook Air comparison', $this->suggest( 'macbook comp' ) );
	}

	public function test_private_documents_are_never_suggested(): void {
		// The private fixture starts with "Macb" too, so a prefix search would
		// return it were the visibility filter not applied.
		$this->assertNotContains( self::PRIVATE_TITLE, $this->suggest( 'macb' ) );
	}

	public function test_another_sites_documents_are_never_suggested(): void {
		$this->assertNotContains( 'MacBook from another site', $this->suggest( 'macb' ) );
	}

	public function test_a_prefix_that_is_too_short_is_not_sent_at_all(): void {
		$query = new SuggestQuery( 'm', self::SITE );

		$this->assertFalse( $query->is_long_enough() );
	}

	public function test_the_limit_is_honoured(): void {
		$query  = new SuggestQuery( 'mac', self::SITE, 2 );
		$titles = $this->titles_for( $query );

		$this->assertLessThanOrEqual( 2, count( $titles ) );
	}

	public function test_suggestions_carry_a_url_to_follow(): void {
		$response = self::call(
			'GET',
			'/select',
			null,
			( new SuggestTranslator() )->translate( new SuggestQuery( 'macb', self::SITE ) )
		);

		$docs = $response['response']['docs'] ?? array();

		$this->assertNotEmpty( $docs );

		foreach ( $docs as $doc ) {
			// A suggestion without a URL cannot be followed, which is the whole
			// reason these are documents rather than word completions.
			$this->assertNotEmpty( $doc[ DocumentFields::URL ] ?? '' );
		}
	}

	public function test_a_leading_wildcard_is_never_sent_to_solr(): void {
		// Solr answers a leading wildcard by walking the term dictionary, which
		// is expensive on an endpoint that fires on every keystroke.
		$params = ( new SuggestTranslator() )->translate( new SuggestQuery( '*mac', self::SITE ) );

		$this->assertStringStartsNotWith( '*', $params['se_terms'] );

		// And it still returns something useful rather than nothing.
		$this->assertNotSame( array(), $this->suggest( '*mac' ) );
	}

	// -- Plumbing ------------------------------------------------------------

	/**
	 * Suggested titles for a prefix.
	 *
	 * @param string $prefix Typed text.
	 * @return string[]
	 */
	private function suggest( string $prefix ): array {
		return $this->titles_for( new SuggestQuery( $prefix, self::SITE ) );
	}

	/**
	 * Suggested titles for a query.
	 *
	 * @param SuggestQuery $query The query.
	 * @return string[]
	 */
	private function titles_for( SuggestQuery $query ): array {
		$response = self::call( 'GET', '/select', null, ( new SuggestTranslator() )->translate( $query ) );

		$titles = array();

		foreach ( (array) ( $response['response']['docs'] ?? array() ) as $doc ) {
			$title = $doc[ DocumentFields::TITLE ] ?? '';

			$titles[] = is_array( $title ) ? (string) reset( $title ) : (string) $title;
		}

		return $titles;
	}

	/**
	 * Documents the assertions depend on.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	private static function fixtures(): array {
		$make = static function ( int $id, string $title, int $site, string $visibility ): array {
			return array(
				'id'                        => sprintf( 'se:%d:post:%d', $site, $id ),
				DocumentFields::SITE        => $site,
				DocumentFields::OBJECT_TYPE => 'post',
				DocumentFields::OBJECT_ID   => $id,
				DocumentFields::POST_TYPE   => 'post',
				DocumentFields::VISIBILITY  => $visibility,
				DocumentFields::TITLE       => $title,
				DocumentFields::URL         => 'https://example.test/' . $id,
			);
		};

		return array(
			$make( 7001, 'MacBook Pro review', self::SITE, 'public' ),
			$make( 7002, 'MacBook Air comparison', self::SITE, 'public' ),
			$make( 7003, 'Lenovo ThinkPad X1', self::SITE, 'public' ),
			$make( 7004, self::PRIVATE_TITLE, self::SITE, 'private' ),
			$make( 7005, 'MacBook from another site', self::OTHER_SITE, 'public' ),
		);
	}

	/**
	 * Perform an HTTP request, encoding repeated parameters as Solr needs.
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
