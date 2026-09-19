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

	/**
	 * Read the live schema.
	 *
	 * @since 0.1.0
	 *
	 * @return Result Success carrying a SchemaState, or a failure.
	 */
	public function describe(): Result;

	/**
	 * Work out what would have to change to satisfy a contract.
	 *
	 * Produces a plan without changing anything. Separate from apply() on
	 * purpose: a single method that decided for itself what to change would
	 * leave nothing to show an administrator, and consent would be impossible
	 * to obtain.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaContract $contract Contract to satisfy.
	 * @return Result Success carrying a SchemaPlan, or a failure.
	 */
	public function plan( SchemaContract $contract ): Result;

	/**
	 * Apply a plan.
	 *
	 * Implementations must reject any plan containing a command outside the
	 * additive set, must stop at the first failure rather than continuing, and
	 * must record the contract version they reached.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaPlan $plan Plan produced by plan().
	 * @return Result Success carrying the number of operations applied, or a
	 *                failure naming the operation that stopped it.
	 */
	public function apply( SchemaPlan $plan ): Result;

	/**
	 * The schema as configuration an administrator can apply by hand.
	 *
	 * The fallback path for a Solr that SolrEngine cannot or should not modify:
	 * a managed service, a locked-down cluster, or credentials without
	 * authorisation. Without this, those customers have no route at all.
	 *
	 * @since 0.1.0
	 *
	 * @param SchemaContract $contract Contract to render.
	 * @return string XML fragment suitable for a managed-schema file.
	 */
	public function export( SchemaContract $contract ): string;
}
