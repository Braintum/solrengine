<?php
/**
 * Turns a Solr suggest response into suggestions.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\Suggestion;
use SolrEngine\Contracts\Suggestions;

/**
 * Reads the documents a suggest query returned.
 *
 * Extracted from {@see \SolrEngine\Backend\Solr\SolrBackend::suggest()} so that
 * anything answering autocomplete reads a reply the same way. There were
 * briefly two parsers by accident — which is the kind of duplication that stays
 * correct until one side gains a field.
 *
 * Pure: decoded JSON in, contracts out. No WordPress, no transport.
 *
 * @since 0.10.2
 */
final class SuggestionParser {

	/**
	 * Read the documents out of a decoded suggest response.
	 *
	 * @since 0.10.2
	 *
	 * @param array<string,mixed> $body    Decoded Solr response.
	 * @param int                 $site_id Site the query was scoped to.
	 * @param string              $prefix  What the visitor typed.
	 * @return Suggestions
	 */
	public function parse( array $body, int $site_id, string $prefix ): Suggestions {
		$docs  = $body['response']['docs'] ?? array();
		$items = array();

		foreach ( (array) $docs as $doc ) {
			if ( ! is_array( $doc ) ) {
				continue;
			}

			$title = self::first( $doc['se_txt_title'] ?? '' );

			// A document with no title has nothing to show in a list of titles.
			if ( '' === $title ) {
				continue;
			}

			$items[] = new Suggestion(
				$title,
				self::first( $doc['se_s_url'] ?? '' ),
				new DocumentRef(
					$site_id,
					self::first( $doc['se_s_object_type'] ?? 'post' ),
					(int) self::first( $doc['se_i_object'] ?? 0 )
				),
				self::first( $doc['se_s_post_type'] ?? '' )
			);
		}

		return new Suggestions(
			$items,
			$prefix,
			(int) ( $body['responseHeader']['QTime'] ?? 0 )
		);
	}

	/**
	 * First value of a Solr field, which may be single or multi-valued.
	 *
	 * The schema contract makes most of these single-valued, but a field that
	 * was created by hand before the contract was applied can be multi-valued,
	 * and Solr then returns an array where one value is expected.
	 *
	 * @param mixed $value Field value.
	 * @return string
	 */
	private static function first( $value ): string {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		return is_scalar( $value ) ? (string) $value : '';
	}
}
