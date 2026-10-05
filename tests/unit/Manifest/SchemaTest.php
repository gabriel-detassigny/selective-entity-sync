<?php
/**
 * Tests for Schema.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Manifest;

use SelectiveEntitySync\Manifest\ManifestException;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Tests\Unit\TestCase;

/**
 * @covers \SelectiveEntitySync\Manifest\Schema
 */
class SchemaTest extends TestCase {

	use ManifestFixtures;

	public function test_valid_manifest_passes(): void {
		( new Schema() )->validate( $this->valid_manifest_data() );

		$this->addToAssertionCount( 1 );
	}

	public function test_reference_only_entities_are_valid(): void {
		$data                                  = $this->valid_manifest_data();
		$data['entities'][0]['reference_only'] = true;

		( new Schema() )->validate( $data );

		$this->addToAssertionCount( 1 );
	}

	public function test_empty_entities_and_files_are_valid(): void {
		( new Schema() )->validate(
			$this->valid_manifest_data(
				array(
					'entities' => array(),
					'files'    => array(),
				)
			)
		);

		$this->addToAssertionCount( 1 );
	}

	/**
	 * @dataProvider invalid_manifests
	 *
	 * @param callable $mutate          Mutates valid data into invalid data.
	 * @param string   $expected_reason Substring expected in the exception message.
	 */
	public function test_invalid_manifest_is_rejected( callable $mutate, string $expected_reason ): void {
		$data = $mutate( $this->valid_manifest_data() );

		$this->expectException( ManifestException::class );
		$this->expectExceptionMessage( $expected_reason );

		( new Schema() )->validate( $data );
	}

	/**
	 * @return array<string, array{callable, string}>
	 */
	public function invalid_manifests(): array {
		return array(
			'missing schema version'  => array(
				static function ( $d ) {
					unset( $d['schema_version'] );
					return $d;
				},
				'schema version',
			),
			'string schema version'   => array(
				static function ( $d ) {
					$d['schema_version'] = '1';
					return $d;
				},
				'schema version',
			),
			'newer schema version'    => array(
				static function ( $d ) {
					$d['schema_version'] = Schema::VERSION + 1;
					return $d;
				},
				'Please update',
			),
			'missing generator'       => array(
				static function ( $d ) {
					unset( $d['generator'] );
					return $d;
				},
				'"generator"',
			),
			'empty source site url'   => array(
				static function ( $d ) {
					$d['source']['site_url'] = '';
					return $d;
				},
				'"source.site_url"',
			),
			'entities not a list'     => array(
				static function ( $d ) {
					$d['entities'] = array( 'a' => $d['entities'][0] );
					return $d;
				},
				'"entities"',
			),
			'invalid entity uuid'     => array(
				static function ( $d ) {
					$d['entities'][1]['uuid'] = 'not-a-uuid';
					return $d;
				},
				'"entities[1].uuid"',
			),
			'invalid entity type'     => array(
				static function ( $d ) {
					$d['entities'][0]['type'] = 'Post Type!';
					return $d;
				},
				'"entities[0].type"',
			),
			'missing source id'       => array(
				static function ( $d ) {
					unset( $d['entities'][0]['source_id'] );
					return $d;
				},
				'"entities[0].source_id"',
			),
			'zero source id'          => array(
				static function ( $d ) {
					$d['entities'][0]['source_id'] = 0;
					return $d;
				},
				'"entities[0].source_id"',
			),
			'string reference_only'   => array(
				static function ( $d ) {
					$d['entities'][0]['reference_only'] = 'yes';
					return $d;
				},
				'"entities[0].reference_only"',
			),
			'entity data not object'  => array(
				static function ( $d ) {
					$d['entities'][0]['data'] = 'x';
					return $d;
				},
				'"entities[0].data"',
			),
			'duplicate uuid (case)'   => array(
				static function ( $d ) {
					$d['entities'][1]['uuid'] = strtoupper( $d['entities'][0]['uuid'] );
					return $d;
				},
				'more than once',
			),
			'path traversal'          => array(
				static function ( $d ) {
					$d['files'][0]['path'] = '../wp-config.php';
					return $d;
				},
				'"files[0].path"',
			),
			'absolute path'           => array(
				static function ( $d ) {
					$d['files'][0]['path'] = '/etc/passwd';
					return $d;
				},
				'"files[0].path"',
			),
			'sha256 trailing newline' => array(
				static function ( $d ) {
					$d['files'][0]['sha256'] .= "\n";
					return $d;
				},
				'"files[0].sha256"',
			),
			'type trailing newline'   => array(
				static function ( $d ) {
					$d['entities'][0]['type'] = "post\n";
					return $d;
				},
				'"entities[0].type"',
			),
			'bad sha256'              => array(
				static function ( $d ) {
					$d['files'][0]['sha256'] = 'abc';
					return $d;
				},
				'"files[0].sha256"',
			),
			'negative size'           => array(
				static function ( $d ) {
					$d['files'][0]['size'] = -1;
					return $d;
				},
				'"files[0].size"',
			),
			'duplicate file path'     => array(
				static function ( $d ) {
					$d['files'][] = $d['files'][0];
					return $d;
				},
				'more than once',
			),
		);
	}
}
