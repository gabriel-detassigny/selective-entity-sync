<?php
/**
 * Package limits.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

/**
 * Size and count limits enforced when reading untrusted packages.
 */
class PackageLimits {

	/**
	 * Default limits.
	 *
	 * @var array{max_package_size: int, max_manifest_size: int, max_entries: int, max_uncompressed_size: int}
	 */
	public const DEFAULTS = array(
		'max_package_size'      => 512 * 1024 * 1024, // 512 MB zip on disk.
		'max_manifest_size'     => 64 * 1024 * 1024,  // 64 MB of JSON.
		'max_entries'           => 20000,             // Files in the zip.
		'max_uncompressed_size' => 2048 * 1024 * 1024, // 2 GB extracted.
	);

	/**
	 * Returns the effective limits.
	 *
	 * @return array{max_package_size: int, max_manifest_size: int, max_entries: int, max_uncompressed_size: int}
	 */
	public function get(): array {
		/**
		 * Filters the limits enforced when reading a package.
		 *
		 * Invalid or non-positive values fall back to the defaults.
		 *
		 * @since 0.1.0
		 *
		 * @param array $limits {
		 *     @type int $max_package_size      Maximum size of the zip file, in bytes. Default 512 MB.
		 *     @type int $max_manifest_size     Maximum size of manifest.json, in bytes. Default 64 MB.
		 *     @type int $max_entries           Maximum number of entries in the zip. Default 20000.
		 *     @type int $max_uncompressed_size Maximum total size of extracted files, in bytes. Default 2 GB.
		 * }
		 */
		$filtered = apply_filters( 'selective_entity_sync_package_limits', self::DEFAULTS );

		$limits = self::DEFAULTS;
		if ( is_array( $filtered ) ) {
			foreach ( array_keys( self::DEFAULTS ) as $key ) {
				if ( isset( $filtered[ $key ] ) && is_int( $filtered[ $key ] ) && $filtered[ $key ] > 0 ) {
					$limits[ $key ] = $filtered[ $key ];
				}
			}
		}

		return $limits;
	}
}
