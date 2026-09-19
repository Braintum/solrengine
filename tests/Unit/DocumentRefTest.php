<?php
/**
 * DocumentRef tests.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\DocumentRef;

/**
 * @covers \SolrEngine\Contracts\DocumentRef
 */
final class DocumentRefTest extends TestCase {

	public function test_document_id_uses_the_composite_format(): void {
		$ref = new DocumentRef( 1, 'post', 4211 );

		$this->assertSame( 'se:1:post:4211', $ref->document_id() );
	}

	/**
	 * The 0.1.0 prototype used the bare post ID, which collides on three
	 * separate axes. Each of these pairs would have shared an identifier.
	 */
	public function test_ids_do_not_collide_across_object_types(): void {
		$post    = new DocumentRef( 1, 'post', 42 );
		$product = new DocumentRef( 1, 'product', 42 );

		$this->assertNotSame( $post->document_id(), $product->document_id() );
	}

	public function test_ids_do_not_collide_across_sites(): void {
		$one = new DocumentRef( 1, 'post', 42 );
		$two = new DocumentRef( 2, 'post', 42 );

		$this->assertNotSame( $one->document_id(), $two->document_id() );
	}

	public function test_ids_do_not_collide_across_object_and_term_ids(): void {
		$post = new DocumentRef( 1, 'post', 7 );
		$term = new DocumentRef( 1, 'term', 7 );

		$this->assertNotSame( $post->document_id(), $term->document_id() );
	}

	public function test_equal_references_share_a_dedupe_key(): void {
		$one = new DocumentRef( 1, 'post', 42 );
		$two = new DocumentRef( 1, 'post', 42 );

		$this->assertTrue( $one->equals( $two ) );
		$this->assertSame( $one->dedupe_key(), $two->dedupe_key() );
	}

	public function test_different_references_are_not_equal(): void {
		$one = new DocumentRef( 1, 'post', 42 );
		$two = new DocumentRef( 1, 'post', 43 );

		$this->assertFalse( $one->equals( $two ) );
	}
}
