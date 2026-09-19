<?php
/**
 * Applied schema verdict tests.
 *
 * The fingerprint is what distinguishes "the schema is as we left it" from
 * "somebody changed it last week". Without that distinction the first symptom of
 * an edited schema is indexing failing for reasons no error message explains.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Schema\AppliedSchema;

/**
 * @covers \SolrEngine\Schema\AppliedSchema
 */
final class AppliedSchemaTest extends TestCase {

	public function test_a_matching_fingerprint_is_current(): void {
		$this->assertSame(
			AppliedSchema::CURRENT,
			AppliedSchema::verdict( 1, 'abc123', 1, 1, 'abc123' )
		);
	}

	public function test_a_changed_fingerprint_is_reported_as_altered(): void {
		$this->assertSame(
			AppliedSchema::ALTERED,
			AppliedSchema::verdict( 1, 'abc123', 1, 1, 'def456' )
		);
	}

	public function test_an_old_live_schema_is_behind(): void {
		$this->assertSame(
			AppliedSchema::BEHIND,
			AppliedSchema::verdict( 1, 'abc123', 2, 1, 'abc123' )
		);
	}

	public function test_behind_wins_over_altered(): void {
		// Both are true here: the schema is out of date and its fields differ
		// from what was recorded. The action is the same for both — apply the
		// plan — and telling an administrator their schema was tampered with
		// when it is merely old sends them looking for an intruder.
		$this->assertSame(
			AppliedSchema::BEHIND,
			AppliedSchema::verdict( 1, 'abc123', 2, 1, 'totally-different' )
		);
	}

	public function test_a_schema_this_site_did_not_apply_is_unrecorded(): void {
		// Adequate, but applied by hand or before the plugin was installed.
		// There is nothing to compare a future change against yet, which is a
		// different statement from "everything is verified".
		$this->assertSame(
			AppliedSchema::UNRECORDED,
			AppliedSchema::verdict( 0, '', 1, 1, 'abc123' )
		);
	}

	public function test_a_recorded_version_without_a_fingerprint_is_unrecorded(): void {
		$this->assertSame(
			AppliedSchema::UNRECORDED,
			AppliedSchema::verdict( 1, '', 1, 1, 'abc123' )
		);
	}

	public function test_a_newer_live_schema_than_required_is_not_behind(): void {
		// After a downgrade, or a partially restored backup. The fields a newer
		// contract added are still there and still harmless, so the build that
		// needs fewer of them has nothing to do.
		$this->assertSame(
			AppliedSchema::CURRENT,
			AppliedSchema::verdict( 2, 'abc123', 1, 2, 'abc123' )
		);
	}
}
