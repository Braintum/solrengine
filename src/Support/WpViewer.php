<?php
/**
 * The signed-in check for a normal WordPress request.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Viewer;

/**
 * Asks WordPress.
 *
 * The answer for every request that went through a full boot, which is all of
 * them except instant-search. See {@see CookieViewer} for that one.
 *
 * @since 0.10.2
 */
final class WpViewer implements Viewer {

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		// Guarded rather than called bare: the user stack is absent during a
		// SHORTINIT boot, and a viewer that fataled there would defeat the
		// purpose of having this seam at all.
		return function_exists( 'is_user_logged_in' ) ? is_user_logged_in() : true;
	}
}
