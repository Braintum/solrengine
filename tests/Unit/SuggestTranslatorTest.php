<?php
/**
 * Autocomplete query construction tests.
 *
 * The suggest path carries visitor text on every keystroke, so it gets the same
 * scrutiny as the search path rather than being treated as a lesser feature.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\SuggestTranslator;

/**
 * @covers \SolrEngine\Search\SuggestTranslator
 * @covers \SolrEngine\Contracts\SuggestQuery
 */
final class SuggestTranslatorTest extends TestCase {

	private function translate( string $prefix, int $site = 1 ): array {
		return ( new SuggestTranslator() )->translate( new SuggestQuery( $prefix, $site ) );
	}

	// -- Prefix building ----------------------------------------------------

	public function test_the_last_word_gets_a_wildcard(): void {
		$this->assertSame( 'macb*', $this->translate( 'macb' )['se_terms'] );
	}

	public function test_only_the_last_word_gets_a_wildcard(): void {
		// Earlier words are finished. Only the one being typed is a prefix.
		$this->assertSame( 'apple macb*', $this->translate( 'apple macb' )['se_terms'] );
	}

	public function test_a_one_character_last_word_gets_no_wildcard(): void {
		// A single-letter prefix matches most of the index and is slow.
		$this->assertSame( 'apple m', $this->translate( 'apple m' )['se_terms'] );
	}

	public function test_a_word_with_punctuation_is_passed_through_untouched(): void {
		// Never assemble syntax out of punctuation a visitor typed.
		$this->assertSame( 'mac-book', $this->translate( 'mac-book' )['se_terms'] );
	}

	public function test_no_leading_wildcard_is_ever_produced(): void {
		foreach ( array( 'macb', '*mac', 'a b c', '**' ) as $input ) {
			$terms = $this->translate( $input )['se_terms'];

			$this->assertStringStartsNotWith( '*', $terms, 'Leading wildcard from ' . $input );
		}
	}

	public function test_whitespace_is_collapsed(): void {
		$this->assertSame( 'apple macb*', $this->translate( "  apple \n  macb  " )['se_terms'] );
	}

	// -- Injection resistance ------------------------------------------------

	/**
	 * @dataProvider hostile_input
	 */
	public function test_typed_text_never_becomes_query_syntax( string $attack ): void {
		$params = $this->translate( $attack );

		$this->assertSame( '{!edismax v=$se_terms}', $params['q'] );
		$this->assertArrayHasKey( 'se_terms', $params );
		$this->assertStringNotContainsString( '{!', $params['se_terms'] );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function hostile_input(): array {
		return array(
			array( '{!lucene}se_s_visibility:private' ),
			array( '{!xmlparser v="x"}' ),
			array( '{!join from=id to=id fromIndex=other}*:*' ),
		);
	}

	public function test_no_deftype_is_sent(): void {
		// Same trap as the search path: defType plus a local parameter on q
		// makes edismax parse the parameter as literal text.
		$this->assertArrayNotHasKey( 'defType', $this->translate( 'macb' ) );
	}

	// -- Mandatory filters ---------------------------------------------------

	public function test_the_site_scope_is_always_applied(): void {
		$this->assertContains( DocumentFields::SITE . ':7', $this->translate( 'macb', 7 )['fq'] );
	}

	public function test_only_public_documents_are_ever_suggested(): void {
		// An autocomplete leaking a private title leaks it as completely as a
		// results page does.
		$this->assertContains( DocumentFields::VISIBILITY . ':public', $this->translate( 'macb' )['fq'] );
	}

	// -- Shape ---------------------------------------------------------------

	public function test_every_word_must_match(): void {
		// Autocomplete narrows as you type, so a result matching only the first
		// of three words is wrong.
		$this->assertSame( '100%', $this->translate( 'a b c' )['mm'] );
	}

	public function test_matching_is_limited_to_titles(): void {
		// Matching content would surface documents whose titles have nothing
		// to do with what was typed, which reads as noise in a short list.
		$qf = $this->translate( 'macb' )['qf'];

		$this->assertStringContainsString( DocumentFields::TITLE, $qf );
		$this->assertStringNotContainsString( DocumentFields::CONTENT, $qf );
	}

	public function test_the_request_is_time_bounded(): void {
		$this->assertArrayHasKey( 'timeAllowed', $this->translate( 'macb' ) );
	}

	public function test_returned_fields_are_only_what_the_dropdown_renders(): void {
		$fl = $this->translate( 'macb' )['fl'];

		$this->assertStringContainsString( DocumentFields::TITLE, $fl );
		$this->assertStringContainsString( DocumentFields::URL, $fl );
		$this->assertStringNotContainsString( DocumentFields::CONTENT, $fl );
		$this->assertStringNotContainsString( '*', $fl );
	}

	// -- Query object --------------------------------------------------------

	public function test_short_prefixes_are_recognised_as_not_worth_a_request(): void {
		$this->assertFalse( ( new SuggestQuery( 'm', 1 ) )->is_long_enough() );
		$this->assertFalse( ( new SuggestQuery( '  ', 1 ) )->is_long_enough() );
		$this->assertTrue( ( new SuggestQuery( 'ma', 1 ) )->is_long_enough() );
	}

	public function test_the_limit_is_capped(): void {
		// A list longer than a screenful is not a list anybody reads, and every
		// row costs latency on a request that fires mid-typing.
		$this->assertSame( 20, ( new SuggestQuery( 'x', 1, 500 ) )->limit() );
		$this->assertSame( 1, ( new SuggestQuery( 'x', 1, 0 ) )->limit() );
	}

	public function test_cache_keys_ignore_case_and_surrounding_whitespace(): void {
		// A visitor typing eight characters generates several requests, and the
		// shorter prefixes were probably generated by somebody else a moment
		// ago. Normalising widens that overlap.
		$a = new SuggestQuery( 'MacBook', 1 );
		$b = new SuggestQuery( '  macbook ', 1 );

		$this->assertSame( $a->cache_key(), $b->cache_key() );
	}

	public function test_cache_keys_separate_sites(): void {
		$this->assertNotSame(
			( new SuggestQuery( 'mac', 1 ) )->cache_key(),
			( new SuggestQuery( 'mac', 2 ) )->cache_key()
		);
	}
}
