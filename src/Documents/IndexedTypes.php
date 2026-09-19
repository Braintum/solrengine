<?php
/**
 * Which post types this site indexes.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Documents;

use SolrEngine\Support\Config;

/**
 * One answer to "what is indexed here", for everything that needs to ask.
 *
 * Four places need it: the save listeners, a full sync, the query integration
 * and the REST endpoint. When they disagree, the failures are quiet and
 * confusing — content that reaches the index on save and disappears on the next
 * full sync, or a REST caller asking for products and being handed the whole
 * site because the post type it named was not on a list it could not see.
 *
 * Both of those happened while Phase 6 was being built, from two copies of the
 * same expression. Hence one copy.
 *
 * @since 0.7.0
 */
final class IndexedTypes {

	/**
	 * The post types this site indexes.
	 *
	 * @since 0.7.0
	 *
	 * @param Config $config Configuration.
	 * @return string[]
	 */
	public static function all( Config $config ): array {
		$types = array_map( 'strval', $config->get_array( 'index.post_types', array( 'post', 'page' ) ) );

		/**
		 * Filters which post types are eligible for indexing.
		 *
		 * Where an integration declares a post type of its own — WooCommerce
		 * adds products here — and where a site removes one.
		 *
		 * @since 0.3.0
		 *
		 * @param string[] $types Configured post types.
		 */
		$types = array_map( 'strval', (array) apply_filters( 'solrengine_indexable_post_types', $types ) );

		return array_values( array_unique( array_filter( $types ) ) );
	}
}
