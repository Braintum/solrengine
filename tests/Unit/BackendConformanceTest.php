<?php
/**
 * Backend contract conformance.
 *
 * Phase 9's outcome is that a second adapter proves the abstraction. This is
 * where the proof is actually made: the rules the contracts state in prose are
 * asserted here, against an implementation, so that "implementations must treat
 * writes as upserts" is a failing test rather than a sentence in a docblock.
 *
 * Every rule below is quoted from the contract it comes from. The Solr adapter
 * is held to the same rules by the backend suite, which needs a running Solr;
 * this suite needs nothing, so the rules are checked on every commit rather than
 * on every commit that someone remembered to start Docker for.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Backend\Memory\MemoryBackend;
use SolrEngine\Contracts\Document;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\IndexStatus;
use SolrEngine\Contracts\LifecycleBackend;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Contracts\Selector;
use SolrEngine\Documents\DocumentFields;
use SolrEngine\Search\FilterRegistry;

/**
 * @covers \SolrEngine\Backend\Memory\MemoryBackend
 */
final class BackendConformanceTest extends TestCase {

	/**
	 * A backend with a filter registered, ready to be written to.
	 *
	 * @return MemoryBackend
	 */
	private function backend(): MemoryBackend {
		$filters = new FilterRegistry();

		$filters->register( 'post_type', DocumentFields::POST_TYPE, 's' );
		$filters->register( 'category', DocumentFields::taxonomy_ids( 'category' ), 'i' );

		return new MemoryBackend( $filters );
	}

	/**
	 * A document for site 1.
	 *
	 * @param int                 $id     Object identifier.
	 * @param array<string,mixed> $fields Field overrides.
	 * @return Document
	 */
	private function document( int $id, array $fields = array() ): Document {
		return new Document(
			new DocumentRef( 1, 'post', $id ),
			array_merge(
				array(
					DocumentFields::SITE        => 1,
					DocumentFields::OBJECT_TYPE => 'post',
					DocumentFields::OBJECT_ID   => $id,
					DocumentFields::POST_TYPE   => 'post',
					DocumentFields::VISIBILITY  => 'public',
					DocumentFields::TITLE       => 'Document ' . $id,
					DocumentFields::TITLE_SORT  => 'Document ' . $id,
					DocumentFields::CONTENT     => 'Body text',
				),
				$fields
			)
		);
	}

	// -- The contracts are all implemented ---------------------------------

	public function test_one_class_may_satisfy_every_contract(): void {
		$backend = $this->backend();

		$this->assertInstanceOf( SearchBackend::class, $backend );
		$this->assertInstanceOf( IndexBackend::class, $backend );
		$this->assertInstanceOf( SchemaBackend::class, $backend );
		$this->assertInstanceOf( LifecycleBackend::class, $backend );
	}

	public function test_nothing_throws_when_the_backend_is_asked_the_impossible(): void {
		// "Nothing here throws. A backend that is down is an expected
		// condition." Every method returns a Result, including the ones this
		// backend cannot satisfy.
		$backend = $this->backend();

		$this->assertTrue( $backend->create_index( 'x' )->is_failure() );
		$this->assertTrue( $backend->drop_index( 'x' )->is_failure() );
		$this->assertTrue( $backend->describe()->is_failure() );
		$this->assertTrue( $backend->ping()->is_ok() );
	}

	// -- IndexBackend ------------------------------------------------------

	public function test_writes_are_upserts_keyed_on_the_document_id(): void {
		// "Writes are upserts keyed on DocumentRef::document_id(), so reindexing
		// is idempotent and a replayed job costs time rather than correctness."
		$backend = $this->backend();

		$backend->write_batch( array( $this->document( 1 ) ) );
		$backend->write_batch( array( $this->document( 1 ) ) );
		$backend->write_batch( array( $this->document( 1, array( DocumentFields::TITLE => 'Changed' ) ) ) );

		$status = $backend->status()->value();

		$this->assertInstanceOf( IndexStatus::class, $status );
		$this->assertSame( 1, $status->document_count() );
	}

