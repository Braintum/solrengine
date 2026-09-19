<?php
/**
 * What the live schema actually contains.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A snapshot of the index's current schema.
 *
 * Only what SolrEngine needs in order to diff. The full Solr schema carries far
 * more, and reproducing it here would make this type a mirror of somebody
 * else's data model rather than a description of our requirements.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class SchemaState {

	/**
	 * Live field definitions keyed by name.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $fields;

	/**
	 * Live dynamic field definitions keyed by pattern.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $dynamic_fields;

	/**
	 * Live copy rules as "source=>dest" keys.
	 *
	 * @var array<string,bool>
	 */
	private $copy_fields;

	/**
	 * Field type names the schema defines.
	 *
	 * @var array<string,bool>
	 */
	private $field_types;

	/**
	 * Whether the schema can be changed through the Schema API.
	 *
	 * @var bool
	 */
	private $mutable;

	/**
	 * The schema's declared unique key field.
	 *
	 * @var string
	 */
	private $unique_key;

	/**
	 * Constructor.
	 *
	 * @param array<string,array<string,mixed>> $fields         Fields by name.
	 * @param array<string,array<string,mixed>> $dynamic_fields Patterns by name.
	 * @param string[]                          $copy_fields    "source=>dest" keys.
	 * @param string[]                          $field_types    Field type names.
	 * @param bool                              $mutable        Schema API usable.
	 * @param string                            $unique_key     Unique key field.
	 */
	public function __construct(
		array $fields,
		array $dynamic_fields,
		array $copy_fields,
		array $field_types,
		bool $mutable,
		string $unique_key
	) {
		$this->fields         = $fields;
		$this->dynamic_fields = $dynamic_fields;
		$this->copy_fields    = array_fill_keys( $copy_fields, true );
		$this->field_types    = array_fill_keys( $field_types, true );
		$this->mutable        = $mutable;
		$this->unique_key     = $unique_key;
	}

	/**
	 * A live field definition, or null when absent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $name Field name.
	 * @return array<string,mixed>|null
	 */
	public function field( string $name ): ?array {
		return $this->fields[ $name ] ?? null;
	}

	/**
	 * A live dynamic field definition, or null when absent.
	 *
	 * @since 0.1.0
	 *
	 * @param string $pattern Dynamic pattern.
	 * @return array<string,mixed>|null
	 */
	public function dynamic_field( string $pattern ): ?array {
		return $this->dynamic_fields[ $pattern ] ?? null;
	}

	/**
	 * Whether a copy rule is already declared.
	 *
	 * @since 0.1.0
	 *
	 * @param CopyFieldRule $rule Rule to look for.
	 * @return bool
	 */
	public function has_copy_field( CopyFieldRule $rule ): bool {
		return isset( $this->copy_fields[ $rule->key() ] );
	}

	/**
	 * Whether the schema defines a field type.
	 *
	 * @since 0.1.0
	 *
	 * @param string $type Field type name.
	 * @return bool
	 */
	public function has_field_type( string $type ): bool {
		return isset( $this->field_types[ $type ] );
	}

	/**
	 * Whether the Schema API can modify this schema.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_mutable(): bool {
		return $this->mutable;
	}

	/**
	 * The schema's unique key field.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function unique_key(): string {
		return $this->unique_key;
	}

	/**
	 * Names of every field SolrEngine owns that already exists.
	 *
	 * Used to fingerprint the applied contract and to identify our own fields
	 * during a migration, without ever touching a field outside the namespace.
	 *
	 * @since 0.1.0
	 *
	 * @return string[]
	 */
	public function owned_field_names(): array {
		$owned = array();

		foreach ( array_keys( $this->fields ) as $name ) {
			if ( 0 === strpos( $name, 'se_' ) ) {
				$owned[] = $name;
			}
		}

		foreach ( array_keys( $this->dynamic_fields ) as $name ) {
			if ( 0 === strpos( $name, 'se_' ) ) {
				$owned[] = $name;
			}
		}

		sort( $owned );

		return $owned;
	}
}
