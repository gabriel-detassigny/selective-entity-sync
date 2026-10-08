<?php
/**
 * Extracted package.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

use SelectiveEntitySync\Manifest\Manifest;
use ZipArchive;

/**
 * A validated package whose files are available in a local directory.
 *
 * Packages read with PackageReader::read() are fully extracted up front.
 * Packages opened with PackageReader::open() extract each file the first time
 * it is requested and verify its checksum then, so a batch only extracts the
 * files it needs.
 */
class Package {

	/**
	 * Validated manifest.
	 *
	 * @var Manifest
	 */
	private $manifest;

	/**
	 * Absolute path of the extraction directory, without trailing slash.
	 *
	 * @var string
	 */
	private $directory;

	/**
	 * Zip to extract files from on demand, or null when already fully extracted.
	 *
	 * @var string|null
	 */
	private $zip_path;

	/**
	 * Constructor.
	 *
	 * @param Manifest    $manifest  Validated manifest.
	 * @param string      $directory Absolute path of the extraction directory.
	 * @param string|null $zip_path  Validated zip to extract files from on demand, or null.
	 */
	public function __construct( Manifest $manifest, string $directory, ?string $zip_path = null ) {
		$this->manifest  = $manifest;
		$this->directory = untrailingslashit( $directory );
		$this->zip_path  = $zip_path;
	}

	/**
	 * Returns the manifest.
	 *
	 * @return Manifest
	 */
	public function get_manifest(): Manifest {
		return $this->manifest;
	}

	/**
	 * Returns the extraction directory.
	 *
	 * @return string
	 */
	public function get_directory(): string {
		return $this->directory;
	}

	/**
	 * Returns the absolute local path of a file declared in the manifest.
	 *
	 * @param string $package_path Relative path inside the package.
	 * @return string
	 * @throws PackageException When the file is not declared, cannot be extracted, or is corrupted.
	 */
	public function get_file_path( string $package_path ): string {
		$file = $this->manifest->get_file( $package_path );
		if ( null === $file ) {
			throw new PackageException(
				sprintf(
					/* translators: %s: File path inside the package. */
					esc_html__( 'The file %s is not part of this package.', 'selective-entity-sync' ),
					esc_html( $package_path )
				)
			);
		}

		$path = $this->directory . '/' . $package_path;
		if ( null !== $this->zip_path && ! is_file( $path ) ) {
			$this->extract( $package_path, $file['sha256'], $path );
		}

		return $path;
	}

	/**
	 * Extracts one declared file and verifies its checksum.
	 *
	 * @param string $package_path Relative path inside the package (validated against the manifest).
	 * @param string $sha256       Expected SHA-256.
	 * @param string $path         Absolute destination path.
	 * @return void
	 * @throws PackageException When extraction fails or the checksum does not match.
	 */
	private function extract( string $package_path, string $sha256, string $path ): void {
		$zip = new ZipArchive();
		if ( true !== $zip->open( (string) $this->zip_path ) ) {
			throw new PackageException( esc_html__( 'The package file could not be read.', 'selective-entity-sync' ) );
		}

		$extracted = $zip->extractTo( $this->directory, $package_path );
		$zip->close();

		if ( ! $extracted || ! is_file( $path ) ) {
			throw new PackageException( esc_html__( 'The package could not be extracted.', 'selective-entity-sync' ) );
		}

		if ( ! hash_equals( $sha256, (string) hash_file( 'sha256', $path ) ) ) {
			wp_delete_file( $path );
			throw new PackageException(
				sprintf(
					/* translators: %s: File path inside the package. */
					esc_html__( 'The file %s is corrupted: its checksum does not match the manifest.', 'selective-entity-sync' ),
					esc_html( $package_path )
				)
			);
		}
	}
}
