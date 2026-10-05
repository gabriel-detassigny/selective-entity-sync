<?php
/**
 * Package reader.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\PackagePath;
use ZipArchive;

/**
 * Validates and extracts untrusted zip packages.
 *
 * Every check runs before anything is extracted:
 * - size and entry-count limits (see PackageLimits);
 * - the manifest is valid JSON and passes schema validation;
 * - the zip contains only manifest.json and the files the manifest declares,
 *   so no unexpected or path-traversing entries can be written;
 * - declared file sizes match the zip's records (guards against zip bombs);
 * - every file type is one WordPress allows to be uploaded.
 *
 * After extraction, every file's SHA-256 is verified against the manifest.
 */
class PackageReader {

	/**
	 * Manifest codec.
	 *
	 * @var ManifestCodec
	 */
	private $codec;

	/**
	 * Limits provider.
	 *
	 * @var PackageLimits
	 */
	private $limits;

	/**
	 * Constructor.
	 *
	 * @param ManifestCodec $codec  Manifest codec.
	 * @param PackageLimits $limits Limits provider.
	 */
	public function __construct( ManifestCodec $codec, PackageLimits $limits ) {
		$this->codec  = $codec;
		$this->limits = $limits;
	}

	/**
	 * Reads only the manifest, without extracting files. Useful for previews.
	 *
	 * @param string $zip_path Absolute path of the zip file.
	 * @return Manifest
	 * @throws PackageException When the package is invalid or unsafe.
	 */
	public function read_manifest( string $zip_path ): Manifest {
		$limits = $this->limits->get();
		$zip    = $this->open( $zip_path, $limits );

		try {
			$manifest = $this->load_manifest( $zip, $limits );
			$this->validate_entries( $zip, $manifest, $limits );
		} finally {
			$zip->close();
		}

		return $manifest;
	}

	/**
	 * Validates a package and extracts its files.
	 *
	 * The caller owns the extraction directory and is responsible for deleting
	 * it, including when an exception is thrown.
	 *
	 * @param string $zip_path    Absolute path of the zip file.
	 * @param string $extract_dir Absolute path of an existing, empty directory.
	 * @return Package
	 * @throws PackageException When the package is invalid or unsafe.
	 */
	public function read( string $zip_path, string $extract_dir ): Package {
		$limits = $this->limits->get();
		$zip    = $this->open( $zip_path, $limits );

		try {
			$manifest = $this->load_manifest( $zip, $limits );
			$this->validate_entries( $zip, $manifest, $limits );
			$this->extract( $zip, $manifest, $extract_dir );
		} finally {
			$zip->close();
		}

		$package = new Package( $manifest, $extract_dir );
		$this->verify_hashes( $package );

		return $package;
	}

	/**
	 * Opens a zip after checking its size.
	 *
	 * @param string                                                                                             $zip_path Absolute path of the zip file.
	 * @param array{max_package_size: int, max_manifest_size: int, max_entries: int, max_uncompressed_size: int} $limits   Limits.
	 * @return ZipArchive
	 * @throws PackageException When the file is missing, too large, or not a zip.
	 */
	private function open( string $zip_path, array $limits ): ZipArchive {
		if ( ! class_exists( ZipArchive::class ) ) {
			throw new PackageException( __( 'The PHP zip extension is required to read packages.', 'selective-entity-sync' ) );
		}

		if ( ! is_file( $zip_path ) || ! is_readable( $zip_path ) ) {
			throw new PackageException( __( 'The package file could not be read.', 'selective-entity-sync' ) );
		}

		if ( filesize( $zip_path ) > $limits['max_package_size'] ) {
			throw new PackageException(
				sprintf(
					/* translators: %s: Maximum package size, e.g. "512 MB". */
					__( 'The package is larger than the maximum allowed size of %s.', 'selective-entity-sync' ),
					size_format( $limits['max_package_size'] )
				)
			);
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $zip_path ) ) {
			throw new PackageException( __( 'The file is not a valid package (zip) file.', 'selective-entity-sync' ) );
		}

		if ( $zip->numFiles > $limits['max_entries'] ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$zip->close();
			throw new PackageException( __( 'The package contains too many files.', 'selective-entity-sync' ) );
		}

