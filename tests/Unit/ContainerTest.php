<?php
/**
 * Container tests.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SolrEngine\Support\Container;

/**
 * @covers \SolrEngine\Support\Container
 */
final class ContainerTest extends TestCase {

	public function test_registering_does_not_construct(): void {
		$constructed = false;
		$container   = new Container();

		$container->share(
			'thing',
			static function () use ( &$constructed ) {
				$constructed = true;

				return new \stdClass();
			}
		);

		// Laziness is the point: a request that never touches SolrEngine must
		// not pay for SolrEngine's services.
		$this->assertFalse( $constructed );
		$this->assertFalse( $container->is_resolved( 'thing' ) );
	}

	public function test_shared_services_are_constructed_once(): void {
		$calls     = 0;
		$container = new Container();

		$container->share(
			'thing',
			static function () use ( &$calls ) {
				++$calls;

				return new \stdClass();
			}
		);

		$first  = $container->get( 'thing' );
		$second = $container->get( 'thing' );

		$this->assertSame( 1, $calls );
		$this->assertSame( $first, $second );
	}

	public function test_unknown_service_throws_rather_than_returning_null(): void {
		$container = new Container();

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'no service registered for "missing"' );

		$container->get( 'missing' );
	}

	public function test_circular_dependencies_are_detected(): void {
		$container = new Container();

		$container->share(
			'a',
			static function ( Container $c ) {
				return $c->get( 'b' );
			}
		);
		$container->share(
			'b',
			static function ( Container $c ) {
				return $c->get( 'a' );
			}
		);

		$this->expectException( RuntimeException::class );
		$this->expectExceptionMessage( 'circular dependency' );

		$container->get( 'a' );
	}

	public function test_a_failed_factory_does_not_leave_the_container_locked(): void {
		$container = new Container();
		$attempts  = 0;

		$container->share(
			'flaky',
			static function () use ( &$attempts ) {
				++$attempts;

				if ( 1 === $attempts ) {
					throw new RuntimeException( 'boom' );
				}

				return new \stdClass();
			}
		);

		try {
			$container->get( 'flaky' );
		} catch ( RuntimeException $e ) {
			unset( $e );
		}

		// The second attempt must not report a false circular dependency.
		$this->assertInstanceOf( \stdClass::class, $container->get( 'flaky' ) );
	}

	public function test_instances_can_be_registered_directly(): void {
		$container = new Container();
		$value     = new \stdClass();

		$container->instance( 'thing', $value );

		$this->assertTrue( $container->has( 'thing' ) );
		$this->assertSame( $value, $container->get( 'thing' ) );
	}
}
