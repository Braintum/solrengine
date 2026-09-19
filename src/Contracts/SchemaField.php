<?php
/**
 * One field or dynamic field definition.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * A single field the index must provide.
 *
 * Covers both concrete fields ("se_all") and dynamic patterns ("se_txt_*").
 *
 * Dynamic patterns carry a trailing wildcard and nothing else. Solr's copyField
 * documentation permits "either leading or trailing asterisks" only, and the
 * patterns here are copyField sources, so a mid-name wildcard is not available.
 * See the Phase 1 addendum to ADR-002.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class SchemaField {

	/**
	 * Field name, or a dynamic pattern ending in "*".
	 *
	 * @var string
	 */
	private $name;

	/**
	 * Solr field type name.
	 *
	 * @var string
	 */
	private $type;

	/**
	 * Field attributes as Solr names them.
	 *
	 * @var array<string,bool>
	 */
	private $attributes;

	/**
	 * Why this field exists, shown to an administrator reviewing a plan.
	 *
	 * @var string
	 */
	private $purpose;

	/**
	 * Constructor.
	 *
	 * @param string             $name       Field name or dynamic pattern.
	 * @param string             $type       Solr field type.
	 * @param array<string,bool> $attributes Overrides for the defaults below.
	 * @param string             $purpose    Human explanation.
	 */
	public function __construct( string $name, string $type, array $attributes = array(), string $purpose = '' ) {
		$this->name = $name;
		$this->type = $type;

		$this->attributes = array_merge(
			array(
				'indexed'     => true,
				'stored'      => true,
				'multiValued' => false,
				'docValues'   => false,
				'required'    => false,
			),
			array_map( 'boolval', $attributes )
		);

		$this->purpose = $purpose;
	}

	/**
	 * Field name or dynamic pattern.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function name(): string {
		return $this->name;
	}

	/**
	 * Solr field type name.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function type(): string {
		return $this->type;
	}

	/**
	 * Whether this is a dynamic pattern rather than a concrete field.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_dynamic(): bool {
		return false !== strpos( $this->name, '*' );
	}

	/**
	 * Whether the name is one Solr will accept.
	 *
	 * Concrete names must be alphanumeric or underscore. Dynamic patterns must
	 * carry exactly one asterisk, at the start or the end.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function has_valid_name(): bool {
		if ( ! $this->is_dynamic() ) {
			return 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $this->name );
		}

		if ( 1 !== substr_count( $this->name, '*' ) ) {
			return false;
		}

		$leading  = 0 === strpos( $this->name, '*' );
		$trailing = '*' === substr( $this->name, -1 );

		if ( ! $leading && ! $trailing ) {
			return false;
		}

		return 1 === preg_match( '/^\*?[A-Za-z0-9_]*\*?$/', $this->name );
	}

	/**
	 * Whether an attribute is set.
	 *
	 * @since 0.1.0
	 *
	 * @param string $attribute Attribute name as Solr spells it.
	 * @return bool
	 */
	public function is( string $attribute ): bool {
		return isset( $this->attributes[ $attribute ] ) && $this->attributes[ $attribute ];
	}

	/**
	 * Why this field exists.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function purpose(): string {
		return $this->purpose;
	}

	/**
	 * The field as a Schema API payload.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_payload(): array {
		return array_merge(
			array(
				'name' => $this->name,
				'type' => $this->type,
			),
			$this->attributes
		);
	}

	/**
	 * Whether a live field definition satisfies this requirement.
	 *
	 * Compares only what SolrEngine depends on. A field that is stored when we
	 * asked for unstored, or that carries extra attributes we never mentioned,
	 * still satisfies the contract: the customer may have widened it
	 * deliberately, and narrowing it back would be exactly the kind of
	 * uninvited change to their infrastructure that ADR-003 forbids.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,mixed> $live Field definition as Solr reports it.
	 * @return bool
	 */
	public function is_satisfied_by( array $live ): bool {
		if ( ! isset( $live['type'] ) || $live['type'] !== $this->type ) {
			return false;
		}

		foreach ( array( 'indexed', 'stored', 'multiValued', 'docValues' ) as $attribute ) {
			if ( ! $this->is( $attribute ) ) {
				continue;
			}

			if ( empty( $live[ $attribute ] ) ) {
				return false;
			}
		}

		return true;
	}
}
