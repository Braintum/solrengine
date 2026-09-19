<?php
/**
 * Result value object tests.
 *
 * @package SolrEngine
 */

declare( strict_types=1 );

namespace SolrEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SolrEngine\Contracts\Result;

/**
 * @covers \SolrEngine\Contracts\Result
 */
final class ResultTest extends TestCase {

	public function test_success_reports_ok(): void {
		$result = Result::success( 'payload' );

		$this->assertTrue( $result->is_ok() );
		$this->assertFalse( $result->is_failure() );
		$this->assertSame( 'payload', $result->value() );
		$this->assertSame( '', $result->code() );
	}

	public function test_failure_carries_a_stable_code(): void {
		$result = Result::failure( 'backend_unavailable', 'Could not connect.' );

		$this->assertTrue( $result->is_failure() );
		$this->assertSame( 'backend_unavailable', $result->code() );
		$this->assertSame( 'Could not connect.', $result->message() );
	}

	public function test_failure_value_returns_the_fallback(): void {
		$result = Result::failure( 'nope' );

		// Callers branch on is_failure(), but a caller that forgets must still
		// get a usable default rather than null propagating somewhere distant.
		$this->assertSame( 'fallback', $result->value( 'fallback' ) );
	}

	public function test_success_payload_may_be_null(): void {
		$result = Result::success();

		$this->assertTrue( $result->is_ok() );
		$this->assertNull( $result->value() );
	}
}