	public function test_an_empty_batch_is_a_success(): void {
		$this->assertTrue( $this->backend()->write_batch( array() )->is_ok() );
		$this->assertTrue( $this->backend()->delete( array() )->is_ok() );
	}

	public function test_deleting_something_absent_is_a_success(): void {
		// "Deleting a reference that is not present is a success, not a failure:
		// the desired end state is absent."
		$backend = $this->backend();

		$this->assertTrue( $backend->delete( array( new DocumentRef( 1, 'post', 99 ) ) )->is_ok() );
	}

	public function test_an_unconstrained_selector_is_refused(): void {
		// "Implementations must refuse an unconstrained selector." Deleting
		// everything must be an explicit decision, never something reachable by
		// forgetting to set a field.
		$backend = $this->backend();

		$backend->write_batch( array( $this->document( 1 ) ) );

		$refused = $backend->delete_by_selector( new Selector() );

		$this->assertTrue( $refused->is_failure() );
		$this->assertSame( 'unconstrained_selector', $refused->code() );
		$this->assertSame( 1, $backend->status()->value()->document_count() );
	}

	public function test_a_constrained_selector_removes_only_what_it_names(): void {
		$backend = $this->backend();

		$backend->write_batch(
			array(
				$this->document( 1 ),
				$this->document( 2, array( DocumentFields::POST_TYPE => 'page' ) ),
			)
		);

		$backend->delete_by_selector( new Selector( 1, '', 'page' ) );

		$this->assertSame( 1, $backend->status()->value()->document_count() );
	}

	// -- SearchBackend -----------------------------------------------------

	public function test_a_search_never_crosses_sites(): void {
		// se_i_site is the only thing separating tenants in a shared collection,
		// so it is a security control rather than a scoping convenience — and it
		// has to hold in every backend, not just the one that was tested.
		$backend = $this->backend();

		$backend->write_batch( array( $this->document( 1 ) ) );

		$results = $backend->search( new SearchQuery( 'Document', 2 ) )->value();

		$this->assertInstanceOf( SearchResults::class, $results );
		$this->assertTrue( $results->is_empty() );
	}

	public function test_a_search_never_returns_non_public_documents(): void {
		$backend = $this->backend();

		$backend->write_batch(
			array( $this->document( 1, array( DocumentFields::VISIBILITY => 'private' ) ) )
		);

		$this->assertTrue( $backend->search( new SearchQuery( 'Document', 1 ) )->value()->is_empty() );
	}

	public function test_results_carry_the_query_that_produced_them(): void {
		$backend = $this->backend();
		$query   = new SearchQuery( 'Document', 1 );

		$backend->write_batch( array( $this->document( 1 ) ) );

		$this->assertSame( $query, $backend->search( $query )->value()->query() );
	}

	public function test_totals_count_matches_rather_than_the_page(): void {
		$backend = $this->backend();

		for ( $id = 1; $id <= 5; $id++ ) {
			$backend->write_batch( array( $this->document( $id ) ) );
		}

		$results = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_pagination( 1, 2 )
		)->value();

