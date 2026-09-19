<?php
/**
 * Turns a Solr response into domain objects.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\DocumentRef;
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

		return new SearchResults(
			$hits,
			(int) ( $body['numFound'] ?? 0 ),
			$query,
			(int) ( $response['responseHeader']['QTime'] ?? 0 )
		);
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
