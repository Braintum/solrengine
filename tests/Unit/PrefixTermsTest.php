<?php
/**
 * Prefix matching rule tests.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Search\PrefixTerms;

/**
 * @covers \SolrEngine\Search\PrefixTerms
 */
final class PrefixTermsTest extends TestCase {

	public function test_only_the_last_word_is_completed(): void {
		$this->assertSame( 'hoo*', PrefixTerms::complete_last( 'hoo' ) );
		$this->assertSame( 'red hoo*', PrefixTerms::complete_last( 'red hoo' ) );
	}

	public function test_letters_and_digits_in_any_script_are_completable(): void {
		foreach ( array( 'ho', 'größe', 'café', 'iphone15', 'ноут', '12' ) as $word ) {
			$this->assertTrue( PrefixTerms::completable( $word ), $word );
		}
	}

	public function test_short_words_and_punctuation_are_left_alone(): void {
		// Never assemble syntax from what the visitor typed, and never expand a
		// single character across the whole term dictionary.
		foreach ( array( '', 'h', 'wi-fi', 'hoo*', '*hoo', 'hoo~1', '"hoo', 'a:b', '(hoo', 'C++' ) as $word ) {
			$this->assertFalse( PrefixTerms::completable( $word ), $word );
			$this->assertSame( 'red ' . $word, PrefixTerms::complete_last( 'red ' . $word ), $word );
		}
	}
}
