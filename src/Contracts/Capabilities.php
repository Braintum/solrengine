<?php
/**
 * Backend capability report.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What a backend can actually do.
 *
 * SolrEngine gates features on detection rather than on version assumptions
 * (ADR-011), because Solr installations vary by version, mode, configset and
 * authorisation. A managed backend reports different capabilities again: the
 * Cloud adapter will report schema management as handled server-side, so the
 * admin UI hides schema screens without knowing why.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class Capabilities {

	/**
	 * Backend identifier, for example "solr" or "cloud".
	 *
	 * @var string
	 */
	private $backend;

	/**
	 * Engine version string, empty when unknown.
	 *
	 * @var string
	 */
	private $version;

	/**
	 * Deployment mode, for example "cloud", "user-managed" or "managed".
	 *
	 * @var string
	 */
	private $mode;

	/**
	 * Named capability flags.
	 *
	 * @var array<string,bool>
	 */
	private $features;

	/**
	 * Constructor.
	 *
	 * @param string             $backend  Backend identifier.
	 * @param string             $version  Engine version.
	 * @param string             $mode     Deployment mode.
	 * @param array<string,bool> $features Capability flags.
	 */
	public function __construct( string $backend, string $version = '', string $mode = '', array $features = array() ) {
		$this->backend  = $backend;
		$this->version  = $version;
		$this->mode     = $mode;
		$this->features = array_map( 'boolval', $features );
	}

	/**
	 * Backend identifier.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function backend(): string {
		return $this->backend;
	}

	/**
	 * Engine version, empty when unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function version(): string {
		return $this->version;
	}

	/**
	 * Deployment mode, empty when unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function mode(): string {
		return $this->mode;
	}

	/**
	 * Whether a named capability is available.
	 *
	 * Unknown capabilities are false. Callers must degrade rather than fail:
	 * a missing suggester is a disabled feature, not an error.
	 *
	 * @since 0.1.0
	 *
	 * @param string $feature Capability name, for example "json_facets",
	 *                        "schema_api", "suggester" or "aliases".
	 * @return bool
	 */
	public function supports( string $feature ): bool {
		return isset( $this->features[ $feature ] ) && $this->features[ $feature ];
	}

	/**
	 * All capability flags.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,bool>
	 */
	public function all(): array {
		return $this->features;
	}
}
