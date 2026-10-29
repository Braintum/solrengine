<?php
/**
 * Circuit breaker around a backend.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Remembers that the backend is failing, so every visitor does not rediscover it.
 *
 * A Solr that is down answers slowly or not at all. Without this, every search
 * on a busy site waits for its own timeout before falling back to WordPress,
 * which turns one outage into a site-wide stall. After a few failures the breaker
 * opens and searches fall back immediately.
 *
 * State is shared deliberately: it is one judgement about one backend, so the
 * results page and instant-search read and write the same record. A backend
 * autocomplete has found broken is one the results page stops waiting for.
 *
 * Extracted from {@see \SolrEngine\Search\SearchService} so that the service can
 * be tested without WordPress — reading `get_option()` there was a leaked seam —
 * and so a process that must not write on a read can decline to.
 *
 * @since 1.0.0
 */
interface Breaker {

	/**
	 * Whether calls should be skipped for now.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_open(): bool;

	/**
	 * Note that a call failed.
	 *
	 * @since 1.0.0
	 *
	 * @param string $code Stable failure code from the backend.
	 * @return void
	 */
	public function record_failure( string $code ): void;

	/**
	 * Note that a call succeeded.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function record_success(): void;
}
