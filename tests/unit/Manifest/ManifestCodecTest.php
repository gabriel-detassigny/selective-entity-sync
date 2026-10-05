<?php
/**
 * Tests for ManifestCodec.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Manifest;

use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\ManifestException;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Manifest\ManifestCodec
 */
class ManifestCodecTest extends TestCase {

	use ManifestFixtures;

	public function test_encode_then_decode_round_trips(): void {
		$codec = new ManifestCodec( new Schema() );
		$data  = $this->valid_manifest_data();

		$json = $codec->encode( $data );

		$this->assertStringContainsString( '"schema_version": 1', $json );
		$this->assertStringContainsString( 'https://staging.example.com', $json, 'Slashes are not escaped.' );
		$this->assertSame( $data, $codec->decode( $json )->to_array() );
	}

	public function test_decode_rejects_malformed_json(): void {
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'not valid JSON' );

		( new ManifestCodec( new Schema() ) )->decode( '{"schema_version": 1,' );
	}

	public function test_decode_rejects_non_object_json(): void {
		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'must be a JSON object' );

		( new ManifestCodec( new Schema() ) )->decode( '"hello"' );
	}

	public function test_decode_rejects_excessive_nesting(): void {
		$data                                = $this->valid_manifest_data();
		$data['entities'][0]['data']['deep'] = $this->nest( ManifestCodec::MAX_DEPTH + 1 );

		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( 'not valid JSON' );

		( new ManifestCodec( new Schema() ) )->decode( (string) json_encode( $data ) );
	}

	public function test_decode_validates_schema(): void {
		$data = $this->valid_manifest_data();
		unset( $data['source'] );

		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( '"source"' );

		( new ManifestCodec( new Schema() ) )->decode( (string) json_encode( $data ) );
	}

	/**
	 * @return array<mixed>
	 */
	private function nest( int $depth ): array {
		$value = array();
		for ( $i = 0; $i < $depth; $i++ ) {
			$value = array( $value );
		}
		return $value;
	}
}
