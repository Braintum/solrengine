<?php
/**
 * What happened to a batch.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

/**
 * The result of processing one set of references.
 *
 * Distinguishes four outcomes that a single count would blur together: written,
 * removed, failed, and skipped. "Skipped" in particular is not a failure — a
 * draft that a job was queued for is correctly absent — and reporting it as one
 * would make a healthy site look broken on the dashboard.
 *
 * Immutable.
 *
 * @since 0.3.0
 */
final class IndexOutcome {

	/**
	 * Documents written.
	 *
	 * @var int
	 */
	private $written;

	/**
	 * Documents removed.
	 *
	 * @var int
	 */
	private $removed;

	/**
	 * Failures keyed by document reference.
	 *
	 * @var array<string,string>
	 */
	private $failures;

	/**
	 * Skip reasons keyed by document reference.
	 *
	 * @var array<string,string>
	 */
	private $skipped;

	/**
	 * Constructor.
	 *
	 * @param int                  $written  Documents written.
	 * @param int                  $removed  Documents removed.
	 * @param array<string,string> $failures Failures by reference.
	 * @param array<string,string> $skipped  Skip reasons by reference.
	 */
	public function __construct( int $written, int $removed, array $failures = array(), array $skipped = array() ) {
		$this->written  = $written;
		$this->removed  = $removed;
		$this->failures = $failures;
		$this->skipped  = $skipped;
	}

	/**
	 * Documents written.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function written(): int {
		return $this->written;
	}

	/**
	 * Documents removed.
	 *
	 * @since 0.3.0
	 *
	 * @return int
	 */
	public function removed(): int {
		return $this->removed;
	}

	/**
	 * Documents that could not be indexed.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,string>
	 */
	public function failures(): array {
		return $this->failures;
	}

	/**
	 * Documents deliberately not indexed, with the reason.
	 *
	 * @since 0.3.0
	 *
	 * @return array<string,string>
	 */
	public function skipped(): array {
		return $this->skipped;
	}

	/**
	 * Whether anything failed.
	 *
	 * @since 0.3.0
	 *
	 * @return bool
	 */
	public function has_failures(): bool {
		return array() !== $this->failures;
	}

	/**
	 * A one-line summary.
	 *
	 * @since 0.3.0
	 *
	 * @return string
	 */
	public function describe(): string {
		return sprintf(
			'%d written, %d removed, %d skipped, %d failed',
			$this->written,
			$this->removed,
			count( $this->skipped ),
			count( $this->failures )
		);
	}
}
