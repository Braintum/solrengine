<?php
/**
 * The difference between what is required and what exists.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What would change, if an administrator says yes.
 *
 * A plan is produced, shown, and only then applied. That separation is the
 * whole point: an apply() that decided for itself what to change would leave
 * nothing to show, and consent would be impossible to obtain.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class SchemaPlan {

	/**
	 * Contract version this plan would bring the schema to.
	 *
	 * @var int
	 */
	private $target_version;

	/**
	 * Changes to apply, in order.
	 *
	 * @var SchemaOperation[]
	 */
	private $operations;

	/**
	 * Problems that block the plan entirely.
	 *
	 * @var string[]
	 */
	private $blockers;

	/**
	 * Constructor.
	 *
	 * @param int               $target_version Contract version targeted.
	 * @param SchemaOperation[] $operations     Changes to apply.
	 * @param string[]          $blockers       Reasons the plan cannot run.
	 */
	public function __construct( int $target_version, array $operations = array(), array $blockers = array() ) {
		$this->target_version = $target_version;
		$this->operations     = $operations;
		$this->blockers       = $blockers;
	}

	/**
	 * Contract version this plan targets.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function target_version(): int {
		return $this->target_version;
	}

	/**
	 * Changes to apply.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaOperation[]
	 */
	public function operations(): array {
		return $this->operations;
	}

	/**
	 * Whether the schema already satisfies the contract.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->operations;
	}

	/**
	 * Reasons the plan cannot be applied.
	 *
	 * A schema that is not mutable, or a missing field type SolrEngine will not
	 * create for itself, are blockers rather than failures: the administrator
	 * can still apply the shipped configset by hand.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function blockers(): array {
		return $this->blockers;
	}

	/**
	 * Whether the plan can be applied at all.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_applicable(): bool {
		return array() === $this->blockers && ! $this->is_empty();
	}

	/**
	 * Whether applying this plan should be followed by a reindex.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function requires_reindex(): bool {
		foreach ( $this->operations as $operation ) {
			if ( $operation->requires_reindex() ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether every operation is one SolrEngine is permitted to issue.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_additive(): bool {
		foreach ( $this->operations as $operation ) {
			if ( ! $operation->is_additive() ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * The plan as lines an administrator can read.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function describe(): array {
		return array_map(
			static function ( SchemaOperation $operation ): string {
				return $operation->description();
			},
			$this->operations
		);
	}
}
