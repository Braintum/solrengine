<?php
/**
 * A product card in the search popup.
 *
 * Override by copying this file to
 * `yourtheme/solrengine/search-modal/result-product.php`. The attributes are
 * the ones documented in result.php.
 *
 * The price is formatted in the browser from the indexed base price, in the
 * store's currency format. It can differ from the product page for a customer
 * in another tax jurisdiction, under a role-based or multi-currency price, or
 * while a scheduled sale starts or ends before the product is reindexed. A
 * store where that matters removes the price element from its copy.
 *
 * @package SolrEngine
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $post_type "product".
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="solrengine-result solrengine-result--product">
	<a class="solrengine-result__link" data-solrengine-href="url">
		<span class="solrengine-result__media" data-solrengine-if="thumbnail">
			<img class="solrengine-result__image" alt="" loading="lazy" decoding="async" data-solrengine-src="thumbnail" />
		</span>
		<span class="solrengine-result__body">
			<span class="solrengine-result__type" data-solrengine-field="type"></span>
			<span class="solrengine-result__title" data-solrengine-field="title"></span>
			<span class="solrengine-result__price" data-solrengine-if="price" data-solrengine-field="price"></span>
		</span>
		<svg class="solrengine-result__go" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6.5 13.5 13.5 6.5M7.5 6.5h6v6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
</li>
