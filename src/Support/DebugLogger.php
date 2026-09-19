<?php
/**
 * Debug logger.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Logger;

/**
 * Writes to the PHP error log, only when debugging is switched on.
 *
 * Selected by the composition root when SOLRENGINE_DEBUG is defined, or when
 * WP_DEBUG and WP_DEBUG_LOG are both on. Every context array passes through
 * Credentials::redact() first, so a password cannot reach the log by being
 * attached to an error.
 *
 * @since 0.1.0
 */
final class DebugLogger implements Logger {

	/**
	 * Minimum level to record, as an index into self::LEVELS.
	 *
	 * @var int
	 */
	private $threshold;

	/**
	 * Ordered severity levels.
	 *
	 * @var string[]
	 */
	private const LEVELS = array( 'debug', 'info', 'warning', 'error' );

	/**
	 * Constructor.
	 *
	 * @param string $minimum_level One of debug, info, warning or error.
	 */
	public function __construct( string $minimum_level = 'debug' ) {
		$index = array_search( $minimum_level, self::LEVELS, true );

		$this->threshold = false === $index ? 0 : (int) $index;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->write( 'debug', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
		$this->write( 'error', $message, $context );
	}

	/**
	 * Format and emit one line.
	 *
	 * @param string              $level   Severity.
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	private function write( string $level, string $message, array $context ): void {
		$index = array_search( $level, self::LEVELS, true );

		if ( false === $index || (int) $index < $this->threshold ) {
			return;
		}

		$line = sprintf( '[SolrEngine] %s: %s', strtoupper( $level ), $message );

		if ( array() !== $context ) {
			$encoded = wp_json_encode( Credentials::redact( $context ) );
			$line   .= ' ' . ( false === $encoded ? '[uncodable context]' : $encoded );
		}

		// This is the one place in the plugin permitted to write to the error
		// log, and it is unreachable unless debugging is explicitly enabled.
		error_log( $line ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}
