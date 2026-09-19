<?php
/**
 * Discarding logger.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Logger;

/**
 * Throws every message away.
 *
 * The production default. Instrumentation should cost a healthy site nothing,
 * and a plugin that writes to the PHP error log on a normal page load is a
 * plugin that fills a disk.
 *
 * @since 0.1.0
 */
final class NullLogger implements Logger {

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void {
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void {
	}
}
