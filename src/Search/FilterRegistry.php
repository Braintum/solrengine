<?php
/**
 * Which filters a caller may apply.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\DocumentFields;

/**
 * Maps public filter keys onto index fields.
 *
 * The single constraint that keeps the public search endpoint from being an
 * unrestricted Solr proxy. A caller sends `filters[category][]=12`; it can
 * never send a filter query. An unregistered key is refused rather than
 * ignored, because silently dropping it hides client bugs and makes probing
 * cheap.
 *
 * Registering is therefore not a formality. It is the security boundary, and
 * it has to be designed in from the start: retrofitting an allowlist onto an
 * API that already accepts raw filters means breaking every client that
 * adopted the loose form.
 *
 * @since 0.4.0
 */
final class FilterRegistry {

	/**
	 * Registered filters keyed by public key.
	 *
	 * @var array<string,array{field:string,type:string,label:string}>
	 */
	private $filters = array();

	/**
	 * Build the default registry for a set of taxonomies.
	 *
	 * @since 0.4.0
	 *
	 * @param string[] $taxonomies Public taxonomy names.
	 * @return self
	 */
	public static function defaults( array $taxonomies = array() ): self {
		$registry = new self();

		$registry->register( 'post_type', DocumentFields::POST_TYPE, FieldType::STRING, __( 'Content type', 'solrengine' ) );
		$registry->register( 'author', DocumentFields::AUTHOR_ID, FieldType::INT, __( 'Author', 'solrengine' ) );

		foreach ( $taxonomies as $taxonomy ) {
			$registry->register(
				(string) $taxonomy,
				DocumentFields::taxonomy_ids( (string) $taxonomy ),
				FieldType::INT,
				(string) $taxonomy
			);
		}

		/**
		 * Filters the registered search filters.
		 *
		 * The place to expose an indexed meta field as a filter. Anything not
		 * registered here cannot be filtered on through the API, by design.
		 *
		 * @since 0.4.0
		 *
		 * @param FilterRegistry $registry The registry.
		 */
		do_action( 'solrengine_register_filters', $registry );

		return $registry;
	}

	/**
	 * Register a filter.
	 *
	 * @since 0.4.0
	 *
	 * @param string $key   Public key a caller uses.
	 * @param string $field Index field it maps to.
	 * @param string $type  Value type, from FieldType.
	 * @param string $label Human label for a UI.
	 * @return void
	 */
	public function register( string $key, string $field, string $type, string $label = '' ): void {
		if ( '' === $key || '' === $field || ! FieldType::is_valid( $type ) ) {
			return;
		}

		// Only fields SolrEngine owns. A registry entry pointing at an
		// arbitrary field would let a caller probe another application's data
		// in a shared collection.
		if ( 0 !== strpos( $field, 'se_' ) ) {
			return;
		}

		$this->filters[ $key ] = array(
			'field' => $field,
			'type'  => $type,
			'label' => '' !== $label ? $label : $key,
		);
	}

	/**
	 * Whether a key is registered.
	 *
	 * @since 0.4.0
	 *
	 * @param string $key Filter key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->filters[ $key ] );
	}

	/**
	 * Every registered key with its label.
	 *
	 * @since 0.4.0
	 *
	 * @return array<string,string>
	 */
	public function available(): array {
		$out = array();

		foreach ( $this->filters as $key => $filter ) {
			$out[ $key ] = $filter['label'];
		}

		return $out;
	}

	/**
	 * Build a filter query clause for a key and its values.
	 *
	 * Returns an empty string for an unregistered key or unusable values, and
	 * the caller treats that as "apply nothing". Rejection of unknown keys
	 * happens earlier, where there is a request to fail.
	 *
	 * @since 0.4.0
	 *
	 * @param string            $key    Filter key.
	 * @param array<int,scalar> $values Values to match.
	 * @return string
	 */
	public function clause_for( string $key, array $values ): string {
		if ( ! isset( $this->filters[ $key ] ) || array() === $values ) {
			return '';
		}

		$filter   = $this->filters[ $key ];
		$rendered = array();

		foreach ( $values as $value ) {
			if ( is_array( $value ) || is_object( $value ) ) {
				continue;
			}

			$coerced = DocumentFields::coerce( $filter['type'], $value );

			if ( null === $coerced ) {
				continue;
			}

			// Escaped even though the value passed coercion: a string value
			// may legitimately contain a colon or a quote, and either would
			// change what the clause matches.
			$rendered[] = is_bool( $coerced )
				? ( $coerced ? 'true' : 'false' )
				: QueryTranslator::escape( (string) $coerced );
		}

		if ( array() === $rendered ) {
			return '';
		}

		// Multiple values for one key are an OR: picking two categories means
		// "in either", which is what a shopper ticking two boxes expects.
		return $filter['field'] . ':(' . implode( ' OR ', $rendered ) . ')';
	}
}
