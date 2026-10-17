<?php
/**
 * A viewer that is never signed in.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Viewer;

/**
 * Treats every request as anonymous.
 *
 * For a decision where wrongly answering "signed in" is the expensive mistake.
 * {@see CookieViewer} errs the other way on purpose — it notices a cookie
 * without validating it — and that is right for a cache, where a forged cookie
 * costs the forger a cache entry. It is wrong for a rate limiter, where
 * "signed in" means "exempt": a forged cookie would switch throttling off for
 * whoever sent it. In a process that cannot validate the cookie, nobody is
 * exempt.
 *
 * @since 0.11.0
 */
final class AnonymousViewer implements Viewer {

	/**
	 * {@inheritDoc}
	 *
	 * @since 0.11.0
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		return false;
	}
}
