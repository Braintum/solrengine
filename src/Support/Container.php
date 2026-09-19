<?php
/**
 * Service container.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use RuntimeException;

/**
 * Lazily constructs and shares services.
 *
 * Used only by the composition root. Services receive their dependencies
 * through their constructors and never see this class, because a class that
 * pulls collaborators out of a container has hidden its dependencies rather
 * than declared them. The 0.1.0 prototype passed the container into
 * SettingsPage and SearchController, which is why neither could be
 * instantiated in a test.
 *
 * Deliberately tiny: bindings, singletons, and a clear error when something is
 * missing. Anything more is a framework.
 *
 * @since 0.1.0
 */
final class Container {

	/**
	 * Factories keyed by service identifier.
	 *
	 * @var array<string,callable>
	 */
	private $factories = array();

	/**
	 * Resolved shared instances keyed by service identifier.
	 *
	 * @var array<string,mixed>
	 */
	private $resolved = array();

	/**
	 * Identifiers currently being resolved, used to detect cycles.
	 *
	 * @var array<string,bool>
	 */
	private $resolving = array();

	/**
	 * Register a shared service.
	 *
	 * The factory runs at most once, on first resolution. Registering does not
	 * construct anything, which is what keeps a page load that never touches
	 * SolrEngine free of SolrEngine's cost.
	 *
	 * @since 0.1.0
	 *
	 * @param string   $id      Service identifier.
	 * @param callable $factory Receives this container, returns the service.
	 * @return void
	 */
	public function share( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
		unset( $this->resolved[ $id ] );
	}

	/**
	 * Register an already-constructed value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id    Service identifier.
	 * @param mixed  $value The value.
	 * @return void
	 */
	public function instance( string $id, $value ): void {
		$this->resolved[ $id ] = $value;
	}

	/**
	 * Resolve a service.
	 *
	 * Throws rather than returning null for an unknown identifier. A container
	 * that silently returns null converts a wiring mistake into a null
	 * dereference somewhere unrelated, which is exactly how the prototype's
	 * settings page failed silently.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service identifier.
	 * @return mixed
	 * @throws RuntimeException When the service is unknown or circular.
	 */
	public function get( string $id ) {
		if ( array_key_exists( $id, $this->resolved ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new RuntimeException(
				sprintf( 'SolrEngine: no service registered for "%s".', $id )
			);
		}

		if ( isset( $this->resolving[ $id ] ) ) {
			throw new RuntimeException(
				sprintf( 'SolrEngine: circular dependency while resolving "%s".', $id )
			);
		}

		$this->resolving[ $id ] = true;

		try {
			$service = ( $this->factories[ $id ] )( $this );
		} finally {
			unset( $this->resolving[ $id ] );
		}

		$this->resolved[ $id ] = $service;

		return $service;
	}

	/**
	 * Whether a service is registered.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] ) || array_key_exists( $id, $this->resolved );
	}

	/**
	 * Whether a service has actually been constructed.
	 *
	 * Used by tests to assert that a code path stayed lazy.
	 *
	 * @since 0.1.0
	 *
	 * @param string $id Service identifier.
	 * @return bool
	 */
	public function is_resolved( string $id ): bool {
		return array_key_exists( $id, $this->resolved );
	}
}
