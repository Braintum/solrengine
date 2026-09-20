<?php
/**
 * What this site indexes into, and whether it indexes at all.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Multisite;

use SolrEngine\Support\Config;

/**
 * The two questions every site in a network has to answer before doing anything.
 *
 * "Am I switched on" and "which collection am I". On a single site both answers
 * are trivial and this class says so in one line each, which is the point: the
 * rest of the plugin asks the questions unconditionally and never learns what
 * multisite is.
 *
 * The topology behind the second answer is, per §10.2, "a deployment choice, not
 * an architectural one". That property was bought in Phase 0 by writing
 * `se_i_site` on every document and injecting it into every query even on a
 * single-site install where it is always 1 — so moving a network between one
 * collection per site and one shared collection changes a collection name and
 * nothing else. No document is rebuilt differently, no query is built
 * differently, and no stored configuration changes shape.
 *
 * @since 0.9.0
 */
final class SiteScope {

	/**
	 * Network configuration.
	 *
	 * @var NetworkConfig
	 */
	private $network;

	/**
	 * Site configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * This site's identifier.
	 *
	 * @var int
	 */
	private $site_id;

	/**
	 * Constructor.
	 *
	 * @param NetworkConfig $network Network configuration.
	 * @param Config        $config  Site configuration.
	 * @param int           $site_id This site's identifier.
	 */
	public function __construct( NetworkConfig $network, Config $config, int $site_id ) {
		$this->network = $network;
		$this->config  = $config;
		$this->site_id = $site_id;
	}

	/**
	 * Whether SolrEngine should do anything on this site.
	 *
	 * False for a site in a network that the network administrator has not
	 * switched on. Such a site indexes nothing, searches through WordPress as it
	 * always did, and shows an admin notice saying why — rather than appearing
	 * broken, which is what an inert plugin with a full settings screen looks
	 * like.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function is_enabled(): bool {
		return $this->network->is_site_enabled( $this->site_id );
	}

	/**
	 * The collection this site reads and writes.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function collection(): string {
		$own = $this->config->get_string( 'connection.collection' );

		if ( ! $this->network->is_network() ) {
			return $own;
		}

		if ( NetworkConfig::TOPOLOGY_SHARED === $this->network->topology() ) {
			// One collection for the network. Every document already carries its
			// site identifier and every query already filters on it, so the
			// separation is the same one a single-site install relies on.
			return $own;
		}

		$pattern = $this->network->collection_pattern();

		if ( '' === $pattern ) {
			// No pattern: each site names its own collection, which is what a
			// network of a dozen heterogeneous sites usually wants.
			return $own;
		}

		return $this->expand( $pattern );
	}

	/**
	 * The topology in force.
	 *
	 * @since 0.9.0
	 *
	 * @return string
	 */
	public function topology(): string {
		return $this->network->is_network()
			? $this->network->topology()
			: NetworkConfig::TOPOLOGY_PER_SITE;
	}

	/**
	 * Whether the collection name is derived rather than typed.
	 *
	 * The connection screen disables the field and explains, instead of
	 * accepting a value it will ignore.
	 *
	 * @since 0.9.0
	 *
	 * @return bool
	 */
	public function collection_is_derived(): bool {
		return $this->network->is_network()
			&& NetworkConfig::TOPOLOGY_PER_SITE === $this->network->topology()
			&& '' !== $this->network->collection_pattern();
	}

	/**
	 * Expand a collection pattern for this site.
	 *
	 * `%d` becomes the site identifier. Done with a replacement rather than
	 * sprintf() because a network administrator's pattern is not a format string:
	 * a stray percent sign in one would make sprintf() emit a warning and return
	 * something unusable, and the name that comes out of here addresses a
	 * collection.
	 *
	 * @param string $pattern Pattern.
	 * @return string
	 */
	private function expand( string $pattern ): string {
		return str_replace( '%d', (string) $this->site_id, $pattern );
	}
}
