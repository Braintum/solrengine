<?php
/**
 * The signed-in check for a process with no user stack.
 *
 * @package SolrEngine
 */

namespace SolrEngine\Support;

use SolrEngine\Contracts\Viewer;

/**
 * Reads the presence of an authentication cookie, and nothing more.
 *
 * Used by the instant-search entry point, which boots with `SHORTINIT` and so
 * has no `is_user_logged_in()` to call. Validating the cookie would mean loading
 * the pluggable auth stack and hashing against the user row — a database read
 * and a chunk of WordPress on every keystroke, to answer a question whose only
 * consequence here is whether one cache entry may be shared.
 *
 * So the cookie is not validated, only noticed. A forged cookie makes this
 * return true, which costs the forger a cache entry and gains them nothing: it
 * grants no access, and the query's site scope and visibility filter are applied
 * regardless. An expired or invalid cookie has the same harmless effect. What
 * matters is the direction of the error — a signed-in user is never mistaken for
 * anonymous, which is the mistake that would leak one view into another's cache.
 *
 * @since 0.10.2
 */
final class CookieViewer implements Viewer {

	/**
	 * Cookie name prefix WordPress uses for the logged-in cookie.
	 *
	 * Matched by prefix because the full name carries a hash of the site's
	 * address, and `LOGGED_IN_COOKIE` is defined during a boot this process does
	 * not perform.
	 *
	 * @var string
	 */
	private const PREFIX = 'wordpress_logged_in_';

	/**
	 * Cookies to inspect.
	 *
	 * @var array<string,mixed>
	 */
	private $cookies;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed>|null $cookies Cookies, or null for the request's.
	 */
	public function __construct( ?array $cookies = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Presence only, never trusted as authentication.
		$this->cookies = $cookies ?? $_COOKIE;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return bool
	 */
	public function is_authenticated(): bool {
		foreach ( array_keys( $this->cookies ) as $name ) {
			if ( 0 === strpos( (string) $name, self::PREFIX ) ) {
				return true;
			}
		}

		return false;
	}
}
