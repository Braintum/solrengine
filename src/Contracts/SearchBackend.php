<?php
/**
 * Search backend contract.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Reads from a search index.
 *
 * Split from IndexBackend deliberately (ADR-007). The two have different
 * consumers, different failure semantics and different lifecycles, and the
 * split lets a site search one backend while indexing into another during a
 * migration.
 *
 * Implementations must not throw. Every failure is a Result, because a slow or
 * unreachable backend is an expected condition and WordPress has to keep
 * rendering (Principle 1).
 *
 * Implementations must not accept engine query syntax from callers. The
 * prototype's `search( string $q, array $params )` is the shape this contract
 * exists to prevent: it made every caller Solr-specific and turned the public
 * endpoint into a proxy.
 *
 * @since 0.1.0 Unstable until 1.0.
 */
interface SearchBackend {

	/**
	 * Identifier for this backend, for example "solr" or "cloud".
	 *
	 * Stable, lowercase, used as an array key and in stored configuration, so
	 * it must not change once released.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	public function id(): string;

	/**
	 * Check that the backend is reachable and usable.
	 *
	 * Must be cheap enough to call from a health check and must never be called
	 * on every request; callers cache the outcome.
	 *
	 * @since 0.1.0
	 *
	 * @return Result Success, or a failure carrying a stable code such as
	 *                "unreachable", "auth_failed" or "collection_missing".
	 */
	public function ping(): Result;

	/**
	 * What this backend can do.
	 *
	 * Detected rather than assumed from a version number (ADR-011). Callers
	 * degrade on a missing capability instead of failing.
	 *
	 * @since 0.1.0
	 *
	 * @return Capabilities
	 */
	public function capabilities(): Capabilities;

	/*
	 * Arriving in later phases, once the types they need have a shape worth
	 * freezing. Listed here so the intended surface is visible:
	 *
	 *   Phase 3: search( SearchQuery $query ): Result   // value: SearchResults
	 *   Phase 4: suggest( SuggestQuery $query ): Result // value: Suggestions
	 *
	 * Facets are carried inside SearchQuery and SearchResults rather than
	 * through a separate method, because a facet request without its query is
	 * meaningless and a second method would invite computing them apart.
	 */
}
