<?php
/**
 * Network-level configuration.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Multisite;

/**
 * Configuration a network administrator sets once for every site.
 *
 * The plan's section 20 asks for two things this provides. First, that
 * configuration be "readable from either site options or network options through
 * one accessor" — so `Config` reads this rather than every caller learning about
 * multisite. Second, per-site opt-in, "because a network administrator enabling a
 * plugin for 400 sites does not want 400 uncoordinated full syncs starting at
 * once."
 *
 * Two kinds of network value, and the difference matters:
 *
 * - A **default** is what a site gets when it has set nothing itself. A site can
 *   override it. This is what F-66 means by "per-site configuration with network
 *   defaults".
 * - A **pin** cannot be overridden. A network administrator who has one Solr
 *   cluster does not want site 87 pointed somewhere else, and a site admin
 *   should be told the field is not theirs rather than have their save silently
 *   discarded — which is the failure the 0.1.0 prototype's settings screen was
 *   built on.
 *
 * Everything here reads and writes network options directly and depends on
 * nothing, because `Config` depends on it.
 *
 * @since 0.9.0
 */
final class NetworkConfig {

	/**
	 * Network option holding shared configuration values.
	 *
	 * @var string
	 */
	public const OPTION_VALUES = 'solrengine_network_connection';

	/**
	 * Network option holding pins, enabled sites and topology.
	 *
	 * @var string
	 */
	public const OPTION_STATE = 'solrengine_network_state';

	/**
	 * One collection per site. The default, per §10.2.
	 *
	 * @var string
	 */
	public const TOPOLOGY_PER_SITE = 'per-site';

	/**
	 * One collection for the whole network, separated by site identifier.
	 *
	 * @var string
	 */
	public const TOPOLOGY_SHARED = 'shared';

	/**
	 * Per-request memo of the two option groups.
	 *
	 * @var array<string,array<string,mixed>>
	 */
	private $loaded = array();

	/**
	 * Whether this is a network at all.
	 *
	 * Everything below returns nothing on a single site, so a single-site install
	 * pays one function call and never reads a network option.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function is_network(): bool {
		return is_multisite();
	}

	/**
	 * A network value for a dot path, or null when there is none.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Dot path, for example "connection.endpoint".
	 * @return mixed|null
	 */
	public function get( string $path ) {
		if ( ! $this->is_network() ) {
			return null;
		}

		$values = $this->load( self::OPTION_VALUES );

		return array_key_exists( $path, $values ) ? $values[ $path ] : null;
	}

	/**
	 * Store a network value.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path  Dot path.
	 * @param mixed  $value Value, or null to remove it.
	 * @return bool
	 */
	public function set( string $path, $value ): bool {
		if ( ! $this->is_network() ) {
			return false;
		}

		$values = $this->load( self::OPTION_VALUES );

		if ( null === $value ) {
			unset( $values[ $path ] );
		} else {
			$values[ $path ] = $value;
		}

		$this->loaded[ self::OPTION_VALUES ] = $values;

		return update_site_option( self::OPTION_VALUES, $values );
	}

	/**
	 * Whether a path is pinned by the network and sites may not change it.
	 *
	 * @since 0.9.0
	 *
	 * @param string $path Dot path.
	 * @return bool
	 */
	public function is_pinned( string $path ): bool {
		if ( ! $this->is_network() ) {
			return false;
		}

		$state = $this->load( self::OPTION_STATE );
		$pins  = isset( $state['pinned'] ) && is_array( $state['pinned'] ) ? $state['pinned'] : array();

		// A pin on a path with no value would lock every site out of a setting
		// and give them nothing in its place, which is worse than either
		// alternative. Reported as unpinned, so the site's own value applies.
		return in_array( $path, array_map( 'strval', $pins ), true ) && null !== $this->get( $path );
	}

	/**
	 * Replace the set of pinned paths.
	 *
	 * @since 0.9.0
	 *
	 * @param string[] $paths Dot paths to pin.
	 * @return bool
	 */
	public function set_pinned( array $paths ): bool {
		return $this->put_state( 'pinned', array_values( array_unique( array_map( 'strval', $paths ) ) ) );
	}

	/**
	 * The collection topology for this network.
	 *
	 * @since 0.9.0
	 *
	 * @return string One of the TOPOLOGY_ constants.
	 */
	public function topology(): string {
		$state = $this->load( self::OPTION_STATE );
		$value = (string) ( $state['topology'] ?? self::TOPOLOGY_PER_SITE );

		return self::TOPOLOGY_SHARED === $value ? self::TOPOLOGY_SHARED : self::TOPOLOGY_PER_SITE;
	}

