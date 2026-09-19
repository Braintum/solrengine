<?php
/**
 * Records that a configuration change needs content indexed again.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Indexing;

use SolrEngine\Support\Config;

/**
 * The gap between changing a setting and the index reflecting it.
 *
 * Adding a meta key to the allowlist, adding a post type, or applying a schema
 * change with a new copyField rule all change what a document should contain —
 * and none of them change a single document that is already indexed. Solr is
 * explicit that "modifying your schema will never modify any documents that are
 * already indexed", and the same is true of SolrEngine's own field map.
 *
 * Without this, the setting appears to have taken effect and quietly has not,
 * which is the failure mode this plugin has spent six phases avoiding. The
 * notice is set when such a change is saved and cleared when a full run
 * finishes, so the answer to "why is my new field not searchable" is on screen
 * before the question is asked.
 *
 * @since 0.8.0
 */
final class ReindexNotice {

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
	 * Note that a reindex is needed, and why.
	 *
	 * The first reason is kept rather than the last. An administrator who
	 * changes four settings before reindexing needs to know the oldest change
	 * still outstanding, not the most recent one.
	 *
	 * @since 0.8.0
	 *
	 * @param string $reason Short human-readable reason.
	 * @return void
	 */
	public function require_reindex( string $reason ): void {
		if ( $this->is_required() ) {
			return;
		}

		$this->config->set( 'runtime.reindex_reason', $reason );
		$this->config->set( 'runtime.reindex_since', time() );
	}

	/**
	 * Whether a reindex is outstanding.
	 *
	 * @since 0.8.0
	 *
	 * @return bool
	 */
	public function is_required(): bool {
		return '' !== $this->reason();
	}

	/**
	 * Why a reindex is outstanding, or an empty string.
	 *
	 * @since 0.8.0
	 *
	 * @return string
	 */
	public function reason(): string {
		return $this->config->get_string( 'runtime.reindex_reason', '' );
	}

	/**
	 * When the outstanding change was made, or zero.
	 *
	 * @since 0.8.0
	 *
	 * @return int
	 */
	public function since(): int {
		return $this->config->get_int( 'runtime.reindex_since', 0 );
	}

	/**
	 * Clear the notice.
	 *
	 * @since 0.8.0
	 *
	 * @return void
	 */
	public function clear(): void {
		$this->config->set( 'runtime.reindex_reason', '' );
		$this->config->set( 'runtime.reindex_since', 0 );
	}
}
