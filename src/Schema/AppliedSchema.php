<?php
/**
 * What this site has applied to its Solr schema.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Schema;

use SolrEngine\Support\Config;

/**
 * Remembers which contract was applied, and notices when somebody else changes
 * the schema afterwards.
 *
 * The plan's section 14.3 asks for three pieces of state per connection: the
 * contract version applied, a fingerprint of the live "se_" field set, and when
 * it was last verified. The fingerprint is the part that earns its keep. A
 * version alone cannot tell the difference between "the schema is what we left
 * it as" and "somebody deleted a field last week", and the first symptom of the
 * second is indexing failing for reasons no error message explains.
 *
 * Nothing here contacts Solr. It is told what the live schema looks like and
 * records or compares; the verdict is a pure function so that every branch of it
 * is testable without a server.
 *
 * @since 0.8.0
 */
final class AppliedSchema {

	/**
	 * Nothing has been applied through SolrEngine.
	 *
	 * @var string
	 */
	public const UNRECORDED = 'unrecorded';

	/**
	 * The live schema satisfies the required contract and is unchanged.
	 *
	 * @var string
	 */
	public const CURRENT = 'current';

	/**
	 * The live schema is older than the contract this build requires.
	 *
	 * @var string
	 */
	public const BEHIND = 'behind';

	/**
	 * The version is right but the field set is not what we left behind.
	 *
	 * @var string
	 */
	public const ALTERED = 'altered';

	/**
	 * Configuration.
	 *
	 * @var Config
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param Config $config Configuration.
	 */
	public function __construct( Config $config ) {
		$this->config = $config;
	}

	/**
	 * Record a successful verification or apply.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $version     Contract version now satisfied.
	 * @param string $fingerprint Fingerprint of the live field set.
	 * @return void
	 */
	public function record( int $version, string $fingerprint ): void {
		$this->config->set( 'runtime.schema_version', max( 0, $version ) );
		$this->config->set( 'runtime.schema_fingerprint', $fingerprint );
		$this->config->set( 'runtime.schema_verified_at', time() );
	}

	/**
	 * Forget the recorded state.
	 *
	 * Used when the connection is pointed at a different collection, where
	 * everything remembered about the previous one is now misleading.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->config->set( 'runtime.schema_version', 0 );
		$this->config->set( 'runtime.schema_fingerprint', '' );
		$this->config->set( 'runtime.schema_verified_at', 0 );
	}

	/**
	 * The recorded contract version, or zero.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	public function version(): int {
		return $this->config->get_int( 'runtime.schema_version', 0 );
	}

	/**
	 * The recorded fingerprint, or an empty string.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public function fingerprint(): string {
		return $this->config->get_string( 'runtime.schema_fingerprint', '' );
	}

	/**
	 * When the schema was last verified, as a Unix timestamp, or zero.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	public function verified_at(): int {
		return $this->config->get_int( 'runtime.schema_verified_at', 0 );
	}

	/**
	 * Compare what was recorded against what is live.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $required         Contract version this build needs.
	 * @param int    $live_version     Contract version the live schema satisfies.
	 * @param string $live_fingerprint Fingerprint of the live field set.
	 * @return string One of the verdict constants.
	 */
	public function compare( int $required, int $live_version, string $live_fingerprint ): string {
		return self::verdict(
			$this->version(),
			$this->fingerprint(),
			$required,
			$live_version,
			$live_fingerprint
		);
	}

	/**
	 * The verdict, as a pure function of the five values it depends on.
	 *
	 * Order matters. "Behind" is reported before "altered", because a schema
	 * that is missing fields needs the same action either way — apply the plan —
	 * and telling an administrator their schema was tampered with when it is
	 * simply out of date would send them looking for an intruder.
	 *
	 * @since 0.8.0
	 *
	 * @param int    $recorded_version     Version recorded by this site.
	 * @param string $recorded_fingerprint Fingerprint recorded by this site.
	 * @param int    $required             Version this build requires.
	 * @param int    $live_version         Version the live schema satisfies.
	 * @param string $live_fingerprint     Fingerprint of the live field set.
	 * @return string
	 */
	public static function verdict(
		int $recorded_version,
		string $recorded_fingerprint,
		int $required,
		int $live_version,
		string $live_fingerprint
	): string {
		if ( $live_version < $required ) {
			return self::BEHIND;
		}

		if ( $recorded_version <= 0 || '' === $recorded_fingerprint ) {
			// The schema is adequate, but this site did not put it there: an
			// administrator applied the configset by hand, or the collection was
			// prepared before SolrEngine was installed. Worth distinguishing,
			// because there is nothing to compare a change against yet.
			return self::UNRECORDED;
		}

		if ( $recorded_fingerprint !== $live_fingerprint ) {
			return self::ALTERED;
		}

		return self::CURRENT;
	}
}
