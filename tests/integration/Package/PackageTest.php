<?php
/**
 * Tests for PackageWriter and PackageReader.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Package;

use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\PackageException;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Package\PackageWriter;
use SelectiveEntitySync\Storage\TempStorage;
use WP_UnitTestCase;
use ZipArchive;

/**
 * @covers \SelectiveEntitySync\Package\PackageWriter
 * @covers \SelectiveEntitySync\Package\PackageReader
 * @covers \SelectiveEntitySync\Package\Package
 */
class PackageTest extends WP_UnitTestCase {

	private const POST_UUID = '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b';

	private const IMAGE_PATH = 'media/3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b/photo.jpg';

	/**
	 * @var TempStorage
	 */
	private $storage;

	/**
	 * Working directory for fixtures and output.
	 *
	 * @var string
	 */
	private $work_dir;

	/**
	 * Empty directory to extract into.
	 *
	 * @var string
	 */
	private $extract_dir;

	public function set_up(): void {
		parent::set_up();
		$this->storage     = new TempStorage();
		$this->work_dir    = $this->storage->create_directory();
		$this->extract_dir = $this->storage->create_directory();
	}

	public function tear_down(): void {
		$this->storage->delete( $this->work_dir );
		$this->storage->delete( $this->extract_dir );
		parent::tear_down();
	}

	public function test_write_then_read_round_trips_manifest_and_files(): void {
		$source = $this->fixture_file( 'photo.jpg', 'fake image bytes' );
		$zip    = $this->work_dir . '/package.zip';

		$written = $this->writer()->write( $this->manifest(), array( self::IMAGE_PATH => $source ), $zip );

		$this->assertSame(
			array(
				array(
					'path'   => self::IMAGE_PATH,
					'sha256' => hash( 'sha256', 'fake image bytes' ),
					'size'   => 16,
				),
			),
			$written->get_files()
		);

		$package = $this->reader()->read( $zip, $this->extract_dir );

		$this->assertSame( $written->to_array(), $package->get_manifest()->to_array() );
		$this->assertSame( 'fake image bytes', file_get_contents( $package->get_file_path( self::IMAGE_PATH ) ) );
	}

	public function test_write_does_not_modify_the_given_manifest(): void {
		$manifest = $this->manifest();

		$this->writer()->write( $manifest, array( self::IMAGE_PATH => $this->fixture_file( 'photo.jpg', 'x' ) ), $this->work_dir . '/p.zip' );

		$this->assertSame( array(), $manifest->get_files() );
	}

	public function test_read_manifest_does_not_extract_files(): void {
		$zip = $this->work_dir . '/package.zip';
		$this->writer()->write( $this->manifest(), array( self::IMAGE_PATH => $this->fixture_file( 'photo.jpg', 'x' ) ), $zip );

		$manifest = $this->reader()->read_manifest( $zip );

		$this->assertTrue( $manifest->has_entity( self::POST_UUID ) );
		$this->assertSame( array( '.', '..' ), scandir( $this->extract_dir ) );
	}

	public function test_manifest_data_filter_can_add_data_but_not_change_files(): void {
		add_filter(
			'selective_entity_sync_manifest_data',
			static function ( array $data ) {
				$data['entities'][0]['data']['custom'] = 'added';
				$data['files']                         = array();
				return $data;
			}
		);
		$zip = $this->work_dir . '/package.zip';

		$this->writer()->write( $this->manifest(), array( self::IMAGE_PATH => $this->fixture_file( 'photo.jpg', 'x' ) ), $zip );
		$manifest = $this->reader()->read_manifest( $zip );

		$this->assertSame( 'added', $manifest->get_entity( self::POST_UUID )['data']['custom'] );
		$this->assertCount( 1, $manifest->get_files() );
	}

	public function test_get_file_path_rejects_undeclared_files(): void {
		$zip = $this->work_dir . '/package.zip';
		$this->writer()->write( $this->manifest(), array(), $zip );
		$package = $this->reader()->read( $zip, $this->extract_dir );

		$this->expectException( PackageException::class );
		$package->get_file_path( 'media/other.jpg' );
	}

	public function test_writer_rejects_unsafe_paths_and_missing_sources(): void {
		try {
			$this->writer()->write( $this->manifest(), array( '../evil.jpg' => $this->fixture_file( 'a.jpg', 'x' ) ), $this->work_dir . '/p.zip' );
			$this->fail( 'Unsafe path accepted.' );
		} catch ( PackageException $e ) {
			$this->assertStringContainsString( 'not allowed', $e->getMessage() );
		}

		$this->expectException( PackageException::class );
		$this->writer()->write( $this->manifest(), array( self::IMAGE_PATH => $this->work_dir . '/missing.jpg' ), $this->work_dir . '/p.zip' );
	}

	/**
	 * @dataProvider malicious_packages
	 *
	 * @param callable $build              Receives ZipArchive, the encoded valid manifest data, and the image bytes.
	 * @param string   $expected_reason    Substring expected in the exception message.
	 * @param bool     $fails_after_extract Whether the problem can only be detected after extraction (checksums).
	 */
	public function test_reader_rejects_malicious_packages( callable $build, string $expected_reason, bool $fails_after_extract = false ): void {
		$zip_path = $this->work_dir . '/evil.zip';
		$zip      = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE );
		$build( $zip, $this->valid_data_with_image( 'image bytes' ), 'image bytes' );
		$zip->close();

