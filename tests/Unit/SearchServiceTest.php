<?php
/**
 * SearchService tests.
 *
 * The point of these is as much architectural as behavioural: this suite loads no
 * WordPress, so the file existing at all is the proof that the service no longer
 * reaches for `is_user_logged_in()`, `wp_cache_get()` or `get_option()`. That is
 * also what lets the instant-search entry point run the very same service in a
 * SHORTINIT process.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\Breaker;
use SolrEngine\Contracts\Cache;
use SolrEngine\Contracts\DocumentRef;
use SolrEngine\Contracts\Result;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Contracts\SearchQuery;
use SolrEngine\Contracts\Suggestion;
use SolrEngine\Contracts\Suggestions;
use SolrEngine\Contracts\SuggestQuery;
use SolrEngine\Contracts\Viewer;
use SolrEngine\Search\SearchService;
use SolrEngine\Support\NullLogger;

/**
 * @covers \SolrEngine\Search\SearchService
 */
final class SearchServiceTest extends TestCase {

	/**
	 * Build a service over test doubles.
	 *
	 * @param SearchBackend|null $backend Backend, or null for none.
	 * @param Viewer|null        $viewer  Viewer.
	 * @param Cache|null         $cache   Cache.
	 * @param Breaker|null       $breaker Breaker.
	 * @param int                $ttl     Cache TTL.
	 * @return SearchService
	 */
	private function service(
		?SearchBackend $backend,
		?Viewer $viewer = null,
		?Cache $cache = null,
		?Breaker $breaker = null,
		int $ttl = 300
	): SearchService {
		return new SearchService(
			$backend,
			new NullLogger(),
			$ttl,
			$viewer ?? $this->viewer( false ),
			$cache ?? $this->cache(),
			$breaker ?? $this->breaker( false )
		);
	}

	/**
	 * A viewer with a fixed answer.
	 *
	 * @param bool $authenticated What it reports.
	 * @return Viewer
	 */
	private function viewer( bool $authenticated ): Viewer {
		return new class( $authenticated ) implements Viewer {
			/**
			 * @var bool
			 */
			private $authenticated;

			/**
			 * @param bool $authenticated What to report.
			 */
			public function __construct( bool $authenticated ) {
				$this->authenticated = $authenticated;
			}

			/**
			 * @return bool
			 */
			public function is_authenticated(): bool {
				return $this->authenticated;
			}
		};
	}

	/**
	 * An in-memory cache that records what it was asked to do.
	 *
	 * @return Cache
	 */
	private function cache(): Cache {
		return new class() implements Cache {
			/**
			 * @var array<string,mixed>
			 */
			public $entries = array();

			/**
			 * @var int
			 */
			public $writes = 0;

			/**
			 * @param string $key Key.
			 * @return mixed
			 */
			public function get( string $key ) {
				return $this->entries[ $key ] ?? null;
			}

			/**
			 * @param string $key   Key.
			 * @param mixed  $value Value.
			 * @param int    $ttl   TTL.
			 * @return void
			 */
			public function set( string $key, $value, int $ttl ): void {
				++$this->writes;

				$this->entries[ $key ] = $value;
			}
		};
	}

	/**
	 * A breaker that records what it was told.
	 *
	 * @param bool $open Whether it reports itself open.
	 * @return Breaker
	 */
	private function breaker( bool $open ) {
		return new class( $open ) implements Breaker {
			/**
			 * @var bool
			 */
			private $open;

			/**
			 * @var string[]
			 */
			public $failures = array();

			/**
			 * @var int
			 */
			public $successes = 0;

			/**
			 * @param bool $open Whether open.
			 */
			public function __construct( bool $open ) {
				$this->open = $open;
			}

			/**
			 * @return bool
			 */
			public function is_open(): bool {
				return $this->open;
			}

			/**
			 * @param string $code Code.
			 * @return void
			 */
			public function record_failure( string $code ): void {
				$this->failures[] = $code;
			}

			/**
			 * @return void
			 */
			public function record_success(): void {
				++$this->successes;
			}
		};
	}

	/**
	 * A backend answering suggest with a fixed result.
	 *
	 * @param Result $result What suggest() returns.
	 * @return SearchBackend
	 */
	private function backend( Result $result ): SearchBackend {
		return new class( $result ) implements SearchBackend {
			/**
			 * @var Result
			 */
			private $result;

			/**
			 * @var int
			 */
			public $calls = 0;

			/**
			 * @param Result $result Result to return.
			 */
			public function __construct( Result $result ) {
				$this->result = $result;
			}

			/**
			 * @return string
			 */
			public function id(): string {
				return 'fake';
			}

			/**
			 * @return Result
			 */
			public function ping(): Result {
				return Result::success();
			}

			/**
			 * @return Result
			 */
			public function status(): Result {
				return Result::success();
			}

			/**
			 * @return \SolrEngine\Contracts\Capabilities
			 */
			public function capabilities(): \SolrEngine\Contracts\Capabilities {
				return new \SolrEngine\Contracts\Capabilities();
			}

			/**
			 * @param SearchQuery $query Query.
			 * @return Result
			 */
			public function search( SearchQuery $query ): Result {
				++$this->calls;

				return $this->result;
			}

			/**
			 * @param SuggestQuery $query Query.
			 * @return Result
			 */
			public function suggest( SuggestQuery $query ): Result {
				++$this->calls;

				return $this->result;
			}
		};
	}

