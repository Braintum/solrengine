<?php
/**
 * Schema planning tests.
 *
 * The planner decides what SolrEngine would do to somebody else's Solr, so its
 * rules matter more than most. Pure logic, so no Solr is needed here.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\CopyFieldRule;
use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaField;
use SolrEngine\Contracts\SchemaState;
use SolrEngine\Schema\ContractRegistry;
use SolrEngine\Schema\SchemaPlanner;

/**
 * @covers \SolrEngine\Schema\SchemaPlanner
 * @covers \SolrEngine\Contracts\SchemaContract
 * @covers \SolrEngine\Contracts\SchemaPlan
 */
final class SchemaPlannerTest extends TestCase {

	/**
	 * A schema state with every required field type present.
	 *
	 * @param array<string,array<string,mixed>> $fields   Concrete fields.
	 * @param array<string,array<string,mixed>> $dynamic  Dynamic patterns.
	 * @param string[]                          $copies   Copy rule keys.
	 * @param bool                              $mutable  Schema API usable.
	 */
	private function state(
		array $fields = array(),
		array $dynamic = array(),
		array $copies = array(),
		bool $mutable = true
	): SchemaState {
		return new SchemaState(
			$fields,
			$dynamic,
			$copies,
			array_keys( ContractRegistry::REQUIRED_TYPES ),
			$mutable,
			'id'
		);
	}

	private function planner(): SchemaPlanner {
		return new SchemaPlanner();
	}

	// -- The shipped contract must be valid --------------------------------

	public function test_the_shipped_contract_is_valid_solr(): void {
		// Guards against shipping a contract that no Solr would accept. The
		// original plan used mid-name wildcards like se_*_txt, which Solr
		// rejects for copyField sources; this test is what stops that class of
		// mistake reaching a customer's server.
		$contract = ( new ContractRegistry() )->current();

		$this->assertSame( array(), $contract->validate() );
	}

	public function test_every_dynamic_pattern_has_a_trailing_wildcard(): void {
		foreach ( ( new ContractRegistry() )->current()->dynamic_fields() as $field ) {
			$this->assertStringEndsWith( '*', $field->name(), $field->name() . ' must end in a wildcard' );
			$this->assertSame( 1, substr_count( $field->name(), '*' ), $field->name() . ' must have exactly one wildcard' );
		}
	}

	public function test_every_field_is_inside_the_se_namespace(): void {
		// The namespace is what makes "only touch fields we own" expressible.
		foreach ( ( new ContractRegistry() )->current()->fields() as $field ) {
			$this->assertStringStartsWith( 'se_', $field->name() );
		}
	}

	public function test_no_copy_rule_chains_into_another(): void {
		// Solr states that no copy feeds into another copy.
		$contract     = ( new ContractRegistry() )->current();
		$destinations = array();

		foreach ( $contract->copy_fields() as $rule ) {
			$destinations[] = $rule->destination();
		}

		foreach ( $contract->copy_fields() as $rule ) {
			$this->assertNotContains(
				$rule->source(),
				$destinations,
				$rule->key() . ' chains from a copy destination'
			);
		}
	}

	// -- Planning ----------------------------------------------------------

	public function test_an_empty_schema_produces_one_operation_per_requirement(): void {
		$contract = new SchemaContract(
			1,
			array( new SchemaField( 'se_txt_title', 'text_general' ) ),
			array( new CopyFieldRule( 'se_txt_title', 'se_all' ) )
		);

		$plan = $this->planner()->plan( $contract, $this->state() );

		$this->assertCount( 2, $plan->operations() );
		$this->assertTrue( $plan->is_applicable() );
		$this->assertSame( 'add-field', $plan->operations()[0]->command() );
		$this->assertSame( 'add-copy-field', $plan->operations()[1]->command() );
	}

	public function test_a_satisfied_schema_produces_no_operations(): void {
		$contract = new SchemaContract(
			1,
			array( new SchemaField( 'se_txt_title', 'text_general' ) ),
			array( new CopyFieldRule( 'se_txt_title', 'se_all' ) )
		);

		$plan = $this->planner()->plan(
			$contract,
			$this->state(
				array(
					'se_txt_title' => array(
						'name'    => 'se_txt_title',
						'type'    => 'text_general',
						'indexed' => true,
						'stored'  => true,
					),
				),
				array(),
				array( 'se_txt_title=>se_all' )
			)
		);

		$this->assertTrue( $plan->is_empty() );
		$this->assertFalse( $plan->is_applicable() );
	}