		try {
			$this->reader()->read( $zip_path, $this->extract_dir );
			$this->fail( 'Malicious package was accepted.' );
		} catch ( PackageException $e ) {
			$this->assertStringContainsString( $expected_reason, $e->getMessage() );
		}

		if ( ! $fails_after_extract ) {
			$this->assertSame( array( '.', '..' ), scandir( $this->extract_dir ), 'Nothing is extracted when structural checks fail.' );
		}
		$this->assertFileDoesNotExist( dirname( $this->extract_dir ) . '/evil.php' );
	}

	/**
	 * @return array<string, array{0: callable, 1: string, 2?: bool}>
	 */
	public function malicious_packages(): array {
		return array(
			'no manifest'                => array(
				static function ( ZipArchive $zip, array $data, string $bytes ) {
					$zip->addFromString( self::IMAGE_PATH, $bytes );
				},
				'does not contain a manifest',
			),
			'invalid manifest json'      => array(
				static function ( ZipArchive $zip ) {
					$zip->addFromString( 'manifest.json', '{not json' );
				},
				'not valid JSON',
			),
			'zip slip entry'             => array(
				static function ( ZipArchive $zip, array $data, string $bytes ) {
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
					$zip->addFromString( self::IMAGE_PATH, $bytes );
					$zip->addFromString( '../evil.php', '<?php echo "pwned";' );
				},
				'unexpected file',
			),
			'undeclared extra file'      => array(
				static function ( ZipArchive $zip, array $data, string $bytes ) {
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
					$zip->addFromString( self::IMAGE_PATH, $bytes );
					$zip->addFromString( 'media/extra.jpg', $bytes );
				},
				'unexpected file',
			),
			'declared php file'          => array(
				static function ( ZipArchive $zip, array $data ) {
					$data['files'][0]['path']   = 'media/shell.php';
					$data['files'][0]['sha256'] = hash( 'sha256', '<?php' );
					$data['files'][0]['size']   = 5;
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
					$zip->addFromString( 'media/shell.php', '<?php' );
				},
				'file type that is not allowed',
			),
			'missing declared file'      => array(
				static function ( ZipArchive $zip, array $data ) {
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
				},
				'missing the file',
			),
			'size mismatch (zip bomb)'   => array(
				static function ( ZipArchive $zip, array $data, string $bytes ) {
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
					$zip->addFromString( self::IMAGE_PATH, $bytes . str_repeat( "\0", 100000 ) );
				},
				'does not match the manifest',
			),
			'tampered content same size' => array(
				static function ( ZipArchive $zip, array $data, string $bytes ) {
					$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
					$zip->addFromString( self::IMAGE_PATH, strrev( $bytes ) );
				},
				'checksum does not match',
				true,
			),
		);
	}

	public function test_reader_rejects_non_zip_files(): void {
		$path = $this->fixture_file( 'not-a-zip.zip', 'hello' );

		$this->expectException( PackageException::class );
		$this->expectExceptionMessage( 'not a valid package' );

		$this->reader()->read( $path, $this->extract_dir );
	}

	public function test_reader_enforces_filtered_size_limits(): void {
		$zip = $this->work_dir . '/package.zip';
		$this->writer()->write( $this->manifest(), array( self::IMAGE_PATH => $this->fixture_file( 'photo.jpg', 'x' ) ), $zip );

		add_filter(
			'selective_entity_sync_package_limits',
			static function ( array $limits ) {
				$limits['max_package_size'] = 10;
				return $limits;
			}
		);

		$this->expectException( PackageException::class );
		$this->expectExceptionMessage( 'larger than the maximum' );

		$this->reader()->read( $zip, $this->extract_dir );
	}

	public function test_reader_rejects_manifest_from_newer_schema(): void {
		$data                   = $this->valid_data_with_image( 'x' );
		$data['schema_version'] = Schema::VERSION + 1;
		$zip_path               = $this->work_dir . '/future.zip';
		$zip                    = new ZipArchive();
		$zip->open( $zip_path, ZipArchive::CREATE );
		$zip->addFromString( 'manifest.json', (string) wp_json_encode( $data ) );
		$zip->close();

		$this->expectException( PackageException::class );
		$this->expectExceptionMessage( 'Please update' );

		$this->reader()->read_manifest( $zip_path );
	}

	private function writer(): PackageWriter {
		return new PackageWriter( new ManifestCodec( new Schema() ), new Schema() );
	}

	private function reader(): PackageReader {
		return new PackageReader( new ManifestCodec( new Schema() ), new PackageLimits() );
	}

	private function manifest(): Manifest {
		$manifest = new Manifest(
			array(
				'plugin'  => 'selective-entity-sync',
				'version' => '0.1.0',
			),
			array(
				'site_url'    => 'https://staging.example.com',
				'wp_version'  => '6.9',
				'exported_at' => '2026-10-05T12:00:00+00:00',
			)
		);
		$manifest->add_entity(
			array(
				'uuid' => self::POST_UUID,
				'type' => 'post',
				'data' => array( 'post_title' => 'Hello' ),
			)
		);

		return $manifest;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function valid_data_with_image( string $bytes ): array {
		$manifest = $this->manifest();
		$manifest->add_file( self::IMAGE_PATH, hash( 'sha256', $bytes ), strlen( $bytes ) );

		return $manifest->to_array();
	}

	private function fixture_file( string $name, string $contents ): string {
		$path = $this->work_dir . '/' . $name;
		file_put_contents( $path, $contents );

		return $path;
	}
}
