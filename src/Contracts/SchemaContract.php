<?php
/**
 * The schema SolrEngine requires.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What the index must provide for a given contract version.
 *
 * Versioned independently of the plugin, because most releases change nothing
 * about the schema and coupling the two would force meaningless migrations.
 * See ADR-003.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class SchemaContract {

	/**
	 * Contract version.
	 *
	 * @var int
	 */
	private $version;

	/**
	 * Required fields and dynamic patterns.
	 *
	 * @var SchemaField[]
	 */
	private $fields;

	/**
	 * Required copyField rules.
	 *
	 * @var CopyFieldRule[]
	 */
	private $copy_fields;

	/**
	 * Constructor.
	 *
	 * @param int             $version     Contract version.
	 * @param SchemaField[]   $fields      Required fields.
	 * @param CopyFieldRule[] $copy_fields Required copy rules.
	 */
	public function __construct( int $version, array $fields, array $copy_fields = array() ) {
		$this->version     = $version;
		$this->fields      = $fields;
		$this->copy_fields = $copy_fields;
	}

	/**
	 * Contract version.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->version;
	}

	/**
	 * Every required field and pattern.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaField[]
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * Concrete fields only.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaField[]
	 */
	public function concrete_fields(): array {
		return array_values(
			array_filter(
				$this->fields,
				static function ( SchemaField $field ): bool {
					return ! $field->is_dynamic();
				}
			)
		);
	}

	/**
	 * Dynamic patterns only.
	 *
	 * @since 0.1.0
	 *
	 * @return SchemaField[]
	 */
	public function dynamic_fields(): array {
		return array_values(
			array_filter(
				$this->fields,
				static function ( SchemaField $field ): bool {
					return $field->is_dynamic();
				}
			)
		);
	}

	/**
	 * Required copyField rules.
	 *
	 * @since 0.1.0
	 *
	 * @return CopyFieldRule[]
	 */
	public function copy_fields(): array {
		return $this->copy_fields;
	}

	/**
	 * Names of anything in the contract that Solr would reject.
	 *
	 * Guards against shipping a contract that cannot be applied. Every name is
	 * checked against Solr's wildcard placement rules, so a mistake is caught by
	 * a unit test here rather than by an add-field call failing on a customer's
	 * server.
	 *
	 * @since 0.1.0
	 *
	 * @return string[] Empty when the contract is well formed.
	 */
	public function validate(): array {
		$problems = array();

		foreach ( $this->fields as $field ) {
			if ( ! $field->has_valid_name() ) {
				$problems[] = sprintf( 'Field name "%s" is not valid in Solr.', $field->name() );
			}
		}

		foreach ( $this->copy_fields as $rule ) {
			if ( ! $rule->is_valid() ) {
				$problems[] = sprintf( 'Copy rule "%s" is not valid in Solr.', $rule->key() );
			}
		}

		// Chains are prohibited: "no copy feeds into another copy".
		$destinations = array();

		foreach ( $this->copy_fields as $rule ) {
			$destinations[ $rule->destination() ] = true;
		}

		foreach ( $this->copy_fields as $rule ) {
			if ( isset( $destinations[ $rule->source() ] ) ) {
				$problems[] = sprintf(
					'Copy rule "%s" chains from a field that is itself a copy destination, which Solr does not support.',
					$rule->key()
				);
			}
		}

		return $problems;
	}
}
