<?php
/**
 * Package writer.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\PackagePath;
use SelectiveEntitySync\Manifest\Schema;
use ZipArchive;

/**
 * Writes a manifest and its files to a zip package.
 */
class PackageWriter {

	/**
	 * Manifest codec.
	 *
	 * @var ManifestCodec
	 */
	private $codec;

	/**
	 * Schema validator.
	 *
	 * @var Schema
	 */
	private $schema;

	/**
	 * Constructor.
	 *
	 * @param ManifestCodec $codec  Manifest codec.
	 * @param Schema        $schema Schema validator.
	 */
	public function __construct( ManifestCodec $codec, Schema $schema ) {
		$this->codec  = $codec;
		$this->schema = $schema;
	}

	/**
	 * Writes a package.
	 *
	 * File entries (hash and size) are computed here from the given sources and
	 * added to a copy of the manifest; the passed manifest is not modified.
	 *
	 * @param Manifest              $manifest    Manifest with its entities. Must not already list files.
	 * @param array<string, string> $files       Map of package path => absolute local source path.
	 * @param string                $destination Absolute path of the zip file to create (overwritten if it exists).
	 * @return Manifest The manifest as written, including file entries.
	 * @throws PackageException When a file is unreadable or the zip cannot be written.
	 */
	public function write( Manifest $manifest, array $files, string $destination ): Manifest {
		$this->assert_zip_available();

		$manifest = clone $manifest;
		foreach ( $files as $package_path => $source_path ) {
			$package_path = (string) $package_path;
			if ( ! PackagePath::is_valid( $package_path ) ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The file path "%s" is not allowed in a package.', 'selective-entity-sync' ),
						$package_path
					)
				);
			}
			if ( ! is_file( $source_path ) || ! is_readable( $source_path ) ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The file for %s could not be read.', 'selective-entity-sync' ),
						$package_path
					)
				);
			}

			$manifest->add_file( $package_path, (string) hash_file( 'sha256', $source_path ), (int) filesize( $source_path ) );
		}

		/**
		 * Filters the manifest data before it is written to a package.
		 *
		 * Use this to add custom data to entities or to the manifest. The `files`
		 * list is managed by the plugin: changes to it are ignored. The result
		 * must still be a valid manifest.
		 *
		 * @since 0.1.0
		 *
		 * @param array    $data     Manifest data (see Manifest::to_array()).
		 * @param Manifest $manifest The manifest being written.
		 */
		$data = apply_filters( 'selective_entity_sync_manifest_data', $manifest->to_array(), $manifest );
		if ( ! is_array( $data ) ) {
			$data = $manifest->to_array();
		}
		$data['files'] = $manifest->get_files();

		$this->schema->validate( $data );
		$json = $this->codec->encode( $data );

		$this->write_zip( $json, $files, $destination );

		return Manifest::from_array( $data );
	}

	/**
	 * Writes the zip archive.
	 *
	 * @param string                $json        Encoded manifest.
	 * @param array<string, string> $files       Map of package path => absolute local source path.
	 * @param string                $destination Absolute path of the zip file.
	 * @return void
	 * @throws PackageException When the zip cannot be written.
	 */
	private function write_zip( string $json, array $files, string $destination ): void {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $destination, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
			throw new PackageException( __( 'The package file could not be created.', 'selective-entity-sync' ) );
		}

		$ok = $zip->addFromString( PackagePath::MANIFEST_FILE, $json );
		foreach ( $files as $package_path => $source_path ) {
			$ok = $ok && $zip->addFile( $source_path, (string) $package_path );
		}

		if ( ! $ok || ! $zip->close() ) {
			throw new PackageException( __( 'The package file could not be written.', 'selective-entity-sync' ) );
		}
	}

	/**
	 * Ensures the PHP zip extension is available.
	 *
	 * @return void
	 * @throws PackageException When ZipArchive is missing.
	 */
	private function assert_zip_available(): void {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new PackageException( __( 'The PHP zip extension is required to create packages.', 'selective-entity-sync' ) );
		}
	}
}
