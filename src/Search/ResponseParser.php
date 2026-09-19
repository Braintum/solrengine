<?php
/**
 * Turns a Solr response into domain objects.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\FacetBucket;
use SolrEngine\Contracts\FacetDefinition;
use SolrEngine\Contracts\FacetResult;
use SolrEngine\Contracts\SearchHit;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\SearchResults;
use SolrEngine\Documents\DocumentFields;

/**
 * Reads a Solr select response.
 *
 * Pure, and separate from the transport that fetched it, so response shapes can
 * be exercised without a server.
 *
 * Defensive throughout. A response is data that crossed a network, and a
 * malformed document must produce one missing result rather than a fatal error
 * on a page a visitor is looking at.
 *
 * @since 0.4.0
 */
final class ResponseParser {

	/**
	 * Which facets may be counted, for labels and types.
	 *
	 * @var FacetRegistry
	 */
	private $facets;

	/**
	 * Constructor.
	 *
	 * @param FacetRegistry|null $facets Registered facets.
	 */
	public function __construct( ?FacetRegistry $facets = null ) {
		$this->facets = $facets ?? new FacetRegistry();
	}

	/**
	 * Parse a select response.
	 *
	 * @since 0.4.0
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @param SearchQuery         $query    The query that produced it.
	 * @return SearchResults
	 */
	public function parse( array $response, SearchQuery $query ): SearchResults {
		$body       = is_array( $response['response'] ?? null ) ? $response['response'] : array();
		$docs       = is_array( $body['docs'] ?? null ) ? $body['docs'] : array();
		$highlights = is_array( $response['highlighting'] ?? null ) ? $response['highlighting'] : array();

		$hits = array();

		foreach ( $docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$hit = $this->to_hit( $doc, $highlights, $query->site_id() );

			if ( null !== $hit ) {
				$hits[] = $hit;
			}
		}

		$results = new SearchResults(
			$hits,
			(int) ( $body['numFound'] ?? 0 ),
			$query,
			(int) ( $response['responseHeader']['QTime'] ?? 0 )
		);

		if ( ! $query->wants_facets() ) {
			return $results;
		}

		return $results->with_facets( $this->to_facets( $response, $query ) );
	}

	/**
	 * Read the facet section.
	 *
	 * Driven by what the query asked for rather than by what came back, so a
	 * facet Solr answered with nothing still produces a result object. A filter
	 * panel that dropped a control the moment its counts reached zero would
	 * take away the one control a visitor needs in order to widen their own
	 * search.
	 *
	 * @param array<string,mixed> $response Decoded response body.
	 * @param SearchQuery         $query    The query that produced it.
	 * @return array<string,FacetResult>
	 */
	private function to_facets( array $response, SearchQuery $query ): array {
		$section = is_array( $response['facets'] ?? null ) ? $response['facets'] : array();
		$filters = $query->filters();
		$out     = array();

		foreach ( $query->facets() as $key => $parent ) {
			$key        = (string) $key;
			$definition = $this->facets->get( $key );

			if ( null === $definition ) {
				continue;
			}

			$selected = array();

			foreach ( (array) ( $filters[ $key ] ?? array() ) as $value ) {
				if ( is_scalar( $value ) ) {
					$selected[] = (string) $value;
				}
			}

			$result = new FacetResult(
				$key,
				$definition->label(),
				$definition->type(),
				$this->buckets_for( $section[ $key ] ?? null, $definition, $selected )
			);

			$out[ $key ] = $result->with_selected( $selected )->with_parent( (string) $parent );
		}

		return $out;
	}

