<?php
/**
 * The search popup.
 *
 * Override by copying this file to `yourtheme/solrengine/search-modal.php`.
 *
 * The script finds its parts by their `data-solrengine-*` attributes, never by
 * class, so classes and structure are yours to change. Keep each attribute on
 * some element, or the part it names stops working:
 *
 * - `data-solrengine-modal`    the <dialog> itself
 * - `data-solrengine-form`     a form; submitting it opens the full results page
 * - `data-solrengine-input`    the search field
 * - `data-solrengine-hint`     how to reach the results by keyboard; the
 *                              field is described by it
 * - `data-solrengine-tab-input` hidden field carrying the selected tab on submit
 * - `data-solrengine-clear`    clears the field
 * - `data-solrengine-close`    closes the popup
 * - `data-solrengine-tabs`     where the tab buttons are placed
 * - `data-solrengine-summary`  the "5 results for …" line
 * - `data-solrengine-results`  where the result sections are placed
 * - `data-solrengine-more`     a button that loads the next page of a tab; it
 *                              is also pressed for the visitor when it scrolls
 *                              into view
 * - `data-solrengine-message`  empty, error and hint text
 * - `data-solrengine-view-all` link to the full results page
 * - <template data-solrengine-template="tab"> one tab; fills `label` and `count`
 * - <template data-solrengine-template="section"> one group of results: on the
 *   All tab, one per content type; on a type tab, the one grid that scrolls.
 *   Inside it:
 *   - `data-solrengine-section-header` the heading row, removed on a type tab
 *   - `data-solrengine-field="label"` the type's plural name
 *   - `data-solrengine-field="count"` its number of matches
 *   - `data-solrengine-see-all`       opens that type's tab; fills `see_all`
 *   - `data-solrengine-section-results` the list the cards are placed in
 *
 * Result cards are separate templates: see search-modal/result.php.
 *
 * @package SolrEngine
 * @since   0.11.0
 *
 * @var array $args {
 *     @type string $heading     Text at the top left. Defaults to the site name.
 *     @type string $tagline     Text at the top right. Defaults to the site tagline.
 *     @type string $placeholder Placeholder in the search field.
 *     @type string $search_url  The site's search results address.
 *     @type bool   $show_credit Whether to show the "Powered by" line. Off unless a site opts in.
 * }
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<dialog class="solrengine-modal" aria-label="<?php esc_attr_e( 'Search', 'solrengine' ); ?>" data-solrengine-modal>
	<div class="solrengine-modal__panel">
		<div class="solrengine-modal__header">
			<p class="solrengine-modal__heading" id="solrengine-modal-heading">
				<svg class="solrengine-modal__mark" viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M3 6V4.5A1.5 1.5 0 0 1 4.5 3H6M14 3h1.5A1.5 1.5 0 0 1 17 4.5V6M17 14v1.5a1.5 1.5 0 0 1-1.5 1.5H14M6 17H4.5A1.5 1.5 0 0 1 3 15.5V14" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/><circle cx="9.5" cy="9.5" r="2.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m11.5 11.5 1.8 1.8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
				<span><?php echo esc_html( $args['heading'] ); ?></span>
			</p>
			<?php if ( '' !== $args['tagline'] ) : ?>
				<p class="solrengine-modal__tagline"><?php echo esc_html( $args['tagline'] ); ?></p>
			<?php endif; ?>
			<button type="button" class="solrengine-modal__close" data-solrengine-close>
				<span class="screen-reader-text"><?php esc_html_e( 'Close search', 'solrengine' ); ?></span>
				<svg viewBox="0 0 20 20" width="18" height="18" aria-hidden="true" focusable="false"><path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
		</div>

		<form class="solrengine-modal__search" role="search" method="get" action="<?php echo esc_url( $args['search_url'] ); ?>" data-solrengine-form>
			<svg class="solrengine-modal__search-icon" viewBox="0 0 20 20" width="20" height="20" aria-hidden="true" focusable="false"><circle cx="9" cy="9" r="6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m13.5 13.5 3.5 3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			<label class="screen-reader-text" for="solrengine-modal-input"><?php esc_html_e( 'Search for:', 'solrengine' ); ?></label>
			<input
				class="solrengine-modal__input"
				id="solrengine-modal-input"
				type="search"
				name="s"
				placeholder="<?php echo esc_attr( $args['placeholder'] ); ?>"
				autocomplete="off"
				autocapitalize="off"
				spellcheck="false"
				enterkeyhint="search"
				aria-describedby="solrengine-modal-hint"
				data-solrengine-input
			/>
			<span class="screen-reader-text" id="solrengine-modal-hint" data-solrengine-hint><?php esc_html_e( 'Results appear below as you type. Press the down arrow to move to them.', 'solrengine' ); ?></span>
			<input type="hidden" name="post_type" value="" disabled data-solrengine-tab-input />
			<span class="solrengine-modal__spinner" aria-hidden="true"></span>
			<button type="button" class="solrengine-modal__clear" hidden data-solrengine-clear>
				<span class="screen-reader-text"><?php esc_html_e( 'Clear search', 'solrengine' ); ?></span>
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="m5.5 5.5 9 9m0-9-9 9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
			</button>
		</form>

		<div class="solrengine-modal__tabs" role="tablist" aria-label="<?php esc_attr_e( 'Filter results by type', 'solrengine' ); ?>" hidden data-solrengine-tabs></div>

		<div class="solrengine-modal__body">
			<div class="solrengine-modal__summary" hidden data-solrengine-summary-row>
				<p class="solrengine-modal__count" data-solrengine-summary></p>
				<p class="solrengine-modal__order"><?php esc_html_e( 'Most relevant first', 'solrengine' ); ?></p>
			</div>
			<div class="solrengine-modal__results" data-solrengine-results></div>
			<button type="button" class="solrengine-modal__more" hidden data-solrengine-more>
				<span class="solrengine-modal__more-label"><?php esc_html_e( 'Load more results', 'solrengine' ); ?></span>
			</button>
			<p class="solrengine-modal__message" data-solrengine-message></p>
			<p class="screen-reader-text" aria-live="polite" data-solrengine-status></p>
		</div>

		<div class="solrengine-modal__footer">
			<?php if ( $args['show_credit'] ) : ?>
				<p class="solrengine-modal__credit"><?php esc_html_e( 'Powered by SolrEngine', 'solrengine' ); ?></p>
			<?php endif; ?>
			<a class="solrengine-modal__all" href="<?php echo esc_url( $args['search_url'] ); ?>" data-solrengine-view-all>
				<?php esc_html_e( 'View all results', 'solrengine' ); ?>
				<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M4 10h11m-4.5-4.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
			</a>
		</div>
	</div>

	<template data-solrengine-template="section">
		<section class="solrengine-section">
			<div class="solrengine-section__header" data-solrengine-section-header>
				<h2 class="solrengine-section__title">
					<span data-solrengine-field="label"></span>
					<span class="solrengine-section__count" data-solrengine-field="count"></span>
				</h2>
				<button type="button" class="solrengine-section__all" data-solrengine-see-all>
					<span data-solrengine-field="see_all"></span>
					<svg viewBox="0 0 20 20" width="16" height="16" aria-hidden="true" focusable="false"><path d="M4 10h11m-4.5-4.5L15 10l-4.5 4.5" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</button>
			</div>
			<ul class="solrengine-section__results" role="list" data-solrengine-section-results></ul>
		</section>
	</template>

	<template data-solrengine-template="tab">
		<button type="button" class="solrengine-tab" role="tab" aria-selected="false" tabindex="-1">
			<span class="solrengine-tab__label" data-solrengine-field="label"></span>
			<span class="solrengine-tab__count" data-solrengine-field="count"></span>
		</button>
	</template>
</dialog>
