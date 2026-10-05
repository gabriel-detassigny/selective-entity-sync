<?php
/**
 * Tests for PackageLimits.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Package;

use Brain\Monkey\Filters;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Package\PackageLimits
 */
class PackageLimitsTest extends TestCase {

	public function test_returns_defaults(): void {
		$this->assertSame( PackageLimits::DEFAULTS, ( new PackageLimits() )->get() );
	}

	public function test_valid_filtered_values_are_used_and_invalid_ones_ignored(): void {
		Filters\expectApplied( 'selective_entity_sync_package_limits' )
			->once()
			->with( PackageLimits::DEFAULTS )
			->andReturn(
				array(
					'max_package_size'  => 1024,
					'max_manifest_size' => -5,
					'max_entries'       => '10',
				)
			);

		$limits = ( new PackageLimits() )->get();

		$this->assertSame( 1024, $limits['max_package_size'] );
		$this->assertSame( PackageLimits::DEFAULTS['max_manifest_size'], $limits['max_manifest_size'] );
		$this->assertSame( PackageLimits::DEFAULTS['max_entries'], $limits['max_entries'] );
		$this->assertSame( PackageLimits::DEFAULTS['max_uncompressed_size'], $limits['max_uncompressed_size'] );
	}

	public function test_non_array_filter_result_falls_back_to_defaults(): void {
		Filters\expectApplied( 'selective_entity_sync_package_limits' )->andReturn( false );

		$this->assertSame( PackageLimits::DEFAULTS, ( new PackageLimits() )->get() );
	}
}
