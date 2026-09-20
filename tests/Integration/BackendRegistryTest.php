<?php
/**
 * Backend registry tests.
 *
 * The registry is public API from 1.0: the Cloud adapter, every add-on backend
 * and the migration path all bind to it. It is also the one place that decides
 * which backend answers a visitor, so "which backend is searching" is a question
 * worth having tests for.
 *
 * Here rather than in the unit suite because the registry reads configuration,
 * and configuration is stored in WordPress options. The unit suite loads no
 * WordPress on purpose: a unit test reaching for get_option() is the signal that
 * a seam has leaked, and shimming the option layer to silence it would remove the
 * signal rather than the leak. Config is the adapter to options, so a test of
 * something built on it belongs on this side of the line.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Backend\BackendRegistry;
use SolrEngine\Backend\Memory\MemoryBackend;
use SolrEngine\Contracts\IndexBackend;
use SolrEngine\Contracts\SchemaBackend;
use SolrEngine\Contracts\SearchBackend;
use SolrEngine\Support\Config;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Backend\BackendRegistry
 */
final class BackendRegistryTest extends WP_UnitTestCase {

	/**
	 * A registry with two backends and the given configuration.
	 *
	 * @param array<string,mixed> $config Configuration overrides.
	 * @return BackendRegistry
	 */
	private function registry( array $config = array() ): BackendRegistry {
		$registry = new BackendRegistry( new Config( $config ) );

		$first  = static function (): MemoryBackend {
			return new MemoryBackend();
		};
		$second = static function (): MemoryBackend {
			return new MemoryBackend();
		};

		$registry->register( 'solr', 'Solr', $first, $first );
		$registry->register( 'other', 'Other', $second, $second );

		return $registry;
	}

	public function test_the_configured_backend_is_selected(): void {
		$this->assertSame( 'other', $this->registry( array( 'connection.backend' => 'other' ) )->selected_id() );
	}

	public function test_an_unregistered_selection_degrades_to_one_that_exists(): void {
		// What happens when an add-on providing a backend is deactivated while
		// selected. Returning the stale identifier would make every call fail
		// with a confusing error instead of degrading to the backend present.
		$this->assertSame( 'solr', $this->registry( array( 'connection.backend' => 'gone' ) )->selected_id() );
	}

	public function test_searching_follows_indexing_by_default(): void {
		$registry = $this->registry( array( 'connection.backend' => 'other' ) );

		$this->assertSame( 'other', $registry->search_id() );
		$this->assertFalse( $registry->is_migrating() );
	}

	public function test_a_migration_indexes_into_one_backend_and_searches_another(): void {
		// §21.1: "a site can index to one backend while searching another during
		// a migration". This is the whole mechanism.
		$registry = $this->registry(
			array(
				'connection.backend'        => 'other',
				'connection.search_backend' => 'solr',
			)
		);

		$this->assertSame( 'other', $registry->selected_id() );
		$this->assertSame( 'solr', $registry->search_id() );
		$this->assertTrue( $registry->is_migrating() );
	}

	public function test_a_search_override_naming_nothing_is_ignored(): void {
		// A half-finished migration to a backend that has since been removed
		// must not leave the site searching nothing.
		$registry = $this->registry(
			array(
				'connection.backend'        => 'other',
				'connection.search_backend' => 'gone',
			)
		);

		$this->assertSame( 'other', $registry->search_id() );
		$this->assertFalse( $registry->is_migrating() );
	}

	public function test_the_schema_follows_the_backend_being_written_to(): void {
		// A schema is applied where documents are written. During a migration
		// those are two different places, and preparing the old backend for
		// content it is no longer receiving would be exactly backwards.
		$registry = $this->registry(
			array(
				'connection.backend'        => 'other',
				'connection.search_backend' => 'solr',
			)
		);

		$this->assertSame( $registry->index(), $registry->schema() );
		$this->assertNotSame( $registry->search(), $registry->index() );
	}

	public function test_each_role_resolves_to_something_usable(): void {
		$registry = $this->registry();

		$this->assertInstanceOf( SearchBackend::class, $registry->search() );
		$this->assertInstanceOf( IndexBackend::class, $registry->index() );
		$this->assertInstanceOf( SchemaBackend::class, $registry->schema() );
	}

	public function test_an_empty_registry_resolves_to_nothing_rather_than_failing(): void {
		$empty = new BackendRegistry( new Config() );

		$this->assertSame( '', $empty->selected_id() );
		$this->assertNull( $empty->search() );
		$this->assertNull( $empty->index() );
		$this->assertNull( $empty->schema() );
		$this->assertNull( $empty->lifecycle() );
	}
}
