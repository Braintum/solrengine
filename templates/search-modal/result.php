<?php
/**
 * A result card in the search popup.
 *
 * Override by copying this file to `yourtheme/solrengine/search-modal/result.php`.
 * To change the card for one post type only, add
 * `yourtheme/solrengine/search-modal/result-{post_type}.php` instead; the
 * product card beside this file is an example.
 *
 * Rendered once per page, into a <template>. The script copies it for each
 * result and fills it from these attributes:
 *
 * - `data-solrengine-field="title"`   title, with the searched words in <mark>
 * - `data-solrengine-field="type"`    the post type's singular name
 * - `data-solrengine-field="excerpt"` a short excerpt
 * - `data-solrengine-field="date"`    publication date, in the site's language
 * - `data-solrengine-field="author"`  author's display name
 * - `data-solrengine-field="price"`   a product's price
 * - `data-solrengine-href="url"`      sets href
 * - `data-solrengine-src="thumbnail"` sets src, or removes the element when
 *                                     there is no image (the placeholder icon
 *                                     behind it then shows)
 * - `data-solrengine-if="excerpt"`    removes the element when that field is empty
 *
 * The outermost element must be the only one, and is the listbox option: keep
 * `role="option"` on it. After filling a card the script dispatches a bubbling
 * `solrengine:result` event on it, with `{ result, element }` as its detail,
 * for anything a template cannot express.
 *
 * Text is always inserted as text. A template cannot cause a title to be
 * parsed as markup, and should not try.
 *
 * @package SolrEngine
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $post_type The post type this card is for, or '' for the default card.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<li class="solrengine-result" role="option" aria-selected="false">
	<a class="solrengine-result__link" tabindex="-1" data-solrengine-href="url">
		<span class="solrengine-result__media">
			<svg class="solrengine-result__placeholder" viewBox="0 0 24 24" width="28" height="28" aria-hidden="true" focusable="false"><path d="M7 3.5h6.5L18 8v11.5a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1v-15a1 1 0 0 1 1-1Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><path d="M13 3.5V8h5M9 12.5h6M9 15.5h6" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
			<img class="solrengine-result__image" alt="" loading="lazy" decoding="async" data-solrengine-src="thumbnail" />
		</span>
		<span class="solrengine-result__body">
			<span class="solrengine-result__type" data-solrengine-field="type"></span>
			<span class="solrengine-result__title" data-solrengine-field="title"></span>
			<span class="solrengine-result__excerpt" data-solrengine-if="excerpt" data-solrengine-field="excerpt"></span>
		</span>
		<svg class="solrengine-result__go" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M6.5 13.5 13.5 6.5M7.5 6.5h6v6" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
	</a>
</li>
