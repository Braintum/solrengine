<?php
/**
 * Who is asking.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Contracts;

/**
 * Answers the one question the search path asks about the current visitor.
 *
 * {@see \SolrEngine\Search\SearchService} has to know whether it may put a
 * result set in a shared cache. It may not for an authenticated user, who can
 * see drafts and private posts a visitor cannot, and one shared entry would
 * serve one of them the other's view.
 *
 * It used to ask `is_user_logged_in()` directly. That made the service
 * untestable without WordPress — the seam the project's own rule says must not
 * leak — and unusable in a process where the user stack is not loaded, which is
 * exactly the process instant-search runs in.
 *
 * @since 1.0.0
 */
interface Viewer {

	/**
	 * Whether the current request belongs to a signed-in user.
	 *
	 * Implementations must err towards true. Answering false for someone who is
	 * in fact signed in risks caching their view for everyone; answering true
	 * for an anonymous visitor only forgoes a cache entry.
	 *
	 * @since 1.0.0
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool;
}
