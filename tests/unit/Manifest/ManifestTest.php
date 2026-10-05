<?php
/**
 * Tests for Manifest.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Manifest;

use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Manifest\ManifestException;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Manifest\Manifest
 */
class ManifestTest extends TestCase {

	use ManifestFixtures;

	public function test_round_trips_through_array(): void {
		$data = $this->valid_manifest_data();

		$this->assertSame( $data, Manifest::from_array( $data )->to_array() );
	}

	public function test_entity_uuids_are_normalised_to_lowercase(): void {
		$manifest = $this->empty_manifest();
		$manifest->add_entity(
			array(
				'uuid' => '3F2B8C1E-4A5D-4E6F-8A9B-0C1D2E3F4A5B',
				'type' => 'post',
				'data' => array(),
			)
		);

		$this->assertTrue( $manifest->has_entity( '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b' ) );
		$this->assertSame( '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b', $manifest->get_entities()[0]['uuid'] );
	}

	public function test_get_entity_returns_null_when_missing(): void {
		$this->assertNull( $this->empty_manifest()->get_entity( '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b' ) );
	}

	public function test_rejects_duplicate_entity(): void {
		$manifest = $this->empty_manifest();
		$entity   = array(
			'uuid' => '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b',
			'type' => 'post',
			'data' => array(),
		);
		$manifest->add_entity( $entity );

		$this->expectException( ManifestException::class );
		$manifest->add_entity( $entity );
	}

	public function test_rejects_entity_without_valid_uuid(): void {
		$this->expectException( ManifestException::class );

		$this->empty_manifest()->add_entity(
			array(
				'uuid' => '42',
				'type' => 'post',
				'data' => array(),
			)
		);
	}

	public function test_rejects_unsafe_file_path(): void {
		$this->expectException( ManifestException::class );

		$this->empty_manifest()->add_file( '../evil.php', str_repeat( 'a', 64 ), 1 );
	}

	public function test_rejects_duplicate_file(): void {
		$manifest = $this->empty_manifest();
		$manifest->add_file( 'media/a.jpg', str_repeat( 'a', 64 ), 1 );

		$this->expectException( ManifestException::class );
		$manifest->add_file( 'media/a.jpg', str_repeat( 'b', 64 ), 2 );
	}

	private function empty_manifest(): Manifest {
		$data = $this->valid_manifest_data();

		return new Manifest( $data['generator'], $data['source'] );
	}
}
