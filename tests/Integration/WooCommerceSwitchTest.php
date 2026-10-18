<?php
/**
 * WooCommerce detection from options, for the instant-search fast path.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Integration;

use SolrEngine\Integration\WooCommerce\WooCommerce;
use SolrEngine\Rest\InstantEndpoint;
use WP_UnitTestCase;

/**
 * @covers \SolrEngine\Integration\WooCommerce\WooCommerce::is_switched_on
 * @covers \SolrEngine\Rest\InstantEndpoint::container
 */
final class WooCommerceSwitchTest extends WP_UnitTestCase {

	public function test_off_when_woocommerce_is_not_activated(): void {
		update_option( 'active_plugins', array( 'akismet/akismet.php' ) );

		$this->assertFalse( WooCommerce::is_switched_on() );
	}

	public function test_on_when_woocommerce_is_activated_from_any_folder(): void {
		foreach ( array( 'woocommerce/woocommerce.php', 'woocommerce-9.3/woocommerce.php' ) as $plugin ) {
			update_option( 'active_plugins', array( 'akismet/akismet.php', $plugin ) );

			$this->assertTrue( WooCommerce::is_switched_on(), $plugin );
		}
	}

	public function test_a_plugin_merely_named_like_it_does_not_count(): void {
		update_option( 'active_plugins', array( 'woocommerce-extra/extra.php', 'not-woocommerce.php' ) );

		$this->assertFalse( WooCommerce::is_switched_on() );
	}

	public function test_the_fast_path_searches_product_fields_when_woocommerce_is_activated(): void {
		// The fast path loads no plugins, so WooCommerce's own functions are
		// never there to ask; it must read the options instead.
		update_option( 'active_plugins', array( 'woocommerce/woocommerce.php' ) );

		$this->assertTrue( InstantEndpoint::container()->get( 'woocommerce.active' ) );

		update_option( 'active_plugins', array() );

		$this->assertFalse( InstantEndpoint::container()->get( 'woocommerce.active' ) );
	}
}
