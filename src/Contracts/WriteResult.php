<?php
/**
 * Outcome of a batch write.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What happened to a batch of documents.
 *
 * Batch writes are partially successful more often than they are wholly
 * successful: one malformed document among a hundred must not discard the
 * other ninety-nine (plan section 12.5). This type carries per-reference
 * failures so the caller can quarantine offenders and continue.
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class WriteResult {

	/**
	 * Number of documents accepted by the backend.
	 *
	 * @var int
	 */
	private $written;

	/**
	 * Failures keyed by document reference dedupe key.
	 *
	 * @var array<string,string>
	 */
	private $failures;

	/**
	 * Backend-reported duration in milliseconds, or null when unknown.
	 *
	 * @var int|null
	 */
	private $took_ms;

	/**
	 * Constructor.
	 *
	 * @param int                  $written  Documents accepted.
	 * @param array<string,string> $failures Failure messages keyed by dedupe key.
	 * @param int|null             $took_ms  Duration in milliseconds.
	 */
	public function __construct( int $written, array $failures = array(), ?int $took_ms = null ) {
		$this->written  = $written;
		$this->failures = $failures;
		$this->took_ms  = $took_ms;
	}

	/**
	 * Documents accepted by the backend.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function written(): int {
		return $this->written;
	}

	/**
	 * Failure messages keyed by document reference dedupe key.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * Number of documents the backend rejected.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function failed(): int {
		return count( $this->failures );
	}

	/**
	 * Whether every document in the batch was accepted.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_complete(): bool {
		return array() === $this->failures;
	}

	/**
	 * Backend-reported duration in milliseconds, or null when unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return int|null
	 */
	public function took_ms(): ?int {
		return $this->took_ms;
	}
}
