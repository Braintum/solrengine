<?php
/**
 * SearchController class file.
 *
 * @package SolrEngine\Front
 */

namespace SolrEngine\Front;

use SolrEngine\Container;
use SolrEngine\Solr\SolrSearcher;

/**
 * Handles the frontend search endpoint and template rendering.
 */
class SearchController {

	/**
	 * Container instance.
	 *
	 * @var Container
	 */
	protected Container $container;

	/**
	 * SolrSearcher instance.
	 *
	 * @var SolrSearcher
	 */
	protected SolrSearcher $searcher;

	/**
	 * Constructor.
	 *
	 * @param Container $container Container instance.
	 */
	public function __construct( Container $container ) {
		$this->container = $container;
		$this->searcher  = $container->get( 'searcher' );
	}

	/**
	 * Register hooks.
	 */
	public function register(): void {
		add_action( 'init', array( $this, 'registerQueryVar' ) );

		// Only handle search requests on frontend.
		if ( ! is_admin() ) {
			add_action( 'template_redirect', array( $this, 'handleSearchRequest' ) );
		}
	}

	/**
	 * Register custom query var and rewrite rule for search.
	 */
	public function registerQueryVar(): void {
		add_rewrite_rule( 'solrengine-search/?$', 'index.php?solrengine_search=1', 'top' );
		add_filter(
			'query_vars',
			function ( $vars ) {
				return array_merge( $vars, array( 'solrengine_search' ) );
			}
		);
	}

	/**
	 * Handle the search request and render results.
	 */
	public function handleSearchRequest(): void {
		global $wp_query;

		if ( get_query_var( 'solrengine_search' ) ) {
			$query   = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$results = array();

			if ( ! empty( $query ) ) {
				$results = $this->searcher->search(
					$query,
					array(
						'rows' => 10,
					)
				);
			}

			$this->renderResultsPage( $query, $results );
			exit;
		}
	}

	/**
	 * Render the search results page.
	 *
	 * @param string $query   Search query.
	 * @param array  $results Search results.
	 */
	protected function renderResultsPage( string $query, array $results ): void {
		get_header();
		?>
		<div class="wrap solrengine-results">
			<h1>
				<?php
				// translators: %s is replaced with the search query.
				printf(
					esc_html__( 'Search results for "%s"', 'solrengine' ), // phpcs:ignore
					esc_html( $query )
				);
				?>
			</h1>

			<?php if ( empty( $results ) ) : ?>
				<p><?php esc_html_e( 'No results found.', 'solrengine' ); ?></p>
			<?php else : ?>
				<ul class="solrengine-result-list">
					<?php foreach ( $results as $r ) : ?>
						<li>
							<a href="<?php echo esc_url( $r['url'] ?? '#' ); ?>">
								<?php echo esc_html( $r['title'] ?? '' ); ?>
							</a>
							<p><?php echo esc_html( $r['excerpt'] ?? '' ); ?></p>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		get_footer();
	}
}
