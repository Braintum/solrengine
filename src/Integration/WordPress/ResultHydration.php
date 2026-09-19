<?php
/**
 * Turning hits into what WP_Query asked for.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\SearchResults;
use WP_Post;
use WP_Query;

/**
 * Renders search results as the posts a query expected.
 *
 * Shared by the two places that substitute results into WordPress — site search
 * and the shop — because the mechanics are identical and two copies would drift
 * the moment one of them learned something the other did not.
 *
 * @since 0.7.0
 */
final class ResultHydration {

	/**
	 * Turn hits into whatever WordPress asked for.
	 *
	 * Honours the "fields" query variable. A query asking for IDs must not be
	 * handed full objects: callers that asked for IDs treat the return value as
	 * scalars, and handing them objects produces errors far from here.
	 *
	 * @since 0.7.0
	 *
	 * @param SearchResults $results Results.
	 * @param WP_Query      $query   The query being answered.
	 * @return array<int,mixed>
	 */
	public static function posts( SearchResults $results, WP_Query $query ): array {
		$ids = $results->object_ids();

		if ( array() === $ids ) {
			return array();
		}

		$fields = (string) $query->get( 'fields' );

		if ( 'ids' === $fields ) {
			return $ids;
		}

		// One query for every post, rather than one per post while the theme
		// renders. Without this a page of ten results costs ten queries plus
		// their meta and term lookups.
		_prime_post_caches( $ids, true, true );

		$posts = array();

		foreach ( $ids as $id ) {
			$post = get_post( $id );

			if ( $post instanceof WP_Post ) {
				// Relevance order comes from the index, so the posts are
				// appended in the order the hits arrived rather than the order
				// a database would have returned them.
				$posts[] = $post;
			}
		}

		if ( 'id=>parent' === $fields ) {
			$pairs = array();

			foreach ( $posts as $post ) {
				$pairs[ $post->ID ] = $post->post_parent;
			}

			return $pairs;
		}

		return $posts;
	}

	/**
	 * Tell a query how many results there are.
	 *
	 * WordPress cannot work this out itself, because no database query ran.
	 * Without it, pagination renders one page however many results there are.
	 *
	 * @since 0.7.0
	 *
	 * @param WP_Query      $query   The query being answered.
	 * @param SearchResults $results Results.
	 * @return void
	 */
	public static function paginate( WP_Query $query, SearchResults $results ): void {
		$query->found_posts   = $results->total();
		$query->max_num_pages = $results->total_pages();
	}
}
