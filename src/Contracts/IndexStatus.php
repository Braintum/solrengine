<?php
/**
 * Index health snapshot.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * What the backend reports about the index right now.
 *
 * Drives the admin dashboard and the reconciliation job. The document count is
 * the backend's own, deliberately not WordPress's: comparing the two is how
 * silent drift is detected (plan section 12.7).
 *
 * Immutable.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
final class IndexStatus {

	/**
	 * Whether the backend considers the index usable.
	 *
	 * @var bool
	 */
	private $healthy;

	/**
	 * Documents the backend currently holds for this site.
	 *
	 * @var int
	 */
	private $document_count;

	/**
	 * Schema contract version the index was built against, 0 when unknown.
	 *
	 * @var int
	 */
	private $schema_version;

	/**
	 * Additional backend-specific detail for display only.
	 *
	 * @var array<string,mixed>
	 */
	private $detail;

	/**
	 * Constructor.
	 *
	 * @param bool                $healthy        Whether the index is usable.
	 * @param int                 $document_count Documents held.
	 * @param int                 $schema_version Schema contract version.
	 * @param array<string,mixed> $detail         Display-only detail.
	 */
	public function __construct( bool $healthy, int $document_count = 0, int $schema_version = 0, array $detail = array() ) {
		$this->healthy        = $healthy;
		$this->document_count = $document_count;
		$this->schema_version = $schema_version;
		$this->detail         = $detail;
	}

	/**
	 * Whether the index is usable.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function is_healthy(): bool {
		return $this->healthy;
	}

	/**
	 * Documents the backend holds for this site.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function document_count(): int {
		return $this->document_count;
	}

	/**
	 * Schema contract version the index was built against, 0 when unknown.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public function schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Backend-specific detail, for display only.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,mixed>
	 */
	public function detail(): array {
		return $this->detail;
	}
}
