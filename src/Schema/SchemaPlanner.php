<?php
/**
 * Works out what a schema is missing.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Contracts\SchemaContract;
use SolrEngine\Contracts\SchemaOperation;
use SolrEngine\Contracts\SchemaPlan;
use SolrEngine\Contracts\SchemaState;

/**
 * Compares a required contract against a live schema.
 *
 * Pure. No Solr, no WordPress, no network — which is what makes the rules
 * below testable in milliseconds instead of against a container, and is the
 * layering the plan's section 11.1 asks for.
 *
 * Three rules govern everything here, all from ADR-003:
 *
 * 1. **Additive only.** The planner emits add-field, add-dynamic-field and
 *    add-copy-field. Never replace, never delete — not even for SolrEngine's
 *    own fields. A field that already exists and is wrong is reported as a
 *    blocker for a human to resolve, because silently replacing it would
 *    discard whatever the customer changed it to.
 * 2. **Only the "se_" namespace.** Nothing outside it is ever inspected or
 *    touched.
 * 3. **Field types are never created.** An analyser definition is a much
 *    larger commitment than a field, and a wrong one is not recoverable by
 *    adding another field. A missing type is a blocker, and the shipped
 *    configset is the answer.
 *
 * @since 0.1.0
 */
final class SchemaPlanner {

	/**
	 * Build a plan.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaContract $contract What is required.
	 * @param SchemaState    $state    What exists.
	 * @return SchemaPlan
	 */
	public function plan( SchemaContract $contract, SchemaState $state ): SchemaPlan {
		$operations = array();
		$blockers   = array();

		foreach ( $contract->validate() as $problem ) {
			// A malformed contract is our bug, not the customer's. Surfacing it
			// as a blocker beats sending an invalid command to their server.
			$blockers[] = $problem;
		}

		foreach ( array_keys( ContractRegistry::REQUIRED_TYPES ) as $type ) {
			if ( ! $state->has_field_type( (string) $type ) ) {
				$blockers[] = sprintf(
					/* translators: %s: Solr field type name. */
					__( 'The field type "%s" is not defined in this schema. SolrEngine will not create field types, because an analyser is a larger commitment than a field. Apply the shipped configset, or add the type yourself.', 'solrengine' ),
					$type
				);
			}
		}

		if ( ! $state->is_mutable() ) {
			$blockers[] = __( 'This schema cannot be changed through the Schema API. It is either not a managed schema, or the credentials lack permission. Use the exported configuration instead.', 'solrengine' );
		}

		foreach ( $contract->fields() as $field ) {
			if ( ! $state->has_field_type( $field->type() ) ) {
				// Caught here rather than at apply time. A contract may name a
				// type outside the required set — a per-language analyser is one
				// a site opted into — and the difference between a blocker and a
				// failed apply is whether an administrator finds out before or
				// after half the plan has been sent.
				$blockers[] = sprintf(
					/* translators: 1: field name, 2: Solr field type name. */
					__( '"%1$s" needs the field type "%2$s", which this schema does not define. SolrEngine will not create field types. Apply the shipped configset, which defines the language analysers, or add the type yourself.', 'solrengine' ),
					$field->name(),
					$field->type()
				);

				continue;
			}

			$live = $field->is_dynamic()
				? $state->dynamic_field( $field->name() )
				: $state->field( $field->name() );

			if ( null === $live ) {
				$operations[] = new SchemaOperation(
					$field->is_dynamic() ? 'add-dynamic-field' : 'add-field',
					$field->to_payload(),
					sprintf(
						/* translators: 1: field name, 2: field type, 3: why it exists. */
						__( 'Add %1$s (%2$s) — %3$s', 'solrengine' ),
						$field->name(),
						$field->type(),
						$field->purpose()
					),
					// A new field holds nothing until documents are written
					// again. Modifying a schema never modifies documents that
					// are already indexed, so Solr will not backfill it.
					true
				);

				continue;
			}

			if ( ! $field->is_satisfied_by( $live ) ) {
				$blockers[] = sprintf(
					/* translators: 1: field name, 2: required type. */
					__( '"%1$s" already exists but does not match what SolrEngine needs (type %2$s). SolrEngine will not replace a field it did not create. Adjust it yourself, or use a separate collection.', 'solrengine' ),
					$field->name(),
					$field->type()
				);
			}
		}

		foreach ( $contract->copy_fields() as $rule ) {
			if ( $state->has_copy_field( $rule ) ) {
				continue;
			}

			$operations[] = new SchemaOperation(
				'add-copy-field',
				$rule->to_payload(),
				sprintf(
					/* translators: 1: source field, 2: destination field, 3: why it exists. */
					__( 'Copy %1$s into %2$s — %3$s', 'solrengine' ),
					$rule->source(),
					$rule->destination(),
					$rule->purpose()
				),
				true
			);
		}

		return new SchemaPlan( $contract->version(), $operations, $blockers );
	}

	/**
	 * A fingerprint of the fields SolrEngine owns in a live schema.
	 *
	 * Stored alongside the applied contract version. If the fingerprint changes
	 * without the version changing, somebody edited the schema underneath us,
	 * which is worth telling an administrator about rather than discovering
	 * through failed indexing.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaState $state Live schema.
	 * @return string
	 */
	public function fingerprint( SchemaState $state ): string {
		return substr( md5( implode( '|', $state->owned_field_names() ) ), 0, 12 );
	}
}
