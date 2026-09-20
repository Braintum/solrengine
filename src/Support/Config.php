<?php
/**
 * Configuration reader.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Multisite\NetworkConfig;

/**
 * Reads configuration from defaults, network options, stored options and PHP
 * constants.
 *
 * Precedence, lowest to highest:
 *
 * 1. Packaged defaults.
 * 2. A network value, as a default a site may override.
 * 3. The site's own stored option.
 * 4. A network value the network has **pinned**, which a site may not override.
 * 5. A PHP constant.
 *
 * Constants win outright because they live in wp-config.php, which is where an
 * operator pins configuration that a compromised or careless admin session must
 * not be able to change. A network pin sits just below, for the same reason one
 * level down: a network administrator with one Solr cluster does not want site 87
 * pointed somewhere else.
 *
 * The same network value therefore appears twice in that list, at the bottom as
 * a default and near the top as a pin. That is deliberate rather than clever:
 * one network setting, and the network decides whether it is advice or law.
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
	 * Network configuration, or null on a single site.
	 *
	 * @var NetworkConfig|null
	 */
	private $network;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>  $defaults  Overrides for the packaged defaults.
	 * @param array<string,string> $constants Overrides for the constant map.
	 * @param NetworkConfig|null   $network   Network configuration.
	 */
	public function __construct( array $defaults = array(), array $constants = array(), ?NetworkConfig $network = null ) {
		$this->network = $network;

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
				'connection.backend'            => 'solr',
				'connection.allowed_ports'      => array( 80, 443, 8443, 8983 ),

				'index.batch_size'              => 100,
				'index.commit_within_ms'        => 60000,
				'index.delete_commit_within_ms' => 5000,
				'index.post_types'              => array( 'post', 'page' ),
				'index.meta_allowlist'          => array(),

				// WooCommerce. Products are indexed as soon as the plugin is
				// there, because a shop that installed a search plugin wants
				// its products searchable; answering the shop's own queries
				// from Solr is the deliberate step, and stays off until asked.
				'index.products'                => true,
				'index.stock_quantity'          => false,
				'index.low_stock_threshold'     => 2,
				'index.variation_limit'         => 100,

				'search.enabled'                => false,
				'search.shop_enabled'           => false,
				'search.instant_enabled'        => false,
				'search.instant_limit'          => 8,
				'search.max_rows'               => 50,
				'search.deep_paging_limit'      => 1000,
				'search.cache_ttl'              => 300,
				'search.rate_limit'             => 60,
				'search.rate_window'            => 60,
				'search.relevance'              => array(),

				// Multilingual. Scoping is on by default: a visitor reading the
				// German site and searching from the German header expects German
				// results. The analysed list is empty until a language is enabled
				// from the Search screen, which only offers languages whose
				// analyser the server actually has.
				'search.language_scope'         => true,
				'index.analysed_languages'      => array(),

				'runtime.debug'                 => false,

				// What this site knows about the schema it is pointed at: the
				// contract version applied, a fingerprint of the se_* field set
				// as it was left, and when that was last confirmed. See the
				// plan's section 14.3 and Schema\AppliedSchema.
				'runtime.schema_version'        => 0,
				'runtime.schema_fingerprint'    => '',
				'runtime.schema_verified_at'    => 0,

				// Whether a setting has changed that existing documents do not
				// reflect. Solr never rewrites a document because a schema or a
				// field map changed, so the gap is recorded rather than left for
				// somebody to discover.
				'runtime.reindex_reason'        => '',
				'runtime.reindex_since'         => 0,
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

		if ( null !== $this->network && $this->network->is_pinned( $path ) ) {
			return $this->network->get( $path );
		}

		$stored = $this->stored_value( $path );

		if ( null !== $stored ) {
			return $stored;
		}

		if ( null !== $this->network ) {
			$networked = $this->network->get( $path );

			if ( null !== $networked ) {
				return $networked;
			}
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
		return '' !== $this->lock_source( $path );
	}

	/**
	 * What is pinning a value, when something is.
	 *
	 * The admin screens need the distinction, not just the fact: "set in
	 * wp-config.php" and "set for the whole network" send an administrator to two
	 * completely different places, and telling them the wrong one wastes an
	 * afternoon.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Dot path.
	 * @return string "constant", "network", or an empty string when editable.
	 */
	public function lock_source( string $path ): string {
		if ( isset( $this->constants[ $path ] ) && defined( $this->constants[ $path ] ) ) {
			return 'constant';
		}

		if ( null !== $this->network && $this->network->is_pinned( $path ) ) {
			return 'network';
		}

		return '';
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

		if ( null !== $this->network ) {
			$this->network->flush();
		}
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
