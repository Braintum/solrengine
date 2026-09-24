<?php
/**
 * Viewer tests.
 *
 * The seam exists so that SearchService can decide whether a result set may be
 * shared without asking WordPress, which is what lets the same service answer a
 * REST request and an instant-search request.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\Viewer;
use SolrEngine\Support\CookieViewer;

/**
 * @covers \SolrEngine\Support\CookieViewer
 */
final class ViewerTest extends TestCase {

	public function test_it_is_a_viewer(): void {
		$this->assertInstanceOf( Viewer::class, new CookieViewer( array() ) );
	}

	public function test_no_cookies_means_anonymous(): void {
		$this->assertFalse( ( new CookieViewer( array() ) )->is_authenticated() );
	}

	public function test_the_logged_in_cookie_means_authenticated(): void {
		$cookies = array( 'wordpress_logged_in_0123456789abcdef' => 'anything' );

		$this->assertTrue( ( new CookieViewer( $cookies ) )->is_authenticated() );
	}

	public function test_other_wordpress_cookies_do_not_count(): void {
		// The test cookie and a comment-author cookie are set for visitors who
		// have never signed in. Treating those as authenticated would disable
		// the suggestion cache for most of the internet.
		$cookies = array(
			'wordpress_test_cookie'    => 'WP Cookie check',
			'comment_author_email_abc' => 'someone@example.com',
			'wp-settings-time-1'       => '123',
		);

		$this->assertFalse( ( new CookieViewer( $cookies ) )->is_authenticated() );
	}

	public function test_the_cookie_value_is_never_inspected(): void {
		// Presence is the whole check. Validating would mean loading the auth
		// stack and reading the user row on every keystroke, and the only
		// consequence here is whether one cache entry may be shared.
		$this->assertTrue(
			( new CookieViewer( array( 'wordpress_logged_in_x' => '' ) ) )->is_authenticated()
		);
	}

	public function test_an_unrecognised_cookie_alongside_the_real_one_still_counts(): void {
		$cookies = array(
			'unrelated'             => '1',
			'wordpress_logged_in_x' => 'v',
		);

		$this->assertTrue( ( new CookieViewer( $cookies ) )->is_authenticated() );
	}
}