	public function test_dynamic_patterns_are_planned_as_dynamic_fields(): void {
		$contract = new SchemaContract( 1, array( new SchemaField( 'se_ss_*', 'string', array( 'multiValued' => true ) ) ) );

		$plan = $this->planner()->plan( $contract, $this->state() );

		$this->assertSame( 'add-dynamic-field', $plan->operations()[0]->command() );
	}

	public function test_every_operation_is_additive(): void {
		// SolrEngine must never emit replace-field or delete-field, even for
		// its own fields. ADR-003.
		$plan = $this->planner()->plan( ( new ContractRegistry() )->current(), $this->state() );

		$this->assertTrue( $plan->is_additive() );

		foreach ( $plan->operations() as $operation ) {
			$this->assertStringStartsWith( 'add-', $operation->command() );
		}
	}

	public function test_new_fields_are_marked_as_requiring_a_reindex(): void {
		// Solr never backfills. Modifying a schema never modifies documents
		// that are already indexed.
		$contract = new SchemaContract( 1, array( new SchemaField( 'se_txt_title', 'text_general' ) ) );

		$this->assertTrue( $this->planner()->plan( $contract, $this->state() )->requires_reindex() );
	}

	// -- Blockers ----------------------------------------------------------

	public function test_a_conflicting_existing_field_blocks_rather_than_being_replaced(): void {
		// The customer may have widened this field deliberately. Replacing it
		// would discard their change without asking.
		$contract = new SchemaContract( 1, array( new SchemaField( 'se_txt_title', 'text_general' ) ) );

		$plan = $this->planner()->plan(
			$contract,
			$this->state(
				array(
					'se_txt_title' => array(
						'name' => 'se_txt_title',
						'type' => 'string',
					),
				)
			)
		);

		$this->assertNotSame( array(), $plan->blockers() );
		$this->assertFalse( $plan->is_applicable() );
		$this->assertSame( array(), $plan->operations() );
	}

	public function test_an_immutable_schema_blocks_the_plan(): void {
		$contract = new SchemaContract( 1, array( new SchemaField( 'se_txt_title', 'text_general' ) ) );

		$plan = $this->planner()->plan( $contract, $this->state( array(), array(), array(), false ) );

		$this->assertFalse( $plan->is_applicable() );
		$this->assertNotSame( array(), $plan->blockers() );
	}

	public function test_a_missing_field_type_blocks_the_plan(): void {
		// SolrEngine will not create field types. An analyser is a far larger
		// commitment than a field and a wrong one is not recoverable.
		$state = new SchemaState( array(), array(), array(), array( 'string' ), true, 'id' );

		$plan = $this->planner()->plan( ( new ContractRegistry() )->current(), $state );

		$this->assertFalse( $plan->is_applicable() );
		$this->assertNotSame( array(), $plan->blockers() );
	}

	public function test_a_wider_existing_field_still_satisfies_the_contract(): void {
		// Stored when we asked for unstored, or extra attributes we never
		// mentioned, is the customer's business, not a conflict.
		$contract = new SchemaContract( 1, array( new SchemaField( 'se_txt_title', 'text_general', array( 'stored' => false ) ) ) );

		$plan = $this->planner()->plan(
			$contract,
			$this->state(
				array(
					'se_txt_title' => array(
						'name'      => 'se_txt_title',
						'type'      => 'text_general',
						'indexed'   => true,
						'stored'    => true,
						'docValues' => true,
					),
				)
			)
		);

		$this->assertTrue( $plan->is_empty() );
	}

	// -- Fingerprinting ----------------------------------------------------

	public function test_the_fingerprint_only_covers_fields_we_own(): void {
		$planner = $this->planner();

		$ours = $this->state(
			array(
				'se_txt_title' => array( 'name' => 'se_txt_title' ),
				'their_field'  => array( 'name' => 'their_field' ),
			)
		);

		$theirs_changed = $this->state(
			array(
				'se_txt_title'    => array( 'name' => 'se_txt_title' ),
				'their_field'     => array( 'name' => 'their_field' ),
				'another_of_thei' => array( 'name' => 'another_of_thei' ),
			)
		);

		$this->assertSame( $planner->fingerprint( $ours ), $planner->fingerprint( $theirs_changed ) );
	}

	public function test_the_fingerprint_changes_when_our_fields_change(): void {
		$planner = $this->planner();

		$before = $this->state( array( 'se_txt_title' => array( 'name' => 'se_txt_title' ) ) );
		$after  = $this->state(
			array(
				'se_txt_title'   => array( 'name' => 'se_txt_title' ),
				'se_txt_content' => array( 'name' => 'se_txt_content' ),
			)
		);

		$this->assertNotSame( $planner->fingerprint( $before ), $planner->fingerprint( $after ) );
	}
}
