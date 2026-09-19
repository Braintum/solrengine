<?php
/**
 * Turns requested facet keys into a JSON Facet request.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\SearchQuery;

/**
 * Builds the `json.facet` structure.
 *
 * The JSON Facet API exclusively, per ADR-008: it nests, it computes
 * statistics, it manipulates a facet's domain, and its response shape maps
 * onto the REST payload without reassembly. The classic `facet.*` parameters
 * offer nothing it lacks and would have to be translated twice.
 *
 * Two things happen here and nowhere else:
 *
 * 1. **Multi-select.** A facet's own filter is tagged by QueryTranslator, and
 *    that tag is excluded from this facet's domain. Without it, ticking
 *    "Lenovo" would drop every other brand's count to zero and a visitor could
 *    never widen their own selection — the filter panel would be a one-way
 *    door.
 * 2. **Hierarchy.** One level of a taxonomy tree comes back per request, by
 *    faceting the materialised path field with a prefix. A tree of ten
 *    thousand categories costs the same as a tree of ten, because only the
 *    level being looked at is ever counted.
 *
 * Pure: it produces a structure, sends nothing, and knows nothing about
 * WordPress.
 *
 * @since 0.6.0
 */
final class FacetTranslator {

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
	 * Build the facet structure for a query.
	 *
	 * @since 0.6.0
	 *
	 * @param SearchQuery $query Query being translated.
	 * @return array<string,mixed> Empty when nothing usable was asked for.
	 */
	public function translate( SearchQuery $query ): array {
		$out = array();

		foreach ( $query->facets() as $key => $parent ) {
			$definition = $this->facets->get( (string) $key );

			if ( null === $definition ) {
				// Unregistered keys are refused by the REST layer, where there
				// is a request to fail. By the time a query reaches here the
				// only way to hold one is a filter that removed a facet after
				// the query was built, and dropping it quietly is right.
				continue;
			}

			$block = $this->block_for( $definition, (string) $parent );

			if ( array() !== $block ) {
				$out[ $definition->key() ] = $block;
			}
		}

		return $out;
	}

	/**
	 * The JSON Facet block for one definition.
	 *
	 * @param FacetDefinition $definition  The facet.
	 * @param string          $parent_path Node to count the children of.
	 * @return array<string,mixed>
	 */
	private function block_for( FacetDefinition $definition, string $parent_path ): array {
		switch ( $definition->type() ) {
			case FacetDefinition::RANGE:
				$block = array(
					'type'  => 'range',
					'field' => $definition->field(),
					'start' => $definition->start(),
					'end'   => $definition->end(),
					'gap'   => $definition->gap(),
					// Values outside the declared range are not counted into a
					// "before" or "after" bucket. A band a visitor cannot
					// select is a count they cannot act on.
					'other' => 'none',
				);

				break;

			case FacetDefinition::HIERARCHY:
				$block = array(
					'type'     => 'terms',
					'field'    => $definition->field(),
					'limit'    => $definition->limit(),
					'mincount' => max( 1, $definition->min_count() ),
					'sort'     => $definition->sort(),
					// The prefix is the whole hierarchy mechanism: it selects
					// exactly one level of the tree.
					'prefix'   => self::child_prefix( $parent_path ),
				);

				break;

			default:
				$block = array(
					'type'     => 'terms',
					'field'    => $definition->field(),
					'limit'    => $definition->limit(),
					'mincount' => max( 1, $definition->min_count() ),
					'sort'     => $definition->sort(),
				);
		}

		if ( $definition->is_multi_select() ) {
			$block['domain'] = array( 'excludeTags' => array( FacetRegistry::tag_for( $definition->key() ) ) );
		}

		if ( $definition->is_refined() ) {
			// Exact counts across shards, at the cost of a second round of
			// requests. Per facet, because it is worth paying for a price band
			// and not for a content-type list.
			$block['refine'] = true;
		}

		return $block;
	}

	/**
	 * The path prefix matching the children of a node.
	 *
	 * Paths are stored depth-first — "0/12", "1/12/34", "2/12/34/56" — and the
	 * leading depth is what makes a single level selectable at all. Without it
	 * the top level would be "every path with no slash in it", which a prefix
	 * cannot express, and the alternative is counting the whole tree and
	 * discarding most of it in PHP. See the Phase 5 addendum to ADR-002.
	 *
	 * @since 0.6.0
	 *
	 * @param string $parent_path Parent path, or an empty string for the top level.
	 * @return string
	 */
	public static function child_prefix( string $parent_path ): string {
		if ( '' === $parent_path ) {
			return '0/';
		}

		$segments = explode( '/', $parent_path );
		$depth    = (int) array_shift( $segments );

		if ( array() === $segments ) {
			return '0/';
		}

		return ( $depth + 1 ) . '/' . implode( '/', $segments ) . '/';
	}
}
