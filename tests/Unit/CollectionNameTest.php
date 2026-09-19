<?php
/**
 * Collection naming tests.
 *
 * A collection name becomes a path segment in a URL and a generation suffix in a
 * rebuild. Both want the same guard, and the second wants arithmetic that does
 * not reuse the name of an index somebody may still be looking at.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Backend\Solr\CollectionName;

/**
 * @covers \SolrEngine\Backend\Solr\CollectionName
 */
final class CollectionNameTest extends TestCase {

	/**
	 * @dataProvider acceptable_names
	 *
	 * @param string $name Candidate name.
	 */
	public function test_acceptable_names( string $name ): void {
		$this->assertTrue( CollectionName::is_valid( $name ), $name . ' should be accepted' );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function acceptable_names(): array {
		return array(
			array( 'wordpress' ),
			array( 'wordpress_v2' ),
			array( 'my-site.prod' ),
			array( 'A1' ),
		);
	}

	/**
	 * @dataProvider unacceptable_names
	 *
	 * @param string $name Candidate name.
	 */
	public function test_unacceptable_names( string $name ): void {
		$this->assertFalse( CollectionName::is_valid( $name ), $name . ' should be refused' );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function unacceptable_names(): array {
		return array(
			array( '' ),
			// The ones that matter: each would address something other than the
			// collection it names once it is pasted into a URL path.
			array( '../admin/collections' ),
			array( 'wordpress/select' ),
			array( 'word press' ),
			array( '.hidden' ),
			array( '-flag' ),
			array( 'coll?action=DELETE' ),
			array( str_repeat( 'a', CollectionName::MAX_LENGTH + 1 ) ),
		);
	}

	public function test_a_plain_name_is_generation_one(): void {
		$this->assertSame( 1, CollectionName::generation( 'wordpress' ) );
		$this->assertSame( 'wordpress', CollectionName::base( 'wordpress' ) );
	}

	public function test_a_suffixed_name_declares_its_generation(): void {
		$this->assertSame( 7, CollectionName::generation( 'wordpress_v7' ) );
		$this->assertSame( 'wordpress', CollectionName::base( 'wordpress_v7' ) );
	}

	public function test_the_first_replacement_is_generation_two(): void {
		$this->assertSame( 'wordpress_v2', CollectionName::next( 'wordpress' ) );
	}

	public function test_the_next_name_skips_every_generation_present(): void {
		// A rebuild that was abandoned halfway leaves its collection behind.
		// Reusing that name would index into a half-built index somebody may
		// still be reading.
		$this->assertSame(
			'wordpress_v4',
			CollectionName::next( 'wordpress', array( 'wordpress', 'wordpress_v2', 'wordpress_v3' ) )
		);
	}

	public function test_unrelated_collections_do_not_affect_the_next_name(): void {
		$this->assertSame(
			'wordpress_v2',
			CollectionName::next( 'wordpress', array( 'other_v9', 'films_v4' ) )
		);
	}
}
