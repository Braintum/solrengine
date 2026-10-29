<?php
/**
 * Backend label resolution tests.
 *
 * Labels are deferred because registering a backend happens on every request
 * while the label is read only by the admin screens and the CLI — and `__()`
 * must not run before `init`. That makes "registering does not resolve the
 * label" a property worth pinning.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Support\Config;

/**
 * @covers \SolrEngine\Backend\BackendRegistry
 */
final class BackendRegistryLabelTest extends TestCase {

	private function registry(): BackendRegistry {
		return new BackendRegistry( new Config() );
	}

	/**
	 * A factory that would fail the test if it were ever called.
	 *
	 * @return callable
	 */
	private function unused_factory(): callable {
		return function () {
			$this->fail( 'A backend factory was resolved while reading labels.' );
		};
	}

	public function test_a_string_label_is_returned_as_given(): void {
		$registry = $this->registry();
		$registry->register( 'solr', 'Apache Solr', $this->unused_factory(), $this->unused_factory() );

		$this->assertSame( array( 'solr' => 'Apache Solr' ), $registry->available() );
	}

	public function test_a_callable_label_is_resolved_when_read(): void {
		$registry = $this->registry();
		$registry->register(
			'solr',
			static function (): string {
				return 'Apache Solr (self-hosted)';
			},
			$this->unused_factory(),
			$this->unused_factory()
		);

		$this->assertSame( array( 'solr' => 'Apache Solr (self-hosted)' ), $registry->available() );
	}

	public function test_registering_does_not_resolve_a_callable_label(): void {
		// The point of the whole change: a process with no translation stack must
		// be able to register a backend.
		$calls    = 0;
		$registry = $this->registry();

		$registry->register(
			'solr',
			static function () use ( &$calls ): string {
				++$calls;

				return 'Apache Solr';
			},
			$this->unused_factory(),
			$this->unused_factory()
		);

		$this->assertSame( 0, $calls, 'Registering a backend resolved its label.' );

		$registry->available();

		$this->assertSame( 1, $calls );
	}

	public function test_string_and_callable_labels_coexist(): void {
		$registry = $this->registry();
		$registry->register( 'solr', 'Apache Solr', $this->unused_factory(), $this->unused_factory() );
		$registry->register(
			'memory',
			static function (): string {
				return 'In-memory';
			},
			$this->unused_factory(),
			$this->unused_factory()
		);

		$this->assertSame(
			array(
				'solr'   => 'Apache Solr',
				'memory' => 'In-memory',
			),
			$registry->available()
		);
	}

	public function test_reading_labels_never_constructs_a_backend(): void {
		$registry = $this->registry();
		$registry->register( 'solr', 'Apache Solr', $this->unused_factory(), $this->unused_factory() );
		$registry->register( 'memory', 'In-memory', $this->unused_factory(), $this->unused_factory() );

		$registry->available();

		// unused_factory() fails the test if called, so reaching here is the
		// assertion. Stated explicitly so the test does not look assertion-free.
		$this->assertTrue( true );
	}
}
