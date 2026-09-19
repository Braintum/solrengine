<?php
/**
 * Logging contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Records what happened, without deciding where it goes.
 *
 * Exists so that no shipped code calls error_log() directly, which the
 * prototype did and which both PHP_CodeSniffer and the plugin review flag. The
 * default implementation discards everything unless SOLRENGINE_DEBUG is on, so
 * a production site pays nothing for instrumentation.
 *
 * Implementations must redact credentials from context before writing. A log
 * line containing a Solr password is a disclosure whether or not anyone reads
 * it.
 *
 * Intentionally narrower than PSR-3: four levels, no interpolation, no
 * dependency. SolrEngine has no use for emergency, alert or notice, and adding
 * a PSR-3 package to the runtime would contradict ADR-001.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
interface Logger {

	/**
	 * Diagnostic detail useful only while investigating.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function debug( string $message, array $context = array() ): void;

	/**
	 * Something noteworthy that is working as intended.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function info( string $message, array $context = array() ): void;

	/**
	 * Something is wrong but the operation continued.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function warning( string $message, array $context = array() ): void;

	/**
	 * An operation failed.
	 *
	 * @since 0.1.0
	 *
	 * @param string              $message Message.
	 * @param array<string,mixed> $context Structured context.
	 * @return void
	 */
	public function error( string $message, array $context = array() ): void;
}
