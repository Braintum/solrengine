<?php
/**
 * Search popup tests: templates, markup and the REST route.
 *
 * Here rather than in the unit suite because each of these is about WordPress:
 * where a theme's files are, what a page prints, what the REST server answers.
 * What the popup *shows* is decided by InstantResults and tested without
 * WordPress in tests/Unit/InstantResultsTest.php.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Integration\WordPress\SearchModal;
use SolrEngine\Integration\WordPress\Templates;
use SolrEngine\Support\Config;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Integration\WordPress\Templates
 * @covers \SolrEngine\Integration\WordPress\SearchModal
 * @covers \SolrEngine\Rest\InstantResultsController
 */
final class SearchModalTest extends WP_UnitTestCase {

	/**
	 * A throwaway theme directory.
	 *
	 * @var string
	 */
	private $theme = '';

	public function set_up(): void {
		parent::set_up();

		$this->theme = sys_get_temp_dir() . '/solrengine-theme-' . wp_generate_password( 8, false );
		wp_mkdir_p( $this->theme . '/solrengine/search-modal' );

		$theme = $this->theme;
		$point = static function () use ( $theme ): string {
			return $theme;
		};

		add_filter( 'stylesheet_directory', $point );
		add_filter( 'template_directory', $point );
	}

	public function tear_down(): void {
		foreach ( array( 'search-modal/result.php', 'search-modal/result-post.php', 'search-modal.php' ) as $file ) {
			if ( file_exists( $this->theme . '/solrengine/' . $file ) ) {
				unlink( $this->theme . '/solrengine/' . $file );
			}
		}

		rmdir( $this->theme . '/solrengine/search-modal' );
		rmdir( $this->theme . '/solrengine' );
		rmdir( $this->theme );

		wp_dequeue_script( SearchModal::HANDLE );
		wp_deregister_script( SearchModal::HANDLE );
		wp_dequeue_style( SearchModal::HANDLE );
		wp_deregister_style( SearchModal::HANDLE );
		remove_all_filters( 'solrengine_search_modal_width' );
		remove_all_filters( 'wp_theme_json_data_theme' );
		unset( $GLOBALS['content_width'] );

		parent::tear_down();
	}

	// -- Templates -----------------------------------------------------------

	public function test_bundled_template_is_found(): void {
		$this->assertSame(
			SOLRENGINE_PLUGIN_DIR . 'templates/search-modal/result.php',
			Templates::locate( 'search-modal/result.php' )
		);
	}

	public function test_theme_copy_wins_over_the_bundled_one(): void {
		file_put_contents( $this->theme . '/solrengine/search-modal/result.php', '<li>theme</li>' );

		$this->assertSame(
			$this->theme . '/solrengine/search-modal/result.php',
			Templates::locate( 'search-modal/result.php' )
		);
	}

	public function test_names_outside_the_pattern_are_refused(): void {
		foreach ( array( '../wp-config.php', 'search-modal/../../x.php', '/etc/passwd', 'Result.php', 'result.txt', '' ) as $name ) {
			$this->assertSame( '', Templates::locate( $name ), $name );
		}
	}

	public function test_filter_can_supply_or_suppress_a_template(): void {
		add_filter( 'solrengine_template', '__return_empty_string' );

		$this->assertSame( '', Templates::locate( 'search-modal.php' ) );

		remove_filter( 'solrengine_template', '__return_empty_string' );
	}

	public function test_render_passes_args(): void {
		file_put_contents(
			$this->theme . '/solrengine/search-modal/result.php',
			'<?php echo esc_html( $args["post_type"] );'
		);

		$this->assertSame( 'page', Templates::render( 'search-modal/result.php', array( 'post_type' => 'page' ) ) );
	}

	// -- Markup --------------------------------------------------------------

	public function test_nothing_is_printed_unless_the_script_is_enqueued(): void {
		$this->assertSame( '', $this->markup() );
	}

	public function test_dialog_and_card_templates_are_printed(): void {
		wp_register_script( SearchModal::HANDLE, 'https://example.com/x.js', array(), '1', true );
		wp_enqueue_script( SearchModal::HANDLE );

		file_put_contents( $this->theme . '/solrengine/search-modal/result-post.php', '<li role="option">post card</li>' );

		$html = $this->markup();

		$this->assertStringContainsString( 'data-solrengine-modal', $html );
		$this->assertStringContainsString( 'data-solrengine-template="result" data-solrengine-post-type=""', $html );
		$this->assertStringContainsString( 'data-solrengine-post-type="post"><li role="option">post card</li>', $html );
		// A page has no card of its own, so it uses the default.
		$this->assertStringNotContainsString( 'data-solrengine-post-type="page"', $html );
		// The parts the script pages and groups with.
		$this->assertStringContainsString( 'data-solrengine-template="section"', $html );
		$this->assertStringContainsString( 'data-solrengine-section-results', $html );
		$this->assertStringContainsString( 'data-solrengine-see-all', $html );
		$this->assertStringContainsString( 'data-solrengine-more', $html );
	}

	public function test_the_bundled_markup_uses_links_not_a_listbox(): void {
		wp_register_script( SearchModal::HANDLE, 'https://example.com/x.js', array(), '1', true );
		wp_enqueue_script( SearchModal::HANDLE );

		$html = $this->markup();

		// A card is a link to a page. A listbox option may not contain one,
		// so the results are lists of ordinary links that Tab reaches.
		$this->assertStringNotContainsString( 'role="listbox"', $html );
		$this->assertStringNotContainsString( 'role="option"', $html );
		$this->assertStringNotContainsString( 'tabindex="-1" data-solrengine-href', $html );
		$this->assertStringContainsString( 'role="list" data-solrengine-section-results', $html );

		// The field says how to reach the results without a mouse.
		$this->assertStringContainsString( 'aria-describedby="solrengine-modal-hint"', $html );
		$this->assertStringContainsString( 'id="solrengine-modal-hint" data-solrengine-hint', $html );
	}

