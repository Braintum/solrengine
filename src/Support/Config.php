<?php
/**
 * Configuration reader.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

/**
 * Reads configuration from defaults, stored options and PHP constants.
 *
 * Precedence, lowest to highest: packaged defaults, the stored option, then a
 * PHP constant. Constants win because they live in wp-config.php, which is
 * where an operator pins configuration that a compromised or careless admin
 * session must not be able to change.
 *
 * Two deliberate departures from the 0.1.0 prototype:
 *
 * - It reads the same option the settings screen writes. The prototype read
 *   `solrengine_solr_endpoint` while the settings page wrote
 *   `solrengine_settings`, so saving the endpoint had no effect at all.
 * - It reads no environment variables. The prototype honoured
 *   `WPSOLR_SOLR_ENDPOINT`, a competing plugin's namespace, and getenv() is an
 *   unauditable input on shared hosting. Constants are visible in the file the
 *   operator already owns.
 *
 * Credentials are NOT handled here. See Credentials.
 *
 * @since 0.1.0
 */
final class Config {

	/**
	 * Option name holding connection configuration. Never autoloaded.
	 *
	 * @var string
	 */
	public const OPTION_CONNECTION = 'solrengine_connection';

	/**
	 * Option name holding index configuration. Never autoloaded.
	 *
	 * @var string
	 */
	public const OPTION_INDEX = 'solrengine_index_config';

	/**
	 * Option name holding runtime state. Never autoloaded.
	 *
	 * @var string
	 */
	public const OPTION_STATE = 'solrengine_state';

	/**
	 * Packaged defaults, keyed by dot path.
	 *
	 * @var array<string,mixed>
	 */
	private $defaults;

	/**
	 * Constants that override a dot path, keyed by dot path.
	 *
	 * @var array<string,string>
	 */
	private $constants;

	/**
	 * Per-request memo of loaded option groups.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $loaded = array();

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>  $defaults  Overrides for the packaged defaults.
	 * @param array<string,string> $constants Overrides for the constant map.
	 */
	public function __construct( array $defaults = array(), array $constants = array() ) {
		$this->defaults = array_merge(
			array(
				'connection.endpoint'           => '',
				'connection.collection'         => '',
				'connection.auth_mode'          => 'none',
				'connection.username'           => '',
				'connection.connect_timeout'    => 3,
				'connection.read_timeout'       => 10,
				'connection.allow_private_ip'   => false,
				'connection.verify_tls'         => true,

				'index.batch_size'              => 100,
				'index.commit_within_ms'        => 60000,
				'index.delete_commit_within_ms' => 5000,
				'index.post_types'              => array( 'post', 'page' ),
				'index.meta_allowlist'          => array(),

				'search.enabled'                => false,
				'search.max_rows'               => 50,
				'search.deep_paging_limit'      => 1000,

				'runtime.debug'                 => false,
			),
			$defaults
		);

		$this->constants = array_merge(
			array(
				'connection.endpoint'   => 'SOLRENGINE_SOLR_ENDPOINT',
				'connection.collection' => 'SOLRENGINE_SOLR_COLLECTION',
				'runtime.debug'         => 'SOLRENGINE_DEBUG',
			),
			$constants
		);
	}

	/**
	 * Read a configuration value.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Dot path, for example "connection.endpoint".
	 * @param mixed  $fallback Returned when nothing is configured and no default
	 *                         exists.
	 * @return mixed
	 */
	public function get( string $path, $fallback = null ) {
		if ( isset( $this->constants[ $path ] ) && defined( $this->constants[ $path ] ) ) {
			return constant( $this->constants[ $path ] );
		}

		$stored = $this->stored_value( $path );

		if ( null !== $stored ) {
			return $stored;
		}

		if ( array_key_exists( $path, $this->defaults ) ) {
			return $this->defaults[ $path ];
		}

		return $fallback;
	}

	/**
	 * Read a value as an integer.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Dot path.
	 * @param int    $fallback Fallback value.
	 * @return int
	 */
	public function get_int( string $path, int $fallback = 0 ): int {
		$value = $this->get( $path, $fallback );

		return is_numeric( $value ) ? (int) $value : $fallback;
	}

