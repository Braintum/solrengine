<?php
/**
 * Turns indexed facet values into words a visitor reads.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\FacetBucket;
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FacetResult;
use SolrEngine\Documents\TermPaths;
use WP_Term;

/**
 * Labels facet buckets from WordPress.
 *
 * Deliberately separate from the parser, and applied at render time rather than
 * at parse time. Parsed results are cached, and a label baked into a cached
 * object outlives the rename that made it wrong — a category renamed this
 * morning would keep showing its old name under search for as long as the cache
 * held. The identifier in the index is stable; the name beside it is not, so
 * the two are resolved at different moments on purpose.
 *
 * It also keeps the parser free of WordPress, which is what lets response
 * shapes be exercised in a unit test without a database.
 *
 * One lookup per facet, not one per bucket: a twenty-bucket category facet is
 * one `get_terms()` call, primed into the term cache, rather than twenty.
 *
 * @since 0.6.0
 */
final class FacetLabeller {

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
	 * Label a set of facet results.
	 *
	 * @since 0.6.0
	 *
	 * @param array<string,FacetResult> $results Parsed facet results.
	 * @return array<string,FacetResult>
	 */
	public function label( array $results ): array {
		$out = array();

		foreach ( $results as $key => $result ) {
			$definition = $this->facets->get( (string) $key );

			$out[ $key ] = null === $definition
				? $result
				: $result->with_buckets( $this->label_buckets( $result->buckets(), $definition ) );
		}

		return $out;
	}

	/**
	 * Label one facet's buckets.
	 *
	 * @param FacetBucket[]   $buckets    Buckets to label.
	 * @param FacetDefinition $definition The facet.
	 * @return FacetBucket[]
	 */
	private function label_buckets( array $buckets, FacetDefinition $definition ): array {
		if ( array() === $buckets ) {
			return $buckets;
		}

		if ( FacetDefinition::RANGE === $definition->type() ) {
			return $this->label_bands( $buckets, $definition );
		}

		if ( '' !== $definition->taxonomy() ) {
			return $this->label_terms( $buckets, $definition );
		}

		if ( 'se_s_post_type' === $definition->field() ) {
			return $this->label_post_types( $buckets );
		}

		return $buckets;
	}

	/**
	 * Label taxonomy buckets, whether keyed by identifier or by path.
	 *
	 * @param FacetBucket[]   $buckets    Buckets to label.
	 * @param FacetDefinition $definition The facet.
	 * @return FacetBucket[]
	 */
	private function label_terms( array $buckets, FacetDefinition $definition ): array {
		$taxonomy  = $definition->taxonomy();
		$hierarchy = FacetDefinition::HIERARCHY === $definition->type();
		$ids       = array();
		$by_bucket = array();
		$has_kids  = array();
		$is_nested = is_taxonomy_hierarchical( $taxonomy );

		foreach ( $buckets as $index => $bucket ) {
			$term_id = $hierarchy ? TermPaths::term_id( $bucket->value() ) : (int) $bucket->value();

			if ( $term_id > 0 ) {
				$ids[]               = $term_id;
				$by_bucket[ $index ] = $term_id;
			}
		}

		if ( array() === $ids ) {
			return $buckets;
		}

		$names = array();

		// One query, priming the term cache for everything about to be asked
		// for. Twenty get_term() calls would be twenty round trips on a page
		// that is already doing a search.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'include'    => $ids,
				'hide_empty' => false,
			)
		);

		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				if ( $term instanceof WP_Term ) {
					$names[ (int) $term->term_id ] = $term->name;
				}
			}
		}

		$out = array();

		foreach ( $buckets as $index => $bucket ) {
			$term_id = $by_bucket[ $index ] ?? 0;
			$name    = $names[ $term_id ] ?? '';

			if ( '' === $name ) {
				// A bucket whose term no longer exists. It is dropped rather
				// than shown as a number: the term was deleted and the index
				// has not caught up, and offering a filter that leads nowhere
				// is worse than offering one fewer.
				continue;
			}

			$labelled = $bucket->with_label( $name );

			if ( $hierarchy && $is_nested ) {
				if ( ! isset( $has_kids[ $term_id ] ) ) {
					$children             = get_term_children( $term_id, $taxonomy );
					$has_kids[ $term_id ] = is_array( $children ) && array() !== $children;
				}

				$labelled = $labelled->with_children( $has_kids[ $term_id ] );
			}

			$out[] = $labelled;
		}

		return $out;
	}

	/**
	 * Label post type buckets.
	 *
	 * @param FacetBucket[] $buckets Buckets to label.
	 * @return FacetBucket[]
	 */
	private function label_post_types( array $buckets ): array {
		$out = array();

		foreach ( $buckets as $bucket ) {
			$type = get_post_type_object( $bucket->value() );

			$out[] = null === $type
				? $bucket
				: $bucket->with_label( (string) ( $type->labels->name ?? $type->label ) );
		}

		return $out;
	}

	/**
	 * Label numeric bands.
	 *
	 * @param FacetBucket[]   $buckets    Buckets to label.
	 * @param FacetDefinition $definition The facet.
	 * @return FacetBucket[]
	 */
	private function label_bands( array $buckets, FacetDefinition $definition ): array {
		$out = array();

		foreach ( $buckets as $bucket ) {
			$start = (float) $bucket->value();

			$out[] = $bucket->with_label(
				sprintf(
					/* translators: 1: lower bound of a numeric band, 2: upper bound. */
					__( '%1$s to %2$s', 'solrengine' ),
					number_format_i18n( $start ),
					number_format_i18n( $start + $definition->gap() )
				)
			);
		}

		return $out;
	}
}
