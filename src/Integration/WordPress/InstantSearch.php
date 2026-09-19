<?php
/**
 * Attaches autocomplete to the theme's search field.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Integration\WordPress;

use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Support\Config;

/**
 * Enqueues the instant-search component.
 *
 * Attaches to whatever search input the theme already renders rather than
 * providing one. A plugin that replaced the theme's search box would have to
 * match its styling on every theme ever written, and would be wrong on most of
 * them.
 *
 * @since 0.5.0
 */
final class InstantSearch {

	/**
	 * Script and style handle.
	 *
	 * @var string
	 */
	private const HANDLE = 'solrengine-instant-search';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

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
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	/**
	 * Enqueue the script and style.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public function enqueue(): void {
		if ( ! $this->config->get_bool( 'search.instant_enabled', false ) ) {
			return;
		}

		$asset_file = SOLRENGINE_PLUGIN_DIR . 'assets/build/instant-search.asset.php';

		if ( ! is_readable( $asset_file ) ) {
			// Built assets are missing. A source checkout without a build is a
			// developer's problem, not a visitor's: the search box keeps
			// working without autocomplete.
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			self::HANDLE,
			SOLRENGINE_PLUGIN_URL . 'assets/build/instant-search.js',
			$asset['dependencies'] ?? array(),
			$asset['version'] ?? SOLRENGINE_VERSION,
			true
		);

		wp_enqueue_style(
			self::HANDLE,
			SOLRENGINE_PLUGIN_URL . 'assets/build/instant-search.css',
			array(),
			$asset['version'] ?? SOLRENGINE_VERSION
		);

		wp_localize_script(
			self::HANDLE,
			'solrEngineInstantSearch',
			array(
				'endpoint' => rest_url( 'solrengine/v1/suggest' ),
				'limit'    => $this->config->get_int( 'search.instant_limit', 8 ),
				'minimum'  => SuggestQuery::MIN_LENGTH,
				/**
				 * Filters which inputs get autocomplete.
				 *
				 * Defaults cover the block search input and the classic search
				 * form. A theme with an unusual markup can point this at its
				 * own selector rather than being left out.
				 *
				 * @since 0.5.0
				 *
				 * @param string $selector A CSS selector.
				 */
				'selector' => (string) apply_filters(
					'solrengine_instant_search_selector',
					'input[type="search"], input[name="s"], .wp-block-search__input'
				),
				'i18n'     => array(
					'noResults'    => __( 'No suggestions', 'solrengine' ),
					/* translators: %d: number of suggestions found. */
					'resultsFound' => __( '%d suggestions available', 'solrengine' ),
				),
			)
		);
	}
}
