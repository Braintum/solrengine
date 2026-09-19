<?php
/**
 * Interprets what a caller selected.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Documents\TermPaths;
use WP_Term;

/**
 * Turns facet keys, selected values and a drill-down position into a query.
 *
 * One place for the conversions, because both the REST endpoint and the
 * front-end filter UI have to make them and a disagreement between the two
 * would show up as a filter that works through one route and silently matches
 * nothing through the other.
 *
 * The conversion worth naming is the taxonomy one. A hierarchical facet counts
 * and filters on materialised paths, so its bucket values look like "1/12/34".
 * A REST client, reasonably, wants to send "34" — a term identifier is what
 * every other part of WordPress calls a category. Both are accepted, and a
 * plain identifier is resolved to its path here, on the server, from the term
 * itself. The alternative, making clients construct paths, would publish an
 * internal encoding as API and break every stored URL the first time it
 * changed.
 *
 * @since 0.6.0
 */
final class FacetSelection {

	/**
	 * Registered facets.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Constructor.
	 *
	 * @param FacetRegistry $facets Registered facets.
	 */
	public function __construct( FacetRegistry $facets ) {
		$this->facets = $facets;
	}

	/**
	 * Normalise the values selected for one key.
	 *
	 * @since 0.6.0
	 *
	 * @param string            $key    Facet or filter key.
	 * @param array<int,scalar> $values Raw values.
	 * @return array<int,scalar>
	 */
	public function values_for( string $key, array $values ): array {
		$definition = $this->facets->get( $key );

		if ( null === $definition || FacetDefinition::HIERARCHY !== $definition->type() ) {
			return $values;
		}

		$out = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$path = $this->path_for( $definition, (string) $value );

			if ( '' !== $path ) {
				$out[] = $path;
			}
		}

		return $out;
	}

	/**
	 * The node a hierarchy facet should count the children of.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key Facet key.
	 * @param string $raw Term identifier or path, empty for the top level.
	 * @return string
	 */
	public function parent_for( string $key, string $raw ): string {
		$definition = $this->facets->get( $key );

		if ( null === $definition || FacetDefinition::HIERARCHY !== $definition->type() || '' === $raw ) {
			return '';
		}

		return $this->path_for( $definition, $raw );
	}

	/**
	 * Build a query that counts the given facets.
	 *
	 * Where a hierarchical facet has a selection and no explicit position, the
	 * deepest selected node becomes the position: a visitor who ticked "Guides"
	 * is looking at Guides, and showing them the top level again would make the
	 * panel appear to ignore what they just clicked.
	 *
	 * @since 0.6.0
	 *
	 * @param SearchQuery          $query   Query to extend.
	 * @param string[]             $keys    Facet keys to count.
	 * @param array<string,string> $parents Drill-down positions, keyed by facet key.
	 * @return SearchQuery
	 */
	public function apply( SearchQuery $query, array $keys, array $parents = array() ): SearchQuery {
		$filters = $query->filters();

		foreach ( $keys as $key ) {
			$key = (string) $key;

			if ( ! $this->facets->has( $key ) ) {
				continue;
			}

			$parent = $this->parent_for( $key, (string) ( $parents[ $key ] ?? '' ) );

			if ( '' === $parent ) {
				$parent = $this->deepest( (array) ( $filters[ $key ] ?? array() ) );
			}

			$query = $query->with_facet( $key, $parent );
		}

		return $query;
	}

	/**
	 * The deepest path among a selection.
	 *
	 * @param array<int,scalar> $values Selected values.
	 * @return string
	 */
	private function deepest( array $values ): string {
		$deepest = '';

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}

			$path = (string) $value;

			if ( ! TermPaths::is_path( $path ) ) {
				continue;
			}

			if ( substr_count( $path, '/' ) > substr_count( $deepest, '/' ) ) {
				$deepest = $path;
			}
		}

		return $deepest;
	}

	/**
	 * Resolve a term identifier or path to a path.
	 *
	 * A path is verified against WordPress rather than trusted: a caller can
	 * put any digits and slashes in a URL, and a path that names no real term
	 * would otherwise become a filter that matches nothing while looking to the
	 * visitor like a filter that works.
	 *
	 * @param FacetDefinition $definition The facet.
	 * @param string          $value      Term identifier or path.
	 * @return string
	 */
	private function path_for( FacetDefinition $definition, string $value ): string {
		$term_id = TermPaths::is_path( $value ) ? TermPaths::term_id( $value ) : (int) $value;

		if ( $term_id <= 0 ) {
			return '';
		}

		$term = get_term( $term_id, $definition->taxonomy() );

		if ( ! $term instanceof WP_Term ) {
			return '';
		}

		return TermPaths::path_for( $term, $definition->taxonomy() );
	}
}
