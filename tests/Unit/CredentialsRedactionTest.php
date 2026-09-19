<?php
/**
 * Credential redaction tests.
 *
 * Security tests, not housekeeping. A password reaching a log file is a
 * disclosure whether or not anyone reads it, and attaching context to an error
 * is the most likely way for one to get there.
 *
 * redact() is static and pure, so it is unit-testable without WordPress.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Support\Credentials;

/**
 * @covers \SolrEngine\Support\Credentials::redact
 */
final class CredentialsRedactionTest extends TestCase {

	/**
	 * @dataProvider secret_keys
	 */
	public function test_secret_keys_are_removed( string $key ): void {
		$clean = Credentials::redact( array( $key => 'hunter2' ) );

		$this->assertSame( '[redacted]', $clean[ $key ] );
		$this->assertStringNotContainsString( 'hunter2', (string) json_encode( $clean ) );
	}

	/**
	 * @return array<int,array{0:string}>
	 */
	public function secret_keys(): array {
		return array(
			array( 'password' ),
			array( 'pass' ),
			array( 'secret' ),
			array( 'token' ),
			array( 'api_key' ),
			array( 'authorization' ),
			array( 'auth' ),
		);
	}

	public function test_key_matching_is_case_insensitive(): void {
		$clean = Credentials::redact(
			array(
				'Password' => 'hunter2',
				'API_KEY'  => 'abc',
			)
		);

		$this->assertSame( '[redacted]', $clean['Password'] );
		$this->assertSame( '[redacted]', $clean['API_KEY'] );
	}

	public function test_nested_context_is_redacted(): void {
		$clean = Credentials::redact(
			array(
				'request' => array(
					'headers' => array( 'authorization' => 'Basic abc123' ),
				),
			)
		);

		$this->assertSame( '[redacted]', $clean['request']['headers']['authorization'] );
	}

	public function test_credentials_embedded_in_a_url_are_removed(): void {
		$clean = Credentials::redact(
			array( 'endpoint' => 'https://admin:hunter2@solr.example.com:8983/solr' )
		);

		$this->assertStringNotContainsString( 'hunter2', $clean['endpoint'] );
		$this->assertStringNotContainsString( 'admin', $clean['endpoint'] );
		$this->assertStringContainsString( 'solr.example.com', $clean['endpoint'] );
	}

	public function test_urls_without_credentials_are_untouched(): void {
		$url   = 'https://solr.example.com:8983/solr/wordpress';
		$clean = Credentials::redact( array( 'endpoint' => $url ) );

		$this->assertSame( $url, $clean['endpoint'] );
	}

	public function test_an_email_address_is_not_mistaken_for_credentials(): void {
		$clean = Credentials::redact( array( 'note' => 'contact me@example.com about it' ) );

		$this->assertSame( 'contact me@example.com about it', $clean['note'] );
	}

	public function test_ordinary_values_survive(): void {
		$clean = Credentials::redact(
			array(
				'collection' => 'wordpress',
				'batch_size' => 100,
				'healthy'    => true,
			)
		);

		$this->assertSame( 'wordpress', $clean['collection'] );
		$this->assertSame( 100, $clean['batch_size'] );
		$this->assertTrue( $clean['healthy'] );
	}
}
