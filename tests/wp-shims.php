<?php
/**
 * Minimal stand-ins for the handful of WordPress functions that unit-testable
 * classes call.
 *
 * The unit suite deliberately loads no WordPress (plan section 11.1). A few
 * functions are unavoidable even in code that has no business knowing about
 * WordPress: translation wrappers, and wp_parse_url(), which WordPress Coding
 * Standards requires in place of parse_url(). Shimming those is honest; it is
 * not a way to sneak WordPress-dependent logic into the unit suite.
 *
 * If a class under unit test ever needs a shim with real behaviour — an option,
 * a hook, a query — that is a seam leaking, and the fix belongs in the class.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * @param string $url       URL to parse.
	 * @param int    $component Component to return.
	 * @return mixed
	 */
	function wp_parse_url( string $url, int $component = -1 ) {
		return parse_url( $url, $component ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url
	}
}

if ( ! function_exists( '__' ) ) {
	/**
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function __( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * @param string $text   Text to translate.
	 * @param string $domain Text domain.
	 * @return string
	 */
	function esc_html__( string $text, string $domain = 'default' ): string {
		unset( $domain );

		return htmlspecialchars( $text, ENT_QUOTES );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * @param mixed $data    Data to encode.
	 * @param int   $options Encoding options.
	 * @param int   $depth   Maximum depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, int $options = 0, int $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
