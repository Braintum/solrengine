<?php
/**
 * Dirty set coalescing tests.
 *
 * Coalescing is the difference between an importer running at normal speed and
 * an importer crawling, so the rules are worth pinning down.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Indexing\DirtySet;

/**
 * @covers \SolrEngine\Indexing\DirtySet
 */
final class DirtySetTest extends TestCase {

	private function ref( int $id ): DocumentRef {
		return new DocumentRef( 1, 'post', $id );
	}

	public function test_one_object_marked_many_times_yields_one_entry(): void {
		// A single wp_update_post fires save_post, wp_after_insert_post,
		// several meta hooks and set_object_terms per taxonomy. All of them
		// must collapse to one job.
		$set = new DirtySet();

		foreach ( array( 'post_saved', 'meta_changed', 'meta_changed', 'terms_changed' ) as $reason ) {
			$set->mark_for_index( $this->ref( 42 ), $reason );
		}

		$this->assertCount( 1, $set->upserts() );
		$this->assertSame( 1, $set->count() );
	}

	public function test_distinct_objects_stay_distinct(): void {
		$set = new DirtySet();

		$set->mark_for_index( $this->ref( 1 ) );
		$set->mark_for_index( $this->ref( 2 ) );

		$this->assertCount( 2, $set->upserts() );
	}

	public function test_deletion_wins_over_a_previous_index_mark(): void {
		// Trashing a post fires both a save-ish hook and the trash hook.
		// Indexing it would resurrect a document that should be gone.
		$set = new DirtySet();

		$set->mark_for_index( $this->ref( 7 ), 'post_saved' );
		$set->mark_for_delete( $this->ref( 7 ), 'trashed' );

		$this->assertSame( array(), $set->upserts() );
		$this->assertCount( 1, $set->deletes() );
	}

	public function test_deletion_wins_over_a_later_index_mark(): void {
		// Meta cleanup after a trash fires updated_post_meta, which would
		// otherwise re-mark the object for indexing.
		$set = new DirtySet();

		$set->mark_for_delete( $this->ref( 7 ), 'trashed' );
		$set->mark_for_index( $this->ref( 7 ), 'meta_changed' );

		$this->assertSame( array(), $set->upserts() );
		$this->assertCount( 1, $set->deletes() );
	}

	public function test_the_first_reason_is_kept(): void {
		// The first reason describes what happened; later marks in the same
		// request are usually side effects of it.
		$set = new DirtySet();

		$set->mark_for_index( $this->ref( 3 ), 'post_saved' );
		$set->mark_for_index( $this->ref( 3 ), 'meta_changed' );

		$this->assertSame( 'post_saved', $set->reason_for( $this->ref( 3 ) ) );
	}

	public function test_objects_on_different_sites_do_not_collide(): void {
		$set = new DirtySet();

		$set->mark_for_index( new DocumentRef( 1, 'post', 42 ) );
		$set->mark_for_index( new DocumentRef( 2, 'post', 42 ) );

		$this->assertCount( 2, $set->upserts() );
	}

	public function test_clearing_empties_everything(): void {
		$set = new DirtySet();

		$set->mark_for_index( $this->ref( 1 ) );
		$set->mark_for_delete( $this->ref( 2 ) );
		$set->clear();

		$this->assertTrue( $set->is_empty() );
		$this->assertSame( '', $set->reason_for( $this->ref( 1 ) ) );
	}

	public function test_a_new_set_is_empty(): void {
		$this->assertTrue( ( new DirtySet() )->is_empty() );
	}
}
