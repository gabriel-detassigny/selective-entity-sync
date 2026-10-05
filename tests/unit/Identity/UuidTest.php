<?php
/**
 * Tests for Uuid.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Identity;

use SelectiveEntitySync\Identity\Uuid;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Identity\Uuid
 */
class UuidTest extends TestCase {

	public function test_accepts_canonical_uuids_in_any_case(): void {
		$this->assertTrue( Uuid::is_valid( '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b' ) );
		$this->assertTrue( Uuid::is_valid( '3F2B8C1E-4A5D-4E6F-8A9B-0C1D2E3F4A5B' ) );
	}

	public function test_rejects_malformed_uuids(): void {
		$this->assertFalse( Uuid::is_valid( '' ) );
		$this->assertFalse( Uuid::is_valid( '3f2b8c1e4a5d4e6f8a9b0c1d2e3f4a5b' ) );
		$this->assertFalse( Uuid::is_valid( '{3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b}' ) );
		$this->assertFalse( Uuid::is_valid( "3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b\n" ) );
		$this->assertFalse( Uuid::is_valid( 'zzzzzzzz-4a5d-4e6f-8a9b-0c1d2e3f4a5b' ) );
	}
}
