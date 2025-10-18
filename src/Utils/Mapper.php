<?php
/**
 * Class Mapper
 *
 * Maps WordPress posts to Solr documents.
 *
 * @package SolrEngine\Utils
 */

namespace SolrEngine\Utils;

use SolrEngine\Interfaces\MapperInterface;

/**
 * Class Mapper
 *
 * Maps WordPress posts to Solr documents.
 *
 * @package SolrEngine\Utils
 */
class Mapper implements MapperInterface {

	/**
	 * Map WP_Post to array.
	 *
	 * @param \WP_Post $post WP_Post object.
	 * @return array
	 */
	public function mapPost( \WP_Post $post ): array {
		// Sanitize, cast fields; ensure 'id' field exists.
		return array(
			'id'        => (string) $post->ID,
			'title'     => sanitize_text_field( $post->post_title ),
			'content'   => wp_strip_all_tags( $post->post_content ),
			'post_type' => $post->post_type,
			'status'    => $post->post_status,
			'url'       => get_permalink( $post ),
			'date'      => get_post_time( 'c', true, $post ),
			/**
			 * Allows other plugins to extend the mapping by adding taxonomies, meta fields, and custom fields.
			 *
			 * Filters can be used here to modify or add additional data to the mapping process.
			 *
			 * @since 1.0.0
			 *
			 * @hook solrengine_mapper_extend
			 * @param array $mapping The current mapping array.
			 * @return array Modified mapping array with additional taxonomies, meta, or custom fields.
			 */
			// Add taxonomies, meta, custom fields via filters.
			// Allow other plugins to extend mapping.
			//'meta' => apply_filters( 'solrengine_map_post_meta', $this->collect_meta( $post->ID ), $post ),
		);
	}

	/**
	 * Map product data to array.
	 *
	 * @param mixed $product Product object or array.
	 * @return array
	 */
	public function mapProduct( $product ): array {
		// Example implementation, adjust fields as needed.
		return array(
			'id'      => isset( $product->ID ) ? (string) $product->ID : '',
			'name'    => isset( $product->name ) ? sanitize_text_field( $product->name ) : '',
			'price'   => isset( $product->price ) ? floatval( $product->price ) : 0.0,
			'status'  => isset( $product->status ) ? $product->status : '',
			'meta'    => isset( $product->ID ) ? $this->collect_meta( $product->ID ) : array(),
		);
	}

	/**
	 * Collect and simplify post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	protected function collect_meta( int $post_id ): array {
		$meta = get_post_meta( $post_id );
		$out  = array();

		foreach ( $meta as $key => $value ) {
			$out[ $key ] = maybe_unserialize( isset( $value[0] ) ? $value[0] : $value );
		}

		return $out;
	}
}
