<?php
/**
 * One schema change.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A single change SolrEngine would make to the schema.
 *
 * Exists so that an administrator can be shown exactly what will happen before
 * anything happens. ADR-003 turns on this: the plugin has the capability to
 * mutate a customer's Solr, and consent is a separate thing from capability.
 *
 * Only additive commands are ever produced. SolrEngine does not replace or
 * delete fields, including its own: orphaned "se_" fields cost almost nothing,
 * and deleting a field that a copyField rule references fails in ways that are
 * tedious to unpick.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class SchemaOperation {

	/**
	 * Schema API command name.
	 *
	 * @var string
	 */
	private $command;

	/**
	 * Command payload.
	 *
	 * @var array<string,mixed>
	 */
	private $payload;

	/**
	 * One-line explanation for an administrator.
	 *
	 * @var string
	 */
	private $description;

	/**
	 * Whether applying this requires content to be indexed again.
	 *
	 * @var bool
	 */
	private $requires_reindex;

	/**
	 * Commands SolrEngine is permitted to issue.
	 *
	 * @var string[]
	 */
	private const ALLOWED = array( 'add-field', 'add-dynamic-field', 'add-copy-field' );

	/**
	 * Constructor.
	 *
	 * @param string              $command          Schema API command.
	 * @param array<string,mixed> $payload          Command payload.
	 * @param string              $description      Human explanation.
	 * @param bool                $requires_reindex Whether a reindex follows.
	 */
	public function __construct( string $command, array $payload, string $description, bool $requires_reindex = false ) {
		$this->command          = $command;
		$this->payload          = $payload;
		$this->description      = $description;
		$this->requires_reindex = $requires_reindex;
	}

	/**
	 * Schema API command name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function command(): string {
		return $this->command;
	}

	/**
	 * Command payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function payload(): array {
		return $this->payload;
	}

	/**
	 * One-line explanation.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function description(): string {
		return $this->description;
	}

	/**
	 * Whether applying this requires a reindex.
	 *
	 * Solr never rewrites indexed documents when the schema changes: "Modifying
	 * your schema will never modify any documents that are already indexed." A
	 * new copyField target, for instance, stays empty until each document is
	 * written again.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function requires_reindex(): bool {
		return $this->requires_reindex;
	}

	/**
	 * Whether this command is one SolrEngine is allowed to issue.
	 *
	 * Enforced at apply time as well as here, so that a hook or a future code
	 * path cannot smuggle a destructive command into a plan.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_additive(): bool {
		return in_array( $this->command, self::ALLOWED, true );
	}
}
