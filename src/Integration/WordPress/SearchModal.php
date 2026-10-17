<?php
/**
 * The search popup.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Documents\IndexedTypes;
use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Support\Config;

/**
 * Opens a search popup from the theme's own search field and button.
 *
 * Like {@see InstantSearch}, it attaches to the search form the theme already
 * renders rather than replacing it: the theme's field stays where it is and
 * looks as it did, and clicking it, typing into it or pressing its button
 * opens the popup instead of submitting. Without JavaScript nothing changes and
 * the form submits as it always has.
 *
 * Results are fetched as JSON and rendered in the browser, because the fast
 * path that answers them loads no theme and so cannot run a theme's PHP. The
 * markup is still the theme's to override: every template is rendered here,
 * once per page, on a full boot with the theme loaded — the dialog itself, and
 * a <template> per result card — and the script fills copies of them. See
 * templates/search-modal/result.php for the contract.
 *
 * @since 0.11.0
 */
final class SearchModal {

	/**
	 * Script and style handle.
	 *
	 * @var string
	 */
	public const HANDLE = 'solrengine-search-modal';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * The classic theme's `$content_width` as the theme declared it.
	 *
	 * @var mixed
	 */
	private $declared_width = null;

	/**
	 * Constructor.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Attach the hooks.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
		// Last, once the theme has declared its width, and before any template
		// narrows it for the page it is rendering.
		add_action( 'after_setup_theme', array( $this, 'remember_content_width' ), PHP_INT_MAX );
		// Late, so a theme that prints its own footer markup has done so and
		// the dialog sits at the end of the body, outside any theme wrapper
		// that might clip or transform it.
		add_action( 'wp_footer', array( $this, 'print_markup' ), 50 );
	}

	/**
	 * Enqueue the script and style.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		$asset_file = SOLRENGINE_PLUGIN_DIR . 'assets/build/search-modal.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			// A source checkout without a build. The theme's search form keeps
			// working exactly as it did.
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			SOLRENGINE_PLUGIN_URL . 'assets/build/search-modal.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SOLRENGINE_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);

		wp_enqueue_style(
			self::HANDLE,
			SOLRENGINE_PLUGIN_URL . 'assets/build/search-modal.css',
			array(),
			$asset['version'] ?? SOLRENGINE_VERSION
		);

		wp_style_add_data( self::HANDLE, 'rtl', 'replace' );

		$width = $this->content_width();

		if ( '' !== $width ) {
			// A fallback the stylesheet reads beneath --solrengine-modal-width,
			// so a theme that sets the documented property still wins.
			wp_add_inline_style( self::HANDLE, '.solrengine-modal{--solrengine-content-width:' . $width . ';}' );
		}

		wp_set_script_translations( self::HANDLE, 'solrengine' );

		// JSON rather than wp_localize_script(), which turns every top-level
		// number and boolean into a string.
		wp_add_inline_script(
			self::HANDLE,
			'var solrEngineSearchModal = ' . wp_json_encode( $this->settings() ) . ';',
			'before'
		);
	}

	/**
	 * Print the dialog and the card templates.
	 *
	 * Only on a page where the script was actually enqueued, so a template
	 * that removed it — or an asset build that is missing — does not leave an
	 * inert dialog in the page.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function print_markup(): void {
		if ( ! wp_script_is( self::HANDLE, 'enqueued' ) ) {
			return;
		}

		$markup = Templates::render( 'search-modal.php', $this->template_args() );

		if ( '' === $markup ) {
			return;
		}

		$markup .= $this->card_template( '' );

		foreach ( IndexedTypes::all( $this->config ) as $post_type ) {
			if ( 1 !== preg_match( '/^[a-z0-9_-]+$/', $post_type ) ) {
				continue;
			}

			if ( '' !== Templates::locate( 'search-modal/result-' . $post_type . '.php' ) ) {
				$markup .= $this->card_template( $post_type );
			}
		}

		// Already escaped where it was produced: every template escapes its
		// own output, as a theme template does, and a theme's override is
		// trusted exactly as far as the rest of the theme is.
		echo $markup; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * One result card, wrapped in a <template>.
	 *
	 * @param string $post_type Post type, or '' for the default card.
	 * @return string
	 */
	private function card_template( string $post_type ): string {
		$name = '' === $post_type ? 'search-modal/result.php' : 'search-modal/result-' . $post_type . '.php';
		$card = Templates::render( $name, array( 'post_type' => $post_type ) );

		if ( '' === trim( $card ) ) {
			return '';
		}

		return sprintf(
			'<template data-solrengine-template="result" data-solrengine-post-type="%1$s">%2$s</template>' . "\n",
			esc_attr( $post_type ),
			$card
		);
	}

