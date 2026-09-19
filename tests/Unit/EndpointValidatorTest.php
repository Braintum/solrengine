<?php
/**
 * Endpoint validation tests.
 *
 * These are the most important tests in the plugin. The validator is the main
 * defence against server-side request forgery, and every case below is a
 * request an attacker with admin access would try.
 *
 * DNS is injected, so every address range is exercised without touching a
 * network.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Backend\Solr\Endpoint;
use SolrEngine\Backend\Solr\EndpointValidator;

/**
 * @covers \SolrEngine\Backend\Solr\EndpointValidator
 * @covers \SolrEngine\Backend\Solr\Endpoint
 */
final class EndpointValidatorTest extends TestCase {

	/**
	 * A validator whose DNS always returns the given addresses.
	 *
	 * @param string[] $addresses Addresses to resolve to.
	 */
	private function validator( array $addresses = array( '203.0.113.10' ) ): EndpointValidator {
		return new EndpointValidator(
			array(),
			static function () use ( $addresses ): array {
				return $addresses;
			}
		);
	}

	// -- Addresses that must never be reachable ----------------------------

	/**
	 * @dataProvider metadata_addresses
	 */
	public function test_cloud_metadata_addresses_are_always_blocked( string $address ): void {
		$result = $this->validator( array( $address ) )
			->validate( 'https://metadata.internal:8983/solr', 'wp', true );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'blocked_address', $result->code() );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function metadata_addresses(): array {
		return array(
			array( '169.254.169.254' ),
			array( 'fd00:ec2::254' ),
			array( '100.100.100.200' ),
		);
	}

	public function test_metadata_is_blocked_even_when_private_access_is_acknowledged(): void {
		// The acknowledgement checkbox exists for "my Solr is on localhost".
		// It must never become a way to reach the cloud credential service.
		$result = $this->validator( array( '169.254.169.254' ) )
			->validate( 'http://169.254.169.254:8983/solr', 'wp', true );

		$this->assertSame( 'blocked_address', $result->code() );
	}

	public function test_link_local_range_is_blocked_entirely(): void {
		$result = $this->validator( array( '169.254.42.7' ) )
			->validate( 'http://link-local.test:8983/solr', 'wp', true );

		$this->assertSame( 'blocked_address', $result->code() );
	}

	/**
	 * @dataProvider reserved_addresses
	 */
	public function test_reserved_ranges_are_blocked( string $address ): void {
		$result = $this->validator( array( $address ) )
			->validate( 'http://reserved.test:8983/solr', 'wp', true );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'blocked_address', $result->code() );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function reserved_addresses(): array {
		return array(
			array( '0.0.0.0' ),
			array( '224.0.0.1' ),
			array( '240.0.0.1' ),
		);
	}

	// -- Private addresses: allowed, but only deliberately -----------------

