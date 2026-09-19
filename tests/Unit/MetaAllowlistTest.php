<?php
/**
 * Meta allowlist tests.
 *
 * The allowlist is a privacy control. The 0.1.0 prototype indexed every meta
 * key a post had, including private ones, which puts licence keys, API tokens
 * and personal data into a search index.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\FieldType;
use SolrEngine\Documents\MetaAllowlist;

/**
 * @covers \SolrEngine\Documents\MetaAllowlist
 */
final class MetaAllowlistTest extends TestCase {

	public function test_nothing_is_allowed_by_default(): void {
		$list = new MetaAllowlist();

		$this->assertTrue( $list->is_empty() );
		$this->assertFalse( $list->allows( 'anything' ) );
		$this->assertFalse( $list->allows( '_secret_api_key' ) );
	}

	public function test_an_added_key_is_allowed(): void {
		$list = new MetaAllowlist();

		$this->assertTrue( $list->add( 'price', FieldType::FLOAT )->is_ok() );
		$this->assertTrue( $list->allows( 'price' ) );
		$this->assertSame( FieldType::FLOAT, $list->type_of( 'price' ) );
	}

	public function test_an_unknown_type_is_refused(): void {
		$result = ( new MetaAllowlist() )->add( 'price', 'quantum' );

		$this->assertSame( 'bad_type', $result->code() );
	}

	public function test_an_empty_key_is_refused(): void {
		$this->assertSame( 'empty_key', ( new MetaAllowlist() )->add( '  ', FieldType::STRING )->code() );
	}

	public function test_two_keys_that_would_share_a_field_are_refused(): void {
		// "a-b" and "a.b" both sanitise to "a_b". Without this check they
		// would silently overwrite each other in every document, and the
		// symptom would be a filter that mysteriously returns wrong results.
		$list = new MetaAllowlist();

		$this->assertTrue( $list->add( 'a-b', FieldType::STRING )->is_ok() );

		$collision = $list->add( 'a.b', FieldType::STRING );

		$this->assertSame( 'field_collision', $collision->code() );
		$this->assertStringContainsString( 'a-b', $collision->message() );
	}

	public function test_re_adding_the_same_key_is_allowed(): void {
		$list = new MetaAllowlist();

		$list->add( 'price', FieldType::FLOAT );

		$this->assertTrue( $list->add( 'price', FieldType::STRING )->is_ok() );
		$this->assertSame( FieldType::STRING, $list->type_of( 'price' ) );
	}

	public function test_invalid_stored_entries_are_dropped_rather_than_failing(): void {
		// One bad row in stored configuration must not stop a site indexing.
		$list = MetaAllowlist::from_config(
			array(
				'price'  => FieldType::FLOAT,
				'broken' => 'not-a-type',
			)
		);

		$this->assertTrue( $list->allows( 'price' ) );
		$this->assertFalse( $list->allows( 'broken' ) );
	}

	public function test_private_keys_are_recognised(): void {
		// Not blocked outright: a store's price really does live in "_price".
		// They are hidden from the UI by default so indexing one is a choice.
		$this->assertTrue( MetaAllowlist::is_private( '_price' ) );
		$this->assertFalse( MetaAllowlist::is_private( 'price' ) );
	}

	public function test_a_private_key_can_be_allowed_deliberately(): void {
		$list = new MetaAllowlist();

		$this->assertTrue( $list->add( '_price', FieldType::FLOAT )->is_ok() );
		$this->assertTrue( $list->allows( '_price' ) );
	}
}
