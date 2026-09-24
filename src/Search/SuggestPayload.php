<?php
/**
 * Renders suggestions as the response body autocomplete reads.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Search;

use SolrEngine\Contracts\Suggestions;

/**
 * The wire format of an autocomplete response.
 *
 * Shared by {@see \SolrEngine\Rest\SearchController::handle_suggest()} and
 * {@see \SolrEngine\Rest\InstantEndpoint}, which answer the same front end over
 * two different bootstraps. `instant-search.js` must not be able to tell which
 * one replied, so the shape lives in one place rather than being written twice
 * and compared by hand.
 *
 * Pure: contracts in, arrays out.
 *
 * @since 0.10.2
 */
final class SuggestPayload {

	/**
	 * Render the response body.
	 *
	 * @since 0.10.2
	 *
	 * @param Suggestions $suggestions Suggestions to render.
	 * @return array<string,mixed>
	 */
	public function render( Suggestions $suggestions ): array {
		$items = array();

		foreach ( $suggestions->items() as $item ) {
			$items[] = array(
				'text'      => $item->text(),
				'url'       => $item->url(),
				'id'        => null === $item->ref() ? 0 : $item->ref()->object_id(),
				'post_type' => $item->post_type(),
			);
		}

		return array(
			'suggestions' => $items,
			'prefix'      => $suggestions->prefix(),
			'took_ms'     => $suggestions->took_ms(),
		);
	}
}
