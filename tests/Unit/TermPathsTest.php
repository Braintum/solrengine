<?php
/**
 * Taxonomy path format tests.
 *
 * The format is written by the document builder and read by the facet layer.
 * These cover the half that needs no WordPress; the half that walks a real
 * taxonomy is exercised by the integration suite.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Documents\TermPaths;

/**
 * @covers \SolrEngine\Documents\TermPaths
 */
final class TermPathsTest extends TestCase {

	/**
	 * @dataProvider paths
	 */
	public function test_a_path_is_recognised( string $value, bool $expected ): void {
		$this->assertSame( $expected, TermPaths::is_path( $value ) );
	}

	/**
	 * @return array<string,array{0:string,1:bool}>
	 */
	public function paths(): array {
		return array(
			'root'         => array( '0/12', true ),
			'nested'       => array( '2/12/34/56', true ),
			'bare id'      => array( '12', false ),
			'empty'        => array( '', false ),
			'query syntax' => array( '0/12 OR se_s_visibility:private', false ),
			'traversal'    => array( '../12', false ),
		);
	}

	public function test_the_term_is_the_last_segment(): void {
		$this->assertSame( 56, TermPaths::term_id( '2/12/34/56' ) );
		$this->assertSame( 12, TermPaths::term_id( '0/12' ) );
	}

	public function test_something_that_is_not_a_path_names_no_term(): void {
		$this->assertSame( 0, TermPaths::term_id( 'se_s_visibility:private' ) );
		$this->assertSame( 0, TermPaths::term_id( '12' ) );
	}

	public function test_a_parent_is_one_level_up(): void {
		$this->assertSame( '1/12/34', TermPaths::parent_of( '2/12/34/56' ) );
		$this->assertSame( '0/12', TermPaths::parent_of( '1/12/34' ) );
	}

	public function test_a_root_has_no_parent(): void {
		$this->assertSame( '', TermPaths::parent_of( '0/12' ) );
	}
}
