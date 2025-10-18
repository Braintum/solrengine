<?php
/**
 * Simple service container.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

/**
 * Simple service container.
 * - Stores shared services or factories (closures).
 * - If a stored value is a Closure it will be invoked with the container
 *   and the result cached (singleton behavior).
 */
class Container {

	/**
	 * Entries array.
	 *
	 * @var array<string,mixed>
	 */
	protected array $entries = array();

	/**
	 * Register a shared service or plain value.
	 *
	 * @param string $key   Key name.
	 * @param mixed  $value If Closure, treated as factory (will be executed once).
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$this->entries[ $key ] = $value;
	}

	/**
	 * Get a service or value. If Closure, invoke and replace with result.
	 *
	 * @param string $key     Key name.
	 * @param mixed  $default Default value if not found.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( ! array_key_exists( $key, $this->entries ) ) {
			return $default;
		}
		$val = $this->entries[ $key ];
		if ( $val instanceof \Closure ) {
			// Invoke factory with container as argument for nested dependencies.
			$res = $val( $this );
			$this->entries[ $key ] = $res; // Cache the instance.
			return $res;
		}
		return $val;
	}

	/**
	 * Check if a key exists.
	 *
	 * @param string $key Key name.
	 * @return bool
	 */
	public function has( string $key ): bool {
		return array_key_exists( $key, $this->entries );
	}

	/**
	 * Remove a service.
	 *
	 * @param string $key Key name.
	 * @return void
	 */
	public function remove( string $key ): void {
		unset( $this->entries[ $key ] );
	}

	/**
	 * Merge an array of entries; existing keys are overwritten.
	 *
	 * @param array<string,mixed> $entries Entries to add.
	 * @return void
	 */
	public function add( array $entries ): void {
		foreach ( $entries as $k => $v ) {
			$this->entries[ $k ] = $v;
		}
	}
}
