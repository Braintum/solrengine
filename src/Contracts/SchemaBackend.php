<?php
/**
 * Schema backend contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Inspects and, where permitted, changes the index schema.
 *
 * Separate from the read and write contracts because it is the one that may not
 * exist. A managed backend owns its own schema and reports it as not
 * manageable from here, and a locked-down Solr reports the same for a different
 * reason. The admin UI adapts to the answer rather than to the backend's name,
 * which is what keeps the Cloud adapter from needing UI changes (ADR-003,
 * ADR-007).
 *
 * Implementations must never modify a field outside the "se_" namespace, never
 * delete or replace a field they did not create, and never apply a change
 * without an explicit caller decision. Capability is not consent.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
interface SchemaBackend {

	/**
	 * Whether schema changes can be applied through this backend at all.
	 *
	 * False for a managed backend, and false for a Solr whose schema is not
	 * mutable or whose credentials lack authorisation. In both cases callers
	 * fall back to exporting the required configuration for a human to apply.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_manageable(): bool;

	/**
	 * The schema contract version the backend is currently satisfying.
	 *
	 * Zero when nothing has been applied or when it cannot be determined.
	 *
	 * @since 0.1.0
	 *
	 * @return Result Success carrying an int, or a failure.
	 */
	public function applied_version(): Result;

	/*
	 * Arriving in Phase 1, once the contract representation is settled:
	 *
	 *   describe(): Result                          // value: SchemaState
	 *   plan( SchemaContract $contract ): Result    // value: SchemaPlan
	 *   apply( SchemaPlan $plan ): Result
	 *
	 * plan() and apply() are separate on purpose. A single apply() that decided
	 * for itself what to change would make the administrator's confirmation
	 * impossible to obtain, since there would be nothing to show them.
	 */
}