	/**
	 * One suggestion set.
	 *
	 * @return Suggestions
	 */
	private function suggestions(): Suggestions {
		return new Suggestions(
			array( new Suggestion( 'MacBook', '/mb/', new DocumentRef( 1, 'post', 7 ), 'product' ) ),
			'macb',
			4
		);
	}

	// -- No backend ---------------------------------------------------------

	public function test_without_a_backend_it_reports_unavailable(): void {
		$this->assertFalse( $this->service( null )->is_available() );
	}

	public function test_without_a_backend_suggest_fails_rather_than_throwing(): void {
		$result = $this->service( null )->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'no_backend', $result->code() );
	}

	// -- The short-prefix guard ---------------------------------------------

	public function test_a_short_prefix_is_answered_without_touching_the_backend(): void {
		$backend = $this->backend( Result::success( $this->suggestions() ) );
		$result  = $this->service( $backend )->suggest( new SuggestQuery( 'm', 1 ) );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( array(), $result->value()->items() );
		$this->assertSame( 0, $backend->calls, 'A one-character prefix reached the backend.' );
	}

	// -- The breaker --------------------------------------------------------

	public function test_an_open_breaker_short_circuits_the_backend(): void {
		$backend = $this->backend( Result::success( $this->suggestions() ) );
		$result  = $this->service( $backend, null, null, $this->breaker( true ) )
			->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'circuit_open', $result->code() );
		$this->assertSame( 0, $backend->calls );
	}

	public function test_a_backend_failure_is_recorded(): void {
		$breaker = $this->breaker( false );
		$backend = $this->backend( Result::failure( 'timeout', 'Too slow.' ) );

		$this->service( $backend, null, null, $breaker )->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( array( 'timeout' ), $breaker->failures );
		$this->assertSame( 0, $breaker->successes );
	}

	public function test_a_backend_success_is_recorded(): void {
		$breaker = $this->breaker( false );
		$backend = $this->backend( Result::success( $this->suggestions() ) );

		$this->service( $backend, null, null, $breaker )->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( 1, $breaker->successes );
		$this->assertSame( array(), $breaker->failures );
	}

	// -- Caching and who may share it ---------------------------------------

	public function test_suggestions_are_cached_for_an_anonymous_visitor(): void {
		$cache   = $this->cache();
		$backend = $this->backend( Result::success( $this->suggestions() ) );

		$this->service( $backend, $this->viewer( false ), $cache )
			->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( 1, $cache->writes );
	}

	public function test_a_cached_entry_is_served_without_the_backend(): void {
		$cache   = $this->cache();
		$backend = $this->backend( Result::success( $this->suggestions() ) );
		$service = $this->service( $backend, $this->viewer( false ), $cache );
		$query   = new SuggestQuery( 'macb', 1 );

		$service->suggest( $query );
		$service->suggest( $query );

		$this->assertSame( 1, $backend->calls, 'The second identical query reached the backend.' );
	}

	public function test_nothing_is_cached_for_a_signed_in_user(): void {
		// They can see drafts and private posts a visitor cannot, and one shared
		// entry would serve one of them the other's view.
		$cache   = $this->cache();
		$backend = $this->backend( Result::success( $this->suggestions() ) );

		$this->service( $backend, $this->viewer( true ), $cache )
			->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( 0, $cache->writes );
	}

	public function test_a_signed_in_user_is_not_served_another_visitors_cache(): void {
		$cache   = $this->cache();
		$backend = $this->backend( Result::success( $this->suggestions() ) );
		$query   = new SuggestQuery( 'macb', 1 );

		// A visitor fills the cache.
		$this->service( $backend, $this->viewer( false ), $cache )->suggest( $query );

		// A signed-in user asks the same thing and must reach the backend.
		$this->service( $backend, $this->viewer( true ), $cache )->suggest( $query );

		$this->assertSame( 2, $backend->calls );
	}

	public function test_a_zero_ttl_disables_caching(): void {
		$cache   = $this->cache();
		$backend = $this->backend( Result::success( $this->suggestions() ) );

		$this->service( $backend, $this->viewer( false ), $cache, null, 0 )
			->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( 0, $cache->writes );
	}

	public function test_a_failure_is_never_cached(): void {
		$cache   = $this->cache();
		$backend = $this->backend( Result::failure( 'timeout', 'Too slow.' ) );

		$this->service( $backend, $this->viewer( false ), $cache )
			->suggest( new SuggestQuery( 'macb', 1 ) );

		$this->assertSame( 0, $cache->writes );
	}
}