	/**
	 * Read a value as a boolean.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Dot path.
	 * @param bool   $fallback Fallback value.
	 * @return bool
	 */
	public function get_bool( string $path, bool $fallback = false ): bool {
		$value = $this->get( $path, $fallback );

		if ( is_bool( $value ) ) {
			return $value;
		}

		return in_array( $value, array( 1, '1', 'true', 'yes', 'on' ), true );
	}

	/**
	 * Read a value as a string.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path     Dot path.
	 * @param string $fallback Fallback value.
	 * @return string
	 */
	public function get_string( string $path, string $fallback = '' ): string {
		$value = $this->get( $path, $fallback );

		return is_scalar( $value ) ? (string) $value : $fallback;
	}

	/**
	 * Read a value as a list.
	 *
	 * @since 0.1.0
	 *
	 * @param string                  $path     Dot path.
	 * @param array<int|string,mixed> $fallback Fallback value.
	 * @return array<int|string,mixed>
	 */
	public function get_array( string $path, array $fallback = array() ): array {
		$value = $this->get( $path, $fallback );

		return is_array( $value ) ? $value : $fallback;
	}

	/**
	 * Whether a value is pinned by a PHP constant and therefore not editable
	 * from the admin screens.
	 *
	 * The settings UI uses this to disable a field and explain why, rather than
	 * accepting input it will silently ignore.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path Dot path.
	 * @return bool
	 */
	public function is_locked( string $path ): bool {
		return isset( $this->constants[ $path ] ) && defined( $this->constants[ $path ] );
	}

	/**
	 * Persist a value.
	 *
	 * Writing a path that a constant pins is a no-op that reports failure,
	 * because silently discarding a save is how the prototype's settings screen
	 * misled its users.
	 *
	 * @since 0.1.0
	 *
	 * @param string $path  Dot path.
	 * @param mixed  $value Value to store.
	 * @return bool Whether the value was stored.
	 */
	public function set( string $path, $value ): bool {
		if ( $this->is_locked( $path ) ) {
			return false;
		}

		$option = $this->option_for( $path );

		if ( '' === $option ) {
			return false;
		}

		$group = $this->load( $option );
		$key   = $this->key_for( $path );

		$group[ $key ] = $value;

		$this->loaded[ $option ] = $group;

		return update_option( $option, $group, false );
	}

	/**
	 * Forget everything memoised this request.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->loaded = array();
	}

	/**
	 * Read a stored value, or null when absent.
	 *
	 * @param string $path Dot path.
	 * @return mixed|null
	 */
	private function stored_value( string $path ) {
		$option = $this->option_for( $path );

		if ( '' === $option ) {
			return null;
		}

		$group = $this->load( $option );
		$key   = $this->key_for( $path );

		return array_key_exists( $key, $group ) ? $group[ $key ] : null;
	}

	/**
	 * Load and memoise one option group.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private function load( string $option ): array {
		if ( ! isset( $this->loaded[ $option ] ) ) {
			$value                   = get_option( $option, array() );
			$this->loaded[ $option ] = is_array( $value ) ? $value : array();
		}

		return $this->loaded[ $option ];
	}

	/**
	 * Map a dot path to the option group that stores it.
	 *
	 * Configuration is split across several options rather than one, so that a
	 * field map that grows with the site is not read and rewritten every time
	 * someone changes a timeout. See ADR-009.
	 *
	 * @param string $path Dot path.
	 * @return string Option name, or an empty string for a path with no store.
	 */
	private function option_for( string $path ): string {
		$prefix = strtok( $path, '.' );

		switch ( $prefix ) {
			case 'connection':
				return self::OPTION_CONNECTION;
			case 'index':
			case 'search':
				return self::OPTION_INDEX;
			case 'runtime':
				return self::OPTION_STATE;
			default:
				return '';
		}
	}

	/**
	 * The key within an option group for a dot path.
	 *
	 * @param string $path Dot path.
	 * @return string
	 */
	private function key_for( string $path ): string {
		$position = strpos( $path, '.' );

		return false === $position ? $path : substr( $path, $position + 1 );
	}
}