	/**
	 * What the dialog template receives.
	 *
	 * @return array<string,mixed>
	 */
	private function template_args(): array {
		$defaults = array(
			'heading'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'tagline'     => wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES ),
			'placeholder' => __( 'Search…', 'solrengine' ),
			'search_url'  => home_url( '/' ),
			// Off. WordPress.org guideline 10: a plugin may not put a credit on
			// the public site unless the site owner chooses to show it.
			'show_credit' => false,
		);

		/**
		 * Filters what the search popup's template receives.
		 *
		 * Change the heading, tagline or placeholder, or opt in to the
		 * "Powered by SolrEngine" line, without overriding the template.
		 *
		 * @since 0.11.0
		 *
		 * @param array $args {
		 *     @type string $heading     Text at the top left. The site name by default.
		 *     @type string $tagline     Text at the top right. The site tagline by default.
		 *     @type string $placeholder Placeholder in the search field.
		 *     @type string $search_url  The site's search results address.
		 *     @type bool   $show_credit Whether to show "Powered by SolrEngine". False by default.
		 * }
		 */
		$args = apply_filters( 'solrengine_search_modal_args', $defaults );
		$args = is_array( $args ) ? $args : array();

		return array(
			'heading'     => (string) ( $args['heading'] ?? $defaults['heading'] ),
			'tagline'     => (string) ( $args['tagline'] ?? $defaults['tagline'] ),
			'placeholder' => (string) ( $args['placeholder'] ?? $defaults['placeholder'] ),
			'search_url'  => (string) ( $args['search_url'] ?? $defaults['search_url'] ),
			'show_credit' => (bool) ( $args['show_credit'] ?? false ),
		);
	}

	/**
	 * Record the theme's declared `$content_width`.
	 *
	 * A theme may narrow the global later, per page, to the column beside a
	 * sidebar — GeneratePress does, on `wp`. That is right for an embed in the
	 * column and wrong for a popup over the whole site.
	 *
	 * @since 0.11.0
	 *
	 * @return void
	 */
	public function remember_content_width(): void {
		$this->declared_width = $GLOBALS['content_width'] ?? null;
	}

	/**
	 * The site's content width, as a CSS length.
	 *
	 * So the popup is as wide as the site it opens over: a block theme's wide
	 * size, else its content size, else a classic theme's `$content_width` as
	 * the theme declared it. Only a plain length or a simple CSS function of
	 * lengths and custom properties is accepted — the value is printed into a
	 * stylesheet, and neither theme.json nor a filter is trusted with arbitrary
	 * CSS.
	 *
	 * @since 0.11.0
	 *
	 * @return string The width, or '' when the theme does not say.
	 */
	public function content_width(): string {
		$candidates = array();

		if ( function_exists( 'wp_get_global_settings' ) ) {
			// A missing path returns the whole settings array rather than
			// null, so only a string counts as an answer.
			$candidates[] = wp_get_global_settings( array( 'layout', 'wideSize' ) );
			$candidates[] = wp_get_global_settings( array( 'layout', 'contentSize' ) );
		}

		$classic = $this->declared_width ?? ( $GLOBALS['content_width'] ?? null );

		if ( is_numeric( $classic ) && (int) $classic > 0 ) {
			$candidates[] = (int) $classic . 'px';
		}

		$width = '';

		foreach ( $candidates as $candidate ) {
			if ( is_string( $candidate ) && self::is_css_length( trim( $candidate ) ) ) {
				$width = trim( $candidate );
				break;
			}
		}

		/**
		 * Filters the width the search popup grows to.
		 *
		 * For a theme that declares no width, or declares a narrower one than
		 * its layout: return the site container's width, such as `'1200px'`.
		 * A plain length, a percentage, or calc(), min(), max(), clamp() or
		 * var() over them; anything else is ignored. Return '' for the
		 * stylesheet's default. A theme can also set
		 * `--solrengine-modal-width` in CSS, which wins over this.
		 *
		 * @since 0.11.0
		 *
		 * @param string $width The theme's wide size, content size or
		 *                      `$content_width`, as a CSS length, or ''.
		 */
		$filtered = apply_filters( 'solrengine_search_modal_width', $width );
		$filtered = is_string( $filtered ) ? trim( $filtered ) : '';

		return self::is_css_length( $filtered ) ? $filtered : '';
	}

	/**
	 * Whether a value is safe to print as a CSS width.
	 *
	 * Lengths, percentages, and calc/min/max/clamp/var over them. No quotes,
	 * semicolons, braces, colons or url(), so the value cannot end the
	 * declaration it is printed into.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	private static function is_css_length( string $value ): bool {
		if ( '' === $value || strlen( $value ) > 200 || false !== stripos( $value, 'url' ) ) {
			return false;
		}

		return 1 === preg_match( '/^[a-z0-9.%(),+*\/\s-]+$/i', $value )
			&& 1 === preg_match( '/^(\d+(\.\d+)?(px|rem|em|vw|ch|%)|(calc|min|max|clamp|var)\(.*\))$/i', $value );
	}

	/**
	 * What the script is told.
	 *
	 * @return array<string,mixed>
	 */
	private function settings(): array {
		$fast = InstantSearch::fast_path_url( $this->config );

		return array(
			'endpoints' => array(
				'fast' => '' === $fast ? '' : add_query_arg( 'mode', 'results', $fast ),
				'rest' => rest_url( 'solrengine/v1/instant' ),
			),
			'minimum'   => SuggestQuery::MIN_LENGTH,
			'fields'    => InstantSearch::selector(),
			/**
			 * Filters which elements open the search popup when clicked.
			 *
			 * For search icons, menu items and buttons that are not part of a
			 * search form. The theme's search fields and the buttons that
			 * submit them are matched separately, through
			 * `solrengine_instant_search_selector`.
			 *
			 * @since 0.11.0
			 *
			 * @param string $selector A CSS selector.
			 */
			'triggers'  => (string) apply_filters(
				'solrengine_search_modal_triggers',
				'.solrengine-search-trigger, [data-solrengine-search], a[href="#solrengine-search"]'
			),
			'searchUrl' => home_url( '/' ),
			'locale'    => str_replace( '_', '-', determine_locale() ),
			'postTypes' => $this->post_types(),
			'price'     => $this->price_format(),
		);
	}

	/**
	 * Labels for the tabs and cards, in the site's order.
	 *
	 * A list rather than a map, because the order is the tab order and a JSON
	 * object's key order is not something to rely on.
	 *
	 * @return array<int,array{key:string,singular:string,plural:string}>
	 */
	private function post_types(): array {
		$out = array();

		foreach ( IndexedTypes::all( $this->config ) as $post_type ) {
			$object = get_post_type_object( $post_type );

			if ( null === $object ) {
				continue;
			}

			$out[] = array(
				'key'      => $post_type,
				'singular' => (string) $object->labels->singular_name,
				'plural'   => (string) $object->labels->name,
			);
		}

		return $out;
	}

	/**
	 * The store's price format, or null on a site that sells nothing.
	 *
	 * Read from WooCommerce here, on a full boot, because the fast path that
	 * answers the popup has no WooCommerce to ask.
	 *
	 * @return array<string,mixed>|null
	 */
	private function price_format(): ?array {
		if ( ! WooCommerce::is_active() ) {
			return null;
		}

		return array(
			'symbol'     => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'position'   => (string) get_option( 'woocommerce_currency_pos', 'left' ),
			'decimals'   => wc_get_price_decimals(),
			'decimal'    => wc_get_price_decimal_separator(),
			'thousand'   => wc_get_price_thousand_separator(),
			'taxDisplay' => (string) get_option( 'woocommerce_tax_display_shop', 'excl' ),
		);
	}
}
