<?php
/**
 * Signatures for optional multilingual plugins.
 *
 * Polylang is an optional integration: present on a multilingual site and absent
 * everywhere else, so its functions are never autoloadable during static
 * analysis. This file exists for the same reason `woocommerce-stubs` is scanned —
 * so PHPStan can check the calls into it — and is the hand-written equivalent,
 * because no maintained stub package covers these two small APIs.
 *
 * Never loaded at runtime. Every call site guards with `function_exists()`.
 *
 * WPML needs nothing here: it is reached entirely through `apply_filters()`,
 * which is WordPress's own function and already analysable.
 *
 * @package SolrEngine
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- these are Polylang's names, not ours.

if ( ! function_exists( 'pll_languages_list' ) ) {
	/**
	 * Polylang's list of configured languages.
	 *
	 * @param array<string,mixed> $args Which field to return, and filters.
	 * @return array<int,string>
	 */
	function pll_languages_list( $args = array() ) {
		return array();
	}
}

if ( ! function_exists( 'pll_current_language' ) ) {
	/**
	 * Polylang's language for the current request.
	 *
	 * @param string $field Field to return, such as "locale".
	 * @return string|false
	 */
	function pll_current_language( $field = 'slug' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_default_language' ) ) {
	/**
	 * Polylang's default language.
	 *
	 * @param string $field Field to return, such as "locale".
	 * @return string|false
	 */
	function pll_default_language( $field = 'slug' ) {
		return false;
	}
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	/**
	 * Polylang's language for one post.
	 *
	 * @param int    $post_id Post identifier.
	 * @param string $field   Field to return, such as "locale".
	 * @return string|false
	 */
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		return false;
	}
}
