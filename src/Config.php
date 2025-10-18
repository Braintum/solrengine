<?php
/**
 * Class Config
 *
 * Simple configuration holder with sensible defaults and overrides.
 *
 * @package SolrEngine
 */

namespace SolrEngine;

/**
 * Class Config
 *
 * Simple configuration holder with sensible defaults and overrides.
 * - Default values come from the constructor.
 * - DB overrides are read from WP options via loadFromWpOptions().
 * - Environment variable overrides (optional) are read from getenv().
 */
class Config {

	/**
	 * Configuration data.
	 *
	 * @var array
	 */
	protected array $data = array();

	/**
	 * Constructor accepts an array of defaults.
	 *
	 * @param array<string,mixed> $defaults Array of default config values.
	 */
	public function __construct( array $defaults = array() ) {
		$this->data = $defaults;
	}

	/**
	 * Get a value from config with optional default.
	 *
	 * @param string $key     Dot-notated key supported (e.g. 'solr.endpoint').
	 * @param mixed  $default Optional. Default value if key not found.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		if ( '' === $key ) {
			return $this->data;
		}
		$parts = explode( '.', $key );
		$ref   = $this->data;
		foreach ( $parts as $p ) {
			if ( ! is_array( $ref ) || ! array_key_exists( $p, $ref ) ) {
				return $default;
			}
			$ref = $ref[ $p ];
		}
		return $ref;
	}

	/**
	 * Set a value in config (supports dot notation).
	 *
	 * @param string $key   Config key.
	 * @param mixed  $value Value to set.
	 * @return void
	 */
	public function set( string $key, $value ): void {
		$parts = explode( '.', $key );
		$ref   =& $this->data;
		$numParts = count( $parts );
		while ( $numParts > 1 ) {
			$p = array_shift( $parts );
			if ( ! isset( $ref[ $p ] ) || ! is_array( $ref[ $p ] ) ) {
				$ref[ $p ] = array();
			}
			$ref =& $ref[ $p ];
			$numParts = count( $parts );
		}
		$ref[ array_shift( $parts ) ] = $value;
	}

	/**
	 * Merge an array of config values into existing config.
	 *
	 * @param array $values Values to merge.
	 * @return void
	 */
	public function merge( array $values ): void {
		$this->data = $this->arrayMergeRecursiveDistinct( $this->data, $values );
	}

	/**
	 * Load overrides from WP options. Will only set keys present in $map.
	 *
	 * Example $map: ['solr.endpoint' => 'solrengine_solr_endpoint']
	 *
	 * @param array<string,string> $map Map of config keys to WP option names.
	 * @return void
	 */
	public function loadFromWpOptions( array $map ): void {
		foreach ( $map as $key => $option_name ) {
			$val = get_option( $option_name, null );
			if ( null !== $val ) {
				$this->set( $key, $val );
			}
		}
	}

	/**
	 * Load overrides from environment variables (optional).
	 * Example $map: ['solr.endpoint' => 'WPSOLR_SOLR_ENDPOINT']
	 *
	 * @param array<string,string> $map Map of config keys to env variable names.
	 * @return void
	 */
	public function loadFromEnv( array $map ): void {
		foreach ( $map as $key => $env ) {
			$val = getenv( $env );
			if ( false !== $val && null !== $val ) {
				$this->set( $key, $val );
			}
		}
	}

	/**
	 * Return the whole config array.
	 *
	 * @return array
	 */
	public function all(): array {
		return $this->data;
	}

	/**
	 * Helper: deep merge arrays but prefer second array values over first.
	 *
	 * @param array $array1 First array.
	 * @param array $array2 Second array.
	 * @return array
	 */
	protected function arrayMergeRecursiveDistinct( array $array1, array $array2 ): array {
		$merged = $array1;
		foreach ( $array2 as $key => $value ) {
			if ( is_array( $value ) && isset( $merged[ $key ] ) && is_array( $merged[ $key ] ) ) {
				$merged[ $key ] = $this->arrayMergeRecursiveDistinct( $merged[ $key ], $value );
			} else {
				$merged[ $key ] = $value;
			}
		}
		return $merged;
	}
}