	public function test_credit_is_off_unless_a_site_opts_in(): void {
		wp_register_script( SearchModal::HANDLE, 'https://example.com/x.js', array(), '1', true );
		wp_enqueue_script( SearchModal::HANDLE );

		$this->assertStringNotContainsString( 'Powered by', $this->markup() );

		$opt_in = static function ( array $args ): array {
			$args['show_credit'] = true;
			$args['heading']     = 'Find <things>';

			return $args;
		};

		add_filter( 'solrengine_search_modal_args', $opt_in );
		$html = $this->markup();
		remove_filter( 'solrengine_search_modal_args', $opt_in );

		$this->assertStringContainsString( 'Powered by SolrEngine', $html );
		$this->assertStringContainsString( 'Find &lt;things&gt;', $html );
	}

	// -- Width ---------------------------------------------------------------

	public function test_width_is_the_declared_content_width_not_a_narrowed_one(): void {
		$modal = $this->modal();

		$GLOBALS['content_width'] = 1200;
		$modal->remember_content_width();
		// What GeneratePress does on `wp` for a page with a sidebar.
		$GLOBALS['content_width'] = 900;

		$this->assertSame( '1200px', $modal->content_width() );
	}

	public function test_width_falls_back_to_the_global_when_nothing_was_declared_in_time(): void {
		$GLOBALS['content_width'] = 960;

		$this->assertSame( '960px', $this->modal()->content_width() );
	}

	public function test_a_block_themes_wide_size_wins(): void {
		$this->theme_layout(
			array(
				'wideSize'    => '1340px',
				'contentSize' => '720px',
			)
		);
		$GLOBALS['content_width'] = 900;

		$this->assertSame( '1340px', $this->modal()->content_width() );
	}

	public function test_a_theme_json_value_that_is_not_a_length_is_ignored(): void {
		$this->theme_layout( array( 'wideSize' => '1px;}body{display:none' ) );
		$GLOBALS['content_width'] = 900;

		$this->assertSame( '900px', $this->modal()->content_width() );
	}

	public function test_width_filter_can_set_the_width_and_is_validated(): void {
		$width = 'min(100%, 80rem)';
		$set   = static function () use ( &$width ): string {
			return $width;
		};

		add_filter( 'solrengine_search_modal_width', $set );

		$this->assertSame( 'min(100%, 80rem)', $this->modal()->content_width() );

		foreach ( array( '1px;}body{display:none', 'url(x)', 'red', '' ) as $width ) {
			$this->assertSame( '', $this->modal()->content_width(), $width );
		}
	}

	public function test_width_is_printed_beside_the_stylesheet(): void {
		$GLOBALS['content_width'] = 1200;

		$this->modal()->enqueue();

		$after = wp_styles()->get_data( SearchModal::HANDLE, 'after' );

		$this->assertIsArray( $after );
		$this->assertContains( '.solrengine-modal{--solrengine-content-width:1200px;}', $after );
	}

	// -- REST ----------------------------------------------------------------

	public function test_route_is_registered(): void {
		$this->assertArrayHasKey( '/solrengine/v1/instant', rest_get_server()->get_routes() );
	}

	public function test_short_text_answers_without_a_backend(): void {
		$request = new WP_REST_Request( 'GET', '/solrengine/v1/instant' );
		$request->set_param( 'q', 'a' );
		$request->set_param( 'post_type', 'post' );

		$response = rest_do_request( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'post', $data['post_type'] );
		$this->assertSame( array(), $data['results'] );
		$this->assertSame( 1, $data['page'] );
		$this->assertFalse( $data['has_more'] );
	}

	public function test_a_page_below_one_is_the_first_page(): void {
		$request = new WP_REST_Request( 'GET', '/solrengine/v1/instant' );
		$request->set_param( 'q', 'a' );
		$request->set_param( 'post_type', 'post' );
		$request->set_param( 'page', 0 );

		$response = rest_do_request( $request );

		// Clamped, not refused: the fast path clamps it too.
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 1, $response->get_data()['page'] );
	}

	public function test_overlong_text_is_refused(): void {
		$request = new WP_REST_Request( 'GET', '/solrengine/v1/instant' );
		$request->set_param( 'q', str_repeat( 'x', 201 ) );

		$this->assertSame( 400, rest_do_request( $request )->get_status() );
	}

	/**
	 * What the footer hook prints.
	 *
	 * @return string
	 */
	private function markup(): string {
		ob_start();
		$this->modal()->print_markup();

		return (string) ob_get_clean();
	}

	/**
	 * The popup, for posts and pages.
	 *
	 * @return SearchModal
	 */
	private function modal(): SearchModal {
		return new SearchModal( new Config( array( 'index.post_types' => array( 'post', 'page' ) ) ) );
	}

	/**
	 * Give the active theme's theme.json these layout settings.
	 *
	 * @param array<string,string> $layout Layout settings.
	 * @return void
	 */
	private function theme_layout( array $layout ): void {
		add_filter(
			'wp_theme_json_data_theme',
			static function ( $data ) use ( $layout ) {
				return $data->update_with(
					array(
						'version'  => 2,
						'settings' => array( 'layout' => $layout ),
					)
				);
			}
		);

		wp_clean_theme_json_cache();
	}
}