		return $zip;
	}

	/**
	 * Loads and validates manifest.json.
	 *
	 * @param ZipArchive                                                                                         $zip    Open zip.
	 * @param array{max_package_size: int, max_manifest_size: int, max_entries: int, max_uncompressed_size: int} $limits Limits.
	 * @return Manifest
	 * @throws PackageException When the manifest is missing, too large, or invalid.
	 */
	private function load_manifest( ZipArchive $zip, array $limits ): Manifest {
		$stat = $zip->statName( PackagePath::MANIFEST_FILE );
		if ( false === $stat ) {
			throw new PackageException( __( 'The package does not contain a manifest.json file.', 'selective-entity-sync' ) );
		}

		if ( $stat['size'] > $limits['max_manifest_size'] ) {
			throw new PackageException( __( 'The package manifest is too large.', 'selective-entity-sync' ) );
		}

		$json = $zip->getFromName( PackagePath::MANIFEST_FILE );
		if ( false === $json ) {
			throw new PackageException( __( 'The package manifest could not be read.', 'selective-entity-sync' ) );
		}

		try {
			return $this->codec->decode( $json );
		} catch ( \SelectiveEntitySync\Manifest\ManifestException $e ) {
			throw new PackageException( $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Checks that the zip holds exactly the declared files, with the declared sizes and allowed types.
	 *
	 * @param ZipArchive                                                                                         $zip      Open zip.
	 * @param Manifest                                                                                           $manifest Validated manifest.
	 * @param array{max_package_size: int, max_manifest_size: int, max_entries: int, max_uncompressed_size: int} $limits   Limits.
	 * @return void
	 * @throws PackageException On any unexpected, missing, oversized or disallowed entry.
	 */
	private function validate_entries( ZipArchive $zip, Manifest $manifest, array $limits ): void {
		$found = array();
		$total = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive property.
			$stat = $zip->statIndex( $i );
			if ( false === $stat ) {
				throw new PackageException( __( 'The package could not be read.', 'selective-entity-sync' ) );
			}

			$name = $stat['name'];

			// Directory entries are harmless if they are part of a valid path.
			if ( '/' === substr( $name, -1 ) && PackagePath::is_valid( rtrim( $name, '/' ) ) ) {
				continue;
			}

			if ( PackagePath::MANIFEST_FILE === $name ) {
				continue;
			}

			$file = $manifest->get_file( $name );
			if ( null === $file ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: Entry name inside the zip file. */
						__( 'The package contains an unexpected file: %s', 'selective-entity-sync' ),
						$name
					)
				);
			}

			if ( (int) $stat['size'] !== $file['size'] ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The size of %s does not match the manifest.', 'selective-entity-sync' ),
						$name
					)
				);
			}

			$total += $file['size'];
			if ( $total > $limits['max_uncompressed_size'] ) {
				throw new PackageException( __( 'The package contents are larger than the maximum allowed size.', 'selective-entity-sync' ) );
			}

			$this->assert_allowed_type( $name );
			$found[ $name ] = true;
		}

		foreach ( $manifest->get_files() as $file ) {
			if ( ! isset( $found[ $file['path'] ] ) ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The package is missing the file %s.', 'selective-entity-sync' ),
						$file['path']
					)
				);
			}
		}
	}

	/**
	 * Rejects file types WordPress would not accept as uploads (e.g. PHP files).
	 *
	 * @param string $path Relative path inside the package.
	 * @return void
	 * @throws PackageException When the type is not allowed.
	 */
	private function assert_allowed_type( string $path ): void {
		$type = wp_check_filetype( basename( $path ), get_allowed_mime_types() );

		if ( empty( $type['ext'] ) ) {
			throw new PackageException(
				sprintf(
					/* translators: %s: File path inside the package. */
					__( 'The package contains a file type that is not allowed: %s', 'selective-entity-sync' ),
					$path
				)
			);
		}
	}

	/**
	 * Extracts the declared files.
	 *
	 * @param ZipArchive $zip         Open, validated zip.
	 * @param Manifest   $manifest    Validated manifest.
	 * @param string     $extract_dir Absolute path of the extraction directory.
	 * @return void
	 * @throws PackageException When extraction fails.
	 */
	private function extract( ZipArchive $zip, Manifest $manifest, string $extract_dir ): void {
		$paths = array_column( $manifest->get_files(), 'path' );
		if ( array() === $paths ) {
			return;
		}

		if ( ! is_dir( $extract_dir ) || ! wp_is_writable( $extract_dir ) ) {
			throw new PackageException( __( 'The package could not be extracted: the working directory is not writable.', 'selective-entity-sync' ) );
		}

		// Entry names were validated against the manifest, so only safe relative paths are extracted.
		if ( ! $zip->extractTo( $extract_dir, $paths ) ) {
			throw new PackageException( __( 'The package could not be extracted.', 'selective-entity-sync' ) );
		}
	}

	/**
	 * Verifies the SHA-256 of every extracted file.
	 *
	 * @param Package $package Extracted package.
	 * @return void
	 * @throws PackageException On a hash mismatch.
	 */
	private function verify_hashes( Package $package ): void {
		foreach ( $package->get_manifest()->get_files() as $file ) {
			$path = $package->get_file_path( $file['path'] );

			if ( ! is_file( $path ) || ! hash_equals( $file['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				throw new PackageException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The file %s is corrupted: its checksum does not match the manifest.', 'selective-entity-sync' ),
						$file['path']
					)
				);
			}
		}
	}
}