	/**
	 * @dataProvider private_addresses
	 */
	public function test_private_addresses_are_refused_without_acknowledgement( string $address ): void {
		$result = $this->validator( array( $address ) )
			->validate( 'http://solr.internal:8983/solr', 'wp', false );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'private_address', $result->code() );
		$this->assertTrue( $result->context()['needs_acknowledgement'] );
	}

	/**
	 * @dataProvider private_addresses
	 */
	public function test_private_addresses_are_allowed_once_acknowledged( string $address ): void {
		$result = $this->validator( array( $address ) )
			->validate( 'http://solr.internal:8983/solr', 'wp', true );

		$this->assertTrue( $result->is_ok(), 'Rejected ' . $address );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function private_addresses(): array {
		return array(
			array( '127.0.0.1' ),
			array( '10.1.2.3' ),
			array( '172.16.5.5' ),
			array( '192.168.1.50' ),
			array( '100.64.0.1' ),
		);
	}

	public function test_the_common_local_solr_address_works_when_acknowledged(): void {
		// http://127.0.0.1:8983/solr is the single most common real-world
		// deployment, and wp_safe_remote_get() would reject it on both the
		// loopback rule and the port rule. This is why the plugin validates
		// addresses itself.
		$result = $this->validator( array( '127.0.0.1' ) )
			->validate( 'http://127.0.0.1:8983/solr', 'wordpress', true );

		$this->assertTrue( $result->is_ok() );

		$endpoint = $result->value();
		$this->assertInstanceOf( Endpoint::class, $endpoint );
		$this->assertSame( 'http://127.0.0.1:8983/solr', $endpoint->base_url() );
	}

	// -- Scheme, credentials, shape ----------------------------------------

	/**
	 * @dataProvider bad_schemes
	 */
	public function test_only_http_and_https_are_accepted( string $url ): void {
		$result = $this->validator()->validate( $url, 'wp', true );

		$this->assertTrue( $result->is_failure() );
		$this->assertContains( $result->code(), array( 'bad_scheme', 'unparseable' ) );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function bad_schemes(): array {
		return array(
			array( 'file:///etc/passwd' ),
			array( 'gopher://solr.example.com:8983/' ),
			array( 'ftp://solr.example.com/solr' ),
			array( 'jar:http://example.com!/' ),
		);
	}

	public function test_credentials_in_the_url_are_rejected(): void {
		// Credentials in a URL end up in logs, in proxy access records and in
		// error messages. They belong in the credential fields, which redact.
		$result = $this->validator()->validate( 'https://admin:hunter2@solr.example.com:8983/solr', 'wp' );

		$this->assertSame( 'credentials_in_url', $result->code() );
	}

	public function test_a_query_string_is_rejected(): void {
		$result = $this->validator()->validate( 'https://solr.example.com:8983/solr?shards=evil', 'wp' );

		$this->assertSame( 'not_a_base_url', $result->code() );
	}

	public function test_an_empty_address_is_rejected(): void {
		$this->assertSame( 'empty_url', $this->validator()->validate( '   ' )->code() );
	}

	public function test_plain_http_to_a_remote_host_is_rejected(): void {
		$result = $this->validator( array( '203.0.113.10' ) )
			->validate( 'http://solr.example.com:8983/solr', 'wp', false );

		$this->assertSame( 'insecure_scheme', $result->code() );
	}

	public function test_https_to_a_remote_host_is_accepted(): void {
		$result = $this->validator( array( '203.0.113.10' ) )
			->validate( 'https://solr.example.com:8983/solr', 'wp' );

		$this->assertTrue( $result->is_ok() );
	}

	public function test_an_unresolvable_host_is_rejected(): void {
		$result = $this->validator( array() )->validate( 'https://nope.invalid:8983/solr', 'wp' );

		$this->assertSame( 'unresolvable', $result->code() );
	}

	// -- Ports -------------------------------------------------------------

	public function test_an_unlisted_port_is_rejected(): void {
		$result = $this->validator()->validate( 'https://solr.example.com:22/solr', 'wp' );

		$this->assertSame( 'port_not_allowed', $result->code() );
	}

	public function test_the_port_allowlist_can_be_widened(): void {
		$validator = new EndpointValidator(
			array( 9999 ),
			static function (): array {
				return array( '203.0.113.10' );
			}
		);

		$this->assertTrue( $validator->validate( 'https://solr.example.com:9999/solr', 'wp' )->is_ok() );
		$this->assertSame( 'port_not_allowed', $validator->validate( 'https://solr.example.com:8983/solr', 'wp' )->code() );
	}

	// -- Base path normalisation -------------------------------------------

	/**
	 * @dataProvider base_paths
	 */
	public function test_base_path_is_normalised( string $input, string $expected ): void {
		$result = $this->validator( array( '127.0.0.1' ) )->validate( $input, 'wp', true );

		$this->assertTrue( $result->is_ok(), 'Rejected ' . $input );
		$this->assertSame( $expected, $result->value()->base_url() );
	}

	/**
	 * Solr 10 requires base URLs to be root paths ending in "/solr", and
	 * administrators paste all of these.
	 *
	 * @return array<int,array{0:string,1:string}>
	 */
	public function base_paths(): array {
		return array(
			array( 'http://127.0.0.1:8983', 'http://127.0.0.1:8983/solr' ),
			array( 'http://127.0.0.1:8983/', 'http://127.0.0.1:8983/solr' ),
			array( 'http://127.0.0.1:8983/solr', 'http://127.0.0.1:8983/solr' ),
			array( 'http://127.0.0.1:8983/solr/', 'http://127.0.0.1:8983/solr' ),
			// A collection pasted along with the address is discarded; the
			// collection is a separate field.
			array( 'http://127.0.0.1:8983/solr/wordpress', 'http://127.0.0.1:8983/solr' ),
			// Behind a reverse proxy on a sub-path.
			array( 'http://127.0.0.1:8983/search/solr', 'http://127.0.0.1:8983/search/solr' ),
		);
	}

	public function test_the_endpoint_never_mutates_when_building_urls(): void {
		$endpoint = $this->validator( array( '127.0.0.1' ) )
			->validate( 'http://127.0.0.1:8983/solr', 'wordpress', true )
			->value();

		// The 0.1.0 prototype appended the core to its endpoint on every call,
		// so the address degraded to .../solr/post/post/post/. Building a URL
		// must be a pure function of the endpoint.
		$endpoint->collection_url( '/select' );
		$endpoint->collection_url( '/select' );
		$endpoint->admin_url( '/admin/info/system' );

		$this->assertSame( 'http://127.0.0.1:8983/solr', $endpoint->base_url() );
		$this->assertSame( 'http://127.0.0.1:8983/solr/wordpress/select', $endpoint->collection_url( '/select' ) );
		$this->assertSame( 'http://127.0.0.1:8983/solr/admin/info/system', $endpoint->admin_url( '/admin/info/system' ) );
	}

	// -- Collection names --------------------------------------------------

	public function test_a_collection_name_with_a_path_traversal_is_rejected(): void {
		$result = $this->validator( array( '127.0.0.1' ) )
			->validate( 'http://127.0.0.1:8983/solr', '../../admin/collections', true );

		$this->assertSame( 'bad_collection', $result->code() );
	}

	public function test_a_collection_name_with_a_query_string_is_rejected(): void {
		$result = $this->validator( array( '127.0.0.1' ) )
			->validate( 'http://127.0.0.1:8983/solr', 'wp?action=DELETE', true );

		$this->assertSame( 'bad_collection', $result->code() );
	}

	public function test_an_ordinary_collection_name_is_accepted(): void {
		$result = $this->validator( array( '127.0.0.1' ) )
			->validate( 'http://127.0.0.1:8983/solr', 'wp-site_1.a', true );

		$this->assertTrue( $result->is_ok() );
		$this->assertSame( 'wp-site_1.a', $result->value()->collection() );
	}

	public function test_resolved_addresses_are_recorded_for_later_comparison(): void {
		$endpoint = $this->validator( array( '203.0.113.10', '203.0.113.11' ) )
			->validate( 'https://solr.example.com:8983/solr', 'wp' )
			->value();

		$this->assertSame( array( '203.0.113.10', '203.0.113.11' ), $endpoint->resolved_ips() );
	}

	public function test_a_host_resolving_to_both_public_and_private_is_rejected(): void {
		// DNS returning a public address alongside a private one is a classic
		// rebinding setup. One bad answer is enough to reject the whole host.
		$result = $this->validator( array( '203.0.113.10', '127.0.0.1' ) )
			->validate( 'https://solr.example.com:8983/solr', 'wp', false );

		$this->assertSame( 'private_address', $result->code() );
	}
}
