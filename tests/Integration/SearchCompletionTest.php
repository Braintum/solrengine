<?php
/**
 * The results page and its filter panel complete what the visitor typed.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Backend\Memory\MemoryBackend;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Integration\WordPress\QueryIntegration;
use SolrEngine\Integration\WordPress\SearchFilters;
use SolrEngine\Search\FacetRegistry;
use SolrEngine\Search\FilterRegistry;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\Config;
use SolrEngine\Support\NullLogger;
use WP_Query;
use WP_UnitTestCase;

/**
 * Both must ask for completion, or the panel's counts would describe a
 * different result set from the one beside them.
 *
 * @covers \SolrEngine\Integration\WordPress\QueryIntegration
 * @covers \SolrEngine\Integration\WordPress\SearchFilters
 */
final class SearchCompletionTest extends WP_UnitTestCase {

	/**
	 * Queries the search service was asked to run.
	 *
	 * @var SearchQuery[]
	 */
	private $seen = array();

	public function set_up(): void {
		parent::set_up();

		$this->seen = array();

		add_filter(
			'solrengine_search_query',
			function ( SearchQuery $query ): SearchQuery {
				$this->seen[] = $query;

				return $query;
			}
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'solrengine_search_query' );
		unset( $_GET['s'] );

		parent::tear_down();
	}

	public function test_the_results_page_completes_the_last_word(): void {
		$integration = new QueryIntegration( $this->service(), $this->config(), 1 );

		$query = new WP_Query();
		$query->set( 's', 'hoo' );
		$query->set( 'solrengine_handled', true );

		$integration->maybe_substitute( null, $query );

		$this->assertCount( 1, $this->seen );
		$this->assertSame( 'hoo', $this->seen[0]->terms() );
		$this->assertTrue( $this->seen[0]->completes_last_word() );
	}

	public function test_the_filter_panel_counts_with_completion_too(): void {
		$_GET['s'] = 'hoo';

		$filters = new SearchFilters( $this->service(), $this->config(), FacetRegistry::defaults(), FilterRegistry::defaults(), 1 );
		$filters->shortcode( array() );

		$this->assertNotEmpty( $this->seen );

		foreach ( $this->seen as $query ) {
			$this->assertSame( 'hoo', $query->terms() );
			$this->assertTrue( $query->completes_last_word() );
		}
	}

	/**
	 * A search service over the in-memory backend.
	 *
	 * @return SearchService
	 */
	private function service(): SearchService {
		return new SearchService( new MemoryBackend( FilterRegistry::defaults(), FacetRegistry::defaults() ), new NullLogger(), 0 );
	}

	/**
	 * Configuration with search switched on.
	 *
	 * @return Config
	 */
	private function config(): Config {
		return new Config(
			array(
				'search.enabled'   => true,
				'index.post_types' => array( 'post', 'page' ),
			)
		);
	}
}
