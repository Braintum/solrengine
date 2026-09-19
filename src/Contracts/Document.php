<?php
/**
 * A document ready to be written to the index.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * One indexable object, mapped onto index fields.
 *
 * Built from a DocumentRef by the document builder, and consumed by a backend.
 * Deliberately a flat map of field name to value: the backend's job is to ship
 * it, not to interpret it, which is what keeps the Cloud adapter from needing
 * to understand WordPress.
 *
 * Values are coerced when they are added rather than when they are written, so
 * a bad value fails in the builder where there is context to report it, not
 * deep inside a batch of a hundred documents.
 *
 * Immutable once built.
 *
 * @since 0.3.0 Unstable until 1.0.
 */
final class Document {

	/**
	 * What this document represents.
	 *
	 * @var DocumentRef
	 */
	private $ref;

	/**
	 * Field values keyed by field name.
	 *
	 * @var array<string,mixed>
	 */
	private $fields;

	/**
	 * Constructor.
	 *
	 * @param DocumentRef         $ref    What this document represents.
	 * @param array<string,mixed> $fields Field values keyed by field name.
	 */
	public function __construct( DocumentRef $ref, array $fields ) {
		$this->ref    = $ref;
		$this->fields = $fields;
	}

	/**
	 * What this document represents.
	 *
	 * @since 0.3.0
	 *
	 * @return DocumentRef
	 */
	public function ref(): DocumentRef {
		return $this->ref;
	}

	/**
	 * The document identifier.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->ref->document_id();
	}

	/**
	 * Field values keyed by field name.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,mixed>
	 */
	public function fields(): array {
		return $this->fields;
	}

	/**
	 * One field's value.
	 *
	 * @since 0.3.0
	 *
	 * @param string $field    Field name.
	 * @param mixed  $fallback Returned when the field is absent.
	 * @return mixed
	 */
	public function get( string $field, $fallback = null ) {
		return array_key_exists( $field, $this->fields ) ? $this->fields[ $field ] : $fallback;
	}

	/**
	 * The document as a backend payload.
	 *
	 * The identifier is added here rather than stored as a field, so it cannot
	 * be overwritten by a field map or by the solrengine_document filter.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,mixed>
	 */
	public function to_payload(): array {
		return array_merge( $this->fields, array( 'id' => $this->id() ) );
	}
}
