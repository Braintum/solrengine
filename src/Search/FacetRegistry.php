<?php
/**
 * Which facets a caller may ask for.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\DocumentFields;

/**
 * The server-side list of facets, and the boundary that keeps the search
 * endpoint from being a Solr proxy.
 *
 * A caller sends `facets=category,post_type`. It never sends facet JSON, and
 * there is nowhere in this design to put any: a definition names its own field,
 * its own limits and its own value type, all decided here. That constraint is
 * the reason the endpoint can be public, and it had to exist before the first
 * facet shipped — an allowlist retrofitted onto an API that already accepted
 * raw facet JSON breaks every client that adopted the loose form (ADR-008).
 *
 * Kept deliberately parallel to FilterRegistry rather than merged with it. The
 * two answer different questions — "what can be counted" and "what can be
 * narrowed" — and a facet key that also filters is a pairing, established by
 * sharing a key, which is what lets multi-select exclude a facet's own filter
 * from its own counts.
 *
 * @since 0.6.0
 */
final class FacetRegistry {

	/**
	 * Registered definitions keyed by public key.
	 *
	 * @var array<string,FacetDefinition>
	 */
	private $facets = array();

	/**
	 * Build the default registry.
	 *
	 * Taxonomies arrive as a map of name to label and hierarchy, rather than a
	 * list of names, because a hierarchical taxonomy gets a different facet
	 * from a flat one: a tree is walked one level at a time, and rendering a
	 * thousand categories flat is not a filter anybody uses.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,array{label?:string,hierarchical?:bool}> $taxonomies Public taxonomies.
	 * @param array<string,string>                                  $languages  Languages the site publishes in.
	 * @return self
	 */
	public static function defaults( array $taxonomies = array(), array $languages = array() ): self {
		$registry = new self();

		$registry->register(
			FacetDefinition::terms(
				'post_type',
				DocumentFields::POST_TYPE,
				__( 'Content type', 'solrengine' ),
				FieldType::STRING
			)
		);

		if ( count( $languages ) > 1 ) {
			// Only on a site that has more than one. A facet offering a single
			// choice that every result already matches is a row of furniture.
			$registry->register(
				FacetDefinition::terms(
					'language',
					DocumentFields::LANG,
					__( 'Language', 'solrengine' ),
					FieldType::STRING
				)
			);
		}

		foreach ( $taxonomies as $name => $taxonomy ) {
			$name  = (string) $name;
			$label = (string) ( $taxonomy['label'] ?? $name );

			if ( ! empty( $taxonomy['hierarchical'] ) ) {
				$registry->register(
					FacetDefinition::hierarchy(
						$name,
						$name,
						DocumentFields::taxonomy_paths( $name ),
						$label
					)
				);

				continue;
			}

			$registry->register(
				FacetDefinition::terms(
					$name,
					DocumentFields::taxonomy_ids( $name ),
					$label,
					FieldType::INT
				)->with_taxonomy( $name )
			);
		}

		/**
		 * Filters the registered facets.
		 *
		 * The place to expose an indexed meta field as a facet, to change a
		 * limit, or to remove one. Anything not registered here cannot be
		 * counted through the API, by design: that is the constraint which
		 * keeps the public endpoint from becoming a Solr proxy.
		 *
		 * @since 0.6.0
		 *
		 * @param FacetRegistry $registry The registry.
		 */
		$filtered = apply_filters( 'solrengine_facet_definitions', $registry );

		return $filtered instanceof self ? $filtered : $registry;
	}

	/**
	 * Register a facet.
	 *
	 * A definition that could not produce a sane Solr request is dropped here,
	 * at registration, rather than at query time: a mistake in a filter then
	 * shows up as a missing facet on the next page load instead of as an empty
	 * response nobody can account for.
	 *
	 * @since 0.6.0
	 *
	 * @param FacetDefinition $definition The facet.
	 * @return void
	 */
	public function register( FacetDefinition $definition ): void {
		if ( ! $definition->is_usable() ) {
			return;
		}

		$this->facets[ $definition->key() ] = $definition;
	}

	/**
	 * Stop offering a facet.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return void
	 */
	public function remove( string $key ): void {
		unset( $this->facets[ $key ] );
	}

	/**
	 * Whether a key is registered.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return isset( $this->facets[ $key ] );
	}

	/**
	 * One definition.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return FacetDefinition|null
	 */
	public function get( string $key ): ?FacetDefinition {
		return $this->facets[ $key ] ?? null;
	}

	/**
	 * Every definition, keyed by key.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string,FacetDefinition>
	 */
	public function all(): array {
		return $this->facets;
	}

	/**
	 * Every registered key.
	 *
	 * @since 0.6.0
	 *
	 * @return string[]
	 */
	public function keys(): array {
		return array_keys( $this->facets );
	}

	/**
	 * Every registered key with its label.
	 *
	 * @since 0.6.0
	 *
	 * @return array<string,string>
	 */
	public function available(): array {
		$out = array();

		foreach ( $this->facets as $key => $facet ) {
			$out[ $key ] = $facet->label();
		}

		return $out;
	}

	/**
	 * Whether anything is registered.
	 *
	 * @since 0.6.0
	 *
	 * @return bool
	 */
	public function is_empty(): bool {
		return array() === $this->facets;
	}

	/**
	 * The tag naming a facet's own filter.
	 *
	 * Multi-select faceting works by tagging the filter a facet owns and
	 * excluding that tag from the facet's own domain, so selecting "Lenovo"
	 * leaves the other brand counts visible. Both halves derive the tag from
	 * the key through this one function, because a tag that matched on one
	 * side and not the other would produce counts that look plausible and are
	 * wrong.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @return string
	 */
	public static function tag_for( string $key ): string {
		return 'se_' . DocumentFields::sanitise( $key );
	}
}
