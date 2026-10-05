<?php
/**
 * Extracted package.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

use SelectiveEntitySync\Manifest\Manifest;

/**
 * A package that has been validated and extracted to a local directory.
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
	 * Constructor.
	 *
	 * @param Manifest $manifest  Validated manifest.
	 * @param string   $directory Absolute path of the extraction directory.
	 */
	public function __construct( Manifest $manifest, string $directory ) {
		$this->manifest  = $manifest;
		$this->directory = untrailingslashit( $directory );
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
	 * @throws PackageException When the file is not declared in the manifest.
	 */
	public function get_file_path( string $package_path ): string {
		if ( null === $this->manifest->get_file( $package_path ) ) {
			throw new PackageException(
				sprintf(
					/* translators: %s: File path inside the package. */
					__( 'The file %s is not part of this package.', 'selective-entity-sync' ),
					$package_path
				)
			);
		}

		return $this->directory . '/' . $package_path;
	}
}