		$this->assertCount( 2, $results->hits() );
		$this->assertSame( 5, $results->total() );
		$this->assertSame( 3, $results->total_pages() );
	}

	public function test_a_query_wanting_no_documents_still_reports_a_total(): void {
		// A facets-only request. Fetching documents in order to discard them
		// costs the stored-field retrieval on the request a visitor repeats most.
		$backend = $this->backend();

		$backend->write_batch( array( $this->document( 1 ) ) );

		$results = $backend->search( ( new SearchQuery( 'Document', 1 ) )->without_documents() )->value();

		$this->assertSame( array(), $results->hits() );
		$this->assertSame( 1, $results->total() );
	}

	public function test_a_filter_narrows_and_an_unknown_key_does_not(): void {
		$backend = $this->backend();

		$backend->write_batch(
			array(
				$this->document( 1 ),
				$this->document( 2, array( DocumentFields::POST_TYPE => 'page' ) ),
			)
		);

		$filtered = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_filter( 'post_type', array( 'page' ) )
		)->value();

		$this->assertSame( 1, $filtered->total() );

		// An unregistered key applies nothing rather than matching nothing.
		// Rejection of unknown keys happens at the request boundary, where
		// there is something to fail.
		$ignored = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_filter( 'invented', array( 'x' ) )
		)->value();

		$this->assertSame( 2, $ignored->total() );
	}

	public function test_two_filter_values_mean_either_unless_asked_otherwise(): void {
		$backend = $this->backend();
		$field   = DocumentFields::taxonomy_ids( 'category' );

		$backend->write_batch(
			array(
				$this->document( 1, array( $field => array( 10 ) ) ),
				$this->document( 2, array( $field => array( 10, 20 ) ) ),
			)
		);

		$either = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_filter( 'category', array( 10, 20 ) )
		)->value();

		$both = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_filter( 'category', array( 10, 20 ), 'and' )
		)->value();

		$this->assertSame( 2, $either->total() );
		$this->assertSame( 1, $both->total() );
	}

	public function test_a_language_scope_keeps_documents_that_have_no_language(): void {
		// The same tolerance the Solr clause has, and for the same reason: a
		// document indexed before languages existed must not vanish from results
		// the day a multilingual plugin is activated.
		$backend = $this->backend();

		$backend->write_batch(
			array(
				$this->document( 1 ),
				$this->document( 2, array( DocumentFields::LANG => 'de-DE' ) ),
				$this->document( 3, array( DocumentFields::LANG => 'fr-FR' ) ),
			)
		);

		$results = $backend->search(
			( new SearchQuery( 'Document', 1 ) )->with_language( 'de-DE' )
		)->value();

		$this->assertSame( 2, $results->total() );
	}

	public function test_a_title_match_outranks_a_content_match(): void {
		$backend = $this->backend();

		$backend->write_batch(
			array(
				$this->document( 1, array( DocumentFields::CONTENT => 'mentions widgets once' ) ),
				$this->document( 2, array( DocumentFields::TITLE => 'Widgets' ) ),
			)
		);

		$hits = $backend->search( new SearchQuery( 'widgets', 1 ) )->value()->hits();

		$this->assertSame( 2, $hits[0]->ref()->object_id() );
	}

	// -- SchemaBackend, in the managed case --------------------------------

	public function test_a_managed_backend_reports_itself_unmanageable(): void {
		// §14.4: "the Cloud adapter simply reports managed and the UI adapts."
		// Before there was a second adapter, is_manageable() had no implementor
		// that returned false and no caller that honoured it.
		$backend = $this->backend();

		$this->assertFalse( $backend->is_manageable() );
		$this->assertFalse( $backend->capabilities()->supports( 'schema_api' ) );
	}

	public function test_a_managed_backend_plans_nothing_rather_than_failing(): void {
		// An empty plan, not an error. The contract is already satisfied,
		// because a managed backend accepts whatever the document model
		// produces — that is what being managed means.
		$backend  = $this->backend();
		$contract = ( new \SolrEngine\Schema\ContractRegistry() )->current();

		$planned = $backend->plan( $contract );

		$this->assertTrue( $planned->is_ok() );
		$this->assertTrue( $planned->value()->is_empty() );
		$this->assertFalse( $planned->value()->is_applicable() );
	}

	public function test_a_managed_backend_refuses_to_be_changed(): void {
		$backend  = $this->backend();
		$contract = ( new \SolrEngine\Schema\ContractRegistry() )->current();

		$this->assertTrue( $backend->apply( $backend->plan( $contract )->value() )->is_failure() );
	}

	// -- LifecycleBackend, in the case with no lifecycle -------------------

	public function test_a_backend_with_one_index_says_so(): void {
		$backend = $this->backend();

		$this->assertSame( LifecycleBackend::STYLE_NONE, $backend->rebuild_style() );
		$this->assertFalse( $backend->can_create_indexes() );
	}
}