	/**
	 * Set the collection topology.
	 *
	 * @since 0.9.0
	 *
	 * @param string $topology One of the TOPOLOGY_ constants.
	 * @return bool
	 */
	public function set_topology( string $topology ): bool {
		return $this->put_state(
			'topology',
			self::TOPOLOGY_SHARED === $topology ? self::TOPOLOGY_SHARED : self::TOPOLOGY_PER_SITE
		);
	}

	/**
	 * Whether a site has been switched on by the network administrator.
	 *
	 * The default is off, and that is the whole point of the opt-in: activating a
	 * plugin network-wide must not start four hundred full syncs against one Solr
	 * cluster.
	 *
	 * The opt-in applies only to a **network activation**, which is the thing it
	 * exists to restrain. A site administrator who activated SolrEngine on their
	 * own site has already opted in, by doing that — and requiring a second
	 * consent from a network screen that only exists for network-activated
	 * plugins would leave them with a plugin that does nothing and no way to
	 * change it.
	 *
	 * On a single site it is always true for the same reason: there is no network
	 * administrator to ask.
	 *
	 * @since 0.9.0
	 *
	 * @param int $site_id Site identifier.
	 * @return bool
	 */
	public function is_site_enabled( int $site_id ): bool {
		if ( ! $this->requires_opt_in() ) {
			return true;
		}

		return in_array( $site_id, $this->enabled_sites(), true );
	}

	/**
	 * Whether sites in this network have to be switched on individually.
	 *
	 * True only when SolrEngine is activated for the whole network.
	 *
	 * Read from the network option rather than through
	 * `is_plugin_active_for_network()`, which lives in wp-admin and is not loaded
	 * on a front-end request — where this question is asked on every page.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function requires_opt_in(): bool {
		if ( ! $this->is_network() ) {
			return false;
		}

		$active = get_site_option( 'active_sitewide_plugins', array() );

		if ( ! is_array( $active ) ) {
			return false;
		}

		return isset( $active[ plugin_basename( SOLRENGINE_PLUGIN_FILE ) ] );
	}

	/**
	 * The sites the network administrator has switched on.
	 *
	 * @since 0.9.0
	 *
	 * @return int[]
	 */
	public function enabled_sites(): array {
		$state = $this->load( self::OPTION_STATE );
		$sites = isset( $state['sites'] ) && is_array( $state['sites'] ) ? $state['sites'] : array();

		return array_values( array_unique( array_map( 'intval', $sites ) ) );
	}

	/**
	 * Replace the set of enabled sites.
	 *
	 * @since 0.9.0
	 *
	 * @param int[] $site_ids Site identifiers.
	 * @return bool
	 */
	public function set_enabled_sites( array $site_ids ): bool {
		return $this->put_state( 'sites', array_values( array_unique( array_map( 'intval', $site_ids ) ) ) );
	}

	/**
	 * The naming pattern for per-site collections.
	 *
	 * `%d` is replaced with the site identifier. Ignored under the shared
	 * topology, where every site uses one collection.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function collection_pattern(): string {
		$state = $this->load( self::OPTION_STATE );

		return (string) ( $state['pattern'] ?? '' );
	}

	/**
	 * Set the naming pattern for per-site collections.
	 *
	 * @since 0.9.0
	 *
	 * @param string $pattern Pattern containing %d.
	 * @return bool
	 */
	public function set_collection_pattern( string $pattern ): bool {
		return $this->put_state( 'pattern', $pattern );
	}

	/**
	 * Forget anything memoised this request.
	 *
	 * @since 0.9.0
	 *
	 * @return void
	 */
	public function flush(): void {
		$this->loaded = array();
	}

	/**
	 * Write one key of the state option.
	 *
	 * @param string $key   State key.
	 * @param mixed  $value Value.
	 * @return bool
	 */
	private function put_state( string $key, $value ): bool {
		if ( ! $this->is_network() ) {
			return false;
		}

		$state = $this->load( self::OPTION_STATE );

		$state[ $key ] = $value;

		$this->loaded[ self::OPTION_STATE ] = $state;

		return update_site_option( self::OPTION_STATE, $state );
	}

	/**
	 * Load and memoise one network option.
	 *
	 * @param string $option Option name.
	 * @return array<string,mixed>
	 */
	private function load( string $option ): array {
		if ( ! isset( $this->loaded[ $option ] ) ) {
			if ( ! $this->is_network() ) {
				return array();
			}

			$value                   = get_site_option( $option, array() );
			$this->loaded[ $option ] = is_array( $value ) ? $value : array();
		}

		return $this->loaded[ $option ];
	}
}
