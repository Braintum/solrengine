<?php
/**
 * Circuit breaker state held in an option.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Breaker;
use SolrEngine\Contracts\Logger;

/**
 * The breaker every request shares, stored in one option.
 *
 * An option rather than a transient, because a transient without a persistent
 * object cache is an option with an expiry row attached, and this one is read on
 * every search. Deleted rather than reset on success, so a healthy site carries
 * no row at all.
 *
 * @since 1.0.0
 */
final class OptionBreaker implements Breaker {

	/**
	 * Option holding the state.
	 *
	 * @var string
	 */
	public const OPTION = 'solrengine_breaker';

	/**
	 * Consecutive failures before the breaker opens.
	 *
	 * @var int
	 */
	private const FAILURE_THRESHOLD = 3;

	/**
	 * Seconds the breaker stays open.
	 *
	 * @var int
	 */
	private const COOLDOWN_SECONDS = 60;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	private $logger;

	/**
	 * Constructor.
	 *
	 * @param Logger $logger Logger.
	 */
	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		$state = get_option( self::OPTION, array() );

		if ( ! is_array( $state ) || empty( $state['open_until'] ) ) {
			return false;
		}

		return time() < (int) $state['open_until'];
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $code Failure code.
	 * @return void
	 */
	public function record_failure( string $code ): void {
		$state = get_option( self::OPTION, array() );
		$state = is_array( $state ) ? $state : array();

		$failures = (int) ( $state['failures'] ?? 0 ) + 1;

		$state['failures']   = $failures;
		$state['last_code']  = $code;
		$state['last_error'] = time();

		if ( $failures >= self::FAILURE_THRESHOLD ) {
			$state['open_until'] = time() + self::COOLDOWN_SECONDS;

			// Logged once when the breaker trips, not once per failed search. A
			// backend outage should not also produce a log flood.
			$this->logger->error(
				'Search backend unavailable, pausing requests',
				array(
					'failures' => $failures,
					'code'     => $code,
					'cooldown' => self::COOLDOWN_SECONDS,
				)
			);
		}

		update_option( self::OPTION, $state, false );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return void
	 */
	public function record_success(): void {
		$state = get_option( self::OPTION, array() );

		if ( ! is_array( $state ) || array() === $state ) {
			return;
		}

		delete_option( self::OPTION );
	}
}
