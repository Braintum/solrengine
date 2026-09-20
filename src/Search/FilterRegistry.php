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
	 * @var array<string,array{field:string,type:string,label:string,gap:float,range:bool,exclude:bool}>
	 */
	private $filters = array();

	/**
	 * Build the default registry for a set of taxonomies.
	 *
	 * Taxonomies arrive as a map rather than a list because a hierarchical one
	 * filters differently: it matches on the materialised ancestor path, so
	 * narrowing to "Guides" includes everything filed below Guides. That is
	 * what a visitor means by it, and it is what makes a filter agree with the
	 * facet count beside it — the count comes from the same path field, and a
	 * filter on term identifiers alone would produce a count of 40 next to a
	 * page showing 12 results.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,array{label?:string,hierarchical?:bool}> $taxonomies Public taxonomies.
	 * @param array<string,string>                                  $languages  Languages the site publishes in.
	 * @return self
	 */
	public static function defaults( array $taxonomies = array(), array $languages = array() ): self {
		$registry = new self();

		$registry->register( 'post_type', DocumentFields::POST_TYPE, FieldType::STRING, __( 'Content type', 'solrengine' ) );
		$registry->register( 'author', DocumentFields::AUTHOR_ID, FieldType::INT, __( 'Author', 'solrengine' ) );

		if ( count( $languages ) > 1 ) {
			// A filter as well as the query-level scope, because the two answer
			// different questions. The scope is "this visitor is reading the
			// German site"; the filter is "show me the French translations",
			// which a REST caller building a language switcher needs to ask.
			$registry->register( 'language', DocumentFields::LANG, FieldType::STRING, __( 'Language', 'solrengine' ) );
		}

		foreach ( $taxonomies as $name => $taxonomy ) {
			$name  = (string) $name;
			$label = (string) ( $taxonomy['label'] ?? $name );

			if ( ! empty( $taxonomy['hierarchical'] ) ) {
				$registry->register(
					$name,
					DocumentFields::taxonomy_paths( $name ),
					FieldType::STRING,
					$label
				);

				continue;
			}

			$registry->register(
				$name,
				DocumentFields::taxonomy_ids( $name ),
				FieldType::INT,
				$label
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
			'field'   => $field,
			'type'    => $type,
			'label'   => '' !== $label ? $label : $key,
			'gap'     => 0.0,
			'range'   => false,
			'exclude' => false,
		);
	}

	/**
	 * Register a filter whose values name the start of a numeric band.
	 *
	 * The counterpart of a range facet. A range facet's buckets are keyed by
	 * their lower bound, so this is what turns the value a visitor ticked back
	 * into the band it came from — server-side, from the same gap the facet was
	 * declared with. A client sending "1200" gets the band it saw counted; it
	 * never sends bounds of its own, which is what stops a caller asking Solr
	 * for an arbitrary range over an arbitrary field.
	 *
	 * @since 0.6.0
	 *
	 * @param string $key   Public key a caller uses.
	 * @param string $field Index field it maps to.
	 * @param float  $gap   Band width.
	 * @param string $label Human label for a UI.
	 * @return void
	 */
	public function register_bands( string $key, string $field, float $gap, string $label = '' ): void {
		if ( $gap <= 0.0 ) {
			return;
		}

		$this->register( $key, $field, FieldType::FLOAT, $label );

		if ( isset( $this->filters[ $key ] ) ) {
			$this->filters[ $key ]['gap'] = $gap;
		}
	}

	/**
	 * Register a filter whose values name an arbitrary numeric range.
	 *
	 * Distinct from a banded filter, which only accepts bands the server
	 * declared. This accepts a pair of numbers, because a price slider is a
	 * pair of numbers and pretending otherwise would mean refusing to answer
	 * the query WooCommerce's own widget produces.
	 *
	 * It is still not a free filter query: the field is chosen here, both
	 * bounds must parse as numbers, and an unparseable pair yields no clause
	 * at all. The values are never interpolated as text.
	 *
	 * @since 0.7.0
	 *
	 * @param string $key   Public key a caller uses.
	 * @param string $field Index field it maps to.
	 * @param string $label Human label for a UI.
	 * @return void
	 */
	public function register_range( string $key, string $field, string $label = '' ): void {
		$this->register( $key, $field, FieldType::FLOAT, $label );

		if ( isset( $this->filters[ $key ] ) ) {
			$this->filters[ $key ]['range'] = true;
		}
	}

	/**
	 * Register a filter that removes matches rather than requiring them.
	 *
	 * The clause it builds is negative, which means it also matches every
	 * document that does not carry the field at all. That is exactly what a
	 * rule like "not hidden from search" needs: it has to narrow products
	 * without touching posts, pages, or anything else in the same index, and a
	 * positive clause would quietly delete all of them from the results.
	 *
	 * @since 0.7.0
	 *
	 * @param string $key   Public key a caller uses.
	 * @param string $field Index field it maps to.
	 * @param string $type  Value type, from FieldType.
	 * @param string $label Human label for a UI.
	 * @return void
	 */
	public function register_excluding( string $key, string $field, string $type, string $label = '' ): void {
		$this->register( $key, $field, $type, $label );

		if ( isset( $this->filters[ $key ] ) ) {
			$this->filters[ $key ]['exclude'] = true;
		}
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
	 * @param string            $mode   "or" for any value, "and" for all of them.
	 * @return string
	 */
	public function clause_for( string $key, array $values, string $mode = 'or' ): string {
		if ( ! isset( $this->filters[ $key ] ) || array() === $values ) {
			return '';
		}

		$filter = $this->filters[ $key ];

		if ( $filter['gap'] > 0.0 ) {
			return $this->band_clause( $filter, $values );
		}

		if ( ! empty( $filter['range'] ) ) {
			return $this->range_clause( $filter, $values );
		}

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

		// Multiple values for one key are an OR by default: picking two
		// categories means "in either", which is what a shopper ticking two
		// boxes expects. A conjunction means "has both", which is what
		// WooCommerce's layered navigation means by default and what makes
		// "16 GB and SSD" return the products that are both.
		$join   = 'and' === $mode ? ' AND ' : ' OR ';
		$clause = $filter['field'] . ':(' . implode( $join, $rendered ) . ')';

		if ( empty( $filter['exclude'] ) ) {
			return $clause;
		}

		// Negated, and deliberately paired with a match-all: a filter query
		// that is nothing but a negation matches nothing in Solr, which would
		// turn "hide out-of-stock products" into "hide everything".
		return '*:* -' . $clause;
	}

	/**
	 * Build the clause for a banded filter.
	 *
	 * The upper bound is exclusive, so adjacent bands cannot both match one
	 * document. Inclusive bounds would make a product priced at exactly 1200
	 * appear under both "1100 to 1200" and "1200 to 1300", and the counts
	 * beside those bands would not add up to the total.
	 *
	 * @param array{field:string,type:string,label:string,gap:float,range:bool,exclude:bool} $filter Filter definition.
	 * @param array<int,scalar>                                                              $values Band lower bounds.
	 * @return string
	 */
	private function band_clause( array $filter, array $values ): string {
		$clauses = array();

		foreach ( $values as $value ) {
			if ( ! is_numeric( $value ) ) {
				continue;
			}

			$start = (float) $value;

			$clauses[] = sprintf(
				'%s:[%s TO %s}',
				$filter['field'],
				self::number( $start ),
				self::number( $start + $filter['gap'] )
			);
		}

		if ( array() === $clauses ) {
			return '';
		}

		return 1 === count( $clauses ) ? $clauses[0] : '(' . implode( ' OR ', $clauses ) . ')';
	}

	/**
	 * Build the clause for a bounded numeric range.
	 *
	 * Values arrive as "min:max", with either side allowed to be empty for an
	 * open bound. Anything that does not parse as a number produces no clause,
	 * because a half-understood price filter is worse than none: it would show
	 * a shopper a filtered page that is not filtered.
	 *
	 * @param array{field:string,type:string,label:string,gap:float,range:bool,exclude:bool} $filter Filter definition.
	 * @param array<int,scalar>                                                              $values Range expressions.
	 * @return string
	 */
	private function range_clause( array $filter, array $values ): string {
		$clauses = array();

		foreach ( $values as $value ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				continue;
			}

			$parts = explode( ':', (string) $value );

			if ( 2 !== count( $parts ) ) {
				continue;
			}

			$min = trim( $parts[0] );
			$max = trim( $parts[1] );

			if ( ( '' !== $min && ! is_numeric( $min ) ) || ( '' !== $max && ! is_numeric( $max ) ) ) {
				continue;
			}

			if ( '' === $min && '' === $max ) {
				continue;
			}

			$clauses[] = sprintf(
				'%s:[%s TO %s]',
				$filter['field'],
				'' === $min ? '*' : self::number( (float) $min ),
				'' === $max ? '*' : self::number( (float) $max )
			);
		}

		if ( array() === $clauses ) {
			return '';
		}

		return 1 === count( $clauses ) ? $clauses[0] : '(' . implode( ' OR ', $clauses ) . ')';
	}

	/**
	 * Render a number for a range clause.
	 *
	 * Fixed notation, always. PHP renders large floats in exponential form,
	 * which Solr's range parser does not accept, and the failure would appear
	 * only for sites whose prices happen to be large.
	 *
	 * @param float $value Value to render.
	 * @return string
	 */
	private static function number( float $value ): string {
		return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
	}
}