	/**
	 * Read one facet's buckets.
	 *
	 * @param mixed           $raw        Facet section for this key.
	 * @param FacetDefinition $definition The facet.
	 * @param string[]        $selected   Values the query filtered on.
	 * @return FacetBucket[]
	 */
	private function buckets_for( $raw, FacetDefinition $definition, array $selected ): array {
		if ( ! is_array( $raw ) || ! is_array( $raw['buckets'] ?? null ) ) {
			return array();
		}

		$buckets = array();

		foreach ( $raw['buckets'] as $bucket ) {
			if ( ! is_array( $bucket ) || ! isset( $bucket['val'] ) || ! is_scalar( $bucket['val'] ) ) {
				continue;
			}

			$count = (int) ( $bucket['count'] ?? 0 );

			if ( $count <= 0 && FacetDefinition::RANGE !== $definition->type() ) {
				// Solr already applies mincount to terms facets; this catches a
				// zero that arrived anyway. Empty range bands are kept, because
				// a price histogram with holes punched in it is misleading.
				continue;
			}

			$value = $this->bucket_value( $bucket['val'] );

			$buckets[] = ( new FacetBucket( $value, $count ) )
				->with_selected( in_array( $value, $selected, true ) );
		}

		return $buckets;
	}

	/**
	 * Render a bucket value as the string a caller sends back to filter by.
	 *
	 * @param scalar $value Raw value.
	 * @return string
	 */
	private function bucket_value( $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}

		if ( is_float( $value ) ) {
			// Solr returns a range bucket's lower bound as a number, and PHP's
			// default float rendering would turn 1200.0 into "1200" on one
			// platform and "1.2E+3" on another. A filter value that varies by
			// platform is a filter value that stops matching.
			return rtrim( rtrim( number_format( $value, 4, '.', '' ), '0' ), '.' );
		}

		return (string) $value;
	}

	/**
	 * Build a hit from one document.
	 *
	 * @param array<string,mixed> $doc        Document fields.
	 * @param array<string,mixed> $highlights Highlighting section.
	 * @param int                 $site_id    Expected site identifier.
	 * @return SearchHit|null
	 */
	private function to_hit( array $doc, array $highlights, int $site_id ): ?SearchHit {
		$object_id = (int) ( $doc[ DocumentFields::OBJECT_ID ] ?? 0 );

		if ( $object_id <= 0 ) {
			// A document without an object identifier cannot be rendered or
			// linked. Dropping it loses one result; returning it would produce
			// a link to nothing.
			return null;
		}

		$ref = new DocumentRef(
			(int) ( $doc[ DocumentFields::SITE ] ?? $site_id ),
			(string) ( $doc[ DocumentFields::OBJECT_TYPE ] ?? 'post' ),
			$object_id
		);

		$id = (string) ( $doc['id'] ?? '' );

		return new SearchHit(
			$ref,
			(float) ( $doc['score'] ?? 0.0 ),
			$this->scalarise( $doc ),
			$this->highlights_for( $id, $highlights )
		);
	}

	/**
	 * Flatten single-element arrays that Solr returns for multi-valued fields.
	 *
	 * A field declared multi-valued always comes back as an array, even when it
	 * holds one value. Callers rendering a title should not have to know which
	 * fields those are.
	 *
	 * @param array<string,mixed> $doc Document fields.
	 * @return array<string,mixed>
	 */
	private function scalarise( array $doc ): array {
		$out = array();

		foreach ( $doc as $field => $value ) {
			if ( is_array( $value ) && 1 === count( $value ) && is_scalar( reset( $value ) ) ) {
				$out[ $field ] = reset( $value );

				continue;
			}

			$out[ $field ] = $value;
		}

		return $out;
	}

	/**
	 * Highlighted snippets for one document.
	 *
	 * @param string              $id         Document identifier.
	 * @param array<string,mixed> $highlights Highlighting section.
	 * @return array<string,string[]>
	 */
	private function highlights_for( string $id, array $highlights ): array {
		if ( '' === $id || ! isset( $highlights[ $id ] ) || ! is_array( $highlights[ $id ] ) ) {
			return array();
		}

		$out = array();

		foreach ( $highlights[ $id ] as $field => $snippets ) {
			if ( ! is_array( $snippets ) ) {
				continue;
			}

			$clean = array();

			foreach ( $snippets as $snippet ) {
				if ( is_string( $snippet ) ) {
					$clean[] = $snippet;
				}
			}

			if ( array() !== $clean ) {
				$out[ (string) $field ] = $clean;
			}
		}

		return $out;
	}
}
