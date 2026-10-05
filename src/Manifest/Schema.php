<?php
/**
 * Manifest schema.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Manifest;

use SelectiveEntitySync\Identity\Uuid;

/**
 * Validates the structure of a decoded manifest.
 *
 * Only the envelope is validated here: required keys, types, unique UUIDs and
 * safe file paths. The contents of each entity's `data` are validated later by
 * the import handler responsible for that entity type.
 *
 * Manifest shape (schema version 1):
 *
 *     {
 *       "schema_version": 1,
 *       "generator": { "plugin": "selective-entity-sync", "version": "0.1.0" },
 *       "source": { "site_url": "https://staging.example.com", "wp_version": "6.9", "exported_at": "2026-10-05T12:00:00+00:00" },
 *       "entities": [ { "uuid": "…", "type": "post", "data": { … } } ],
 *       "files": [ { "path": "media/…/photo.jpg", "sha256": "…", "size": 12345 } ]
 *     }
 */
class Schema {

	/**
	 * Current manifest schema version. Increment on breaking format changes.
	 */
	public const VERSION = 1;

	/**
	 * Validates a decoded manifest.
	 *
	 * @param array<mixed> $data Decoded manifest.
	 * @return void
	 * @throws ManifestException When the manifest is invalid.
	 */
	public function validate( array $data ): void {
		$this->validate_schema_version( $data['schema_version'] ?? null );
		$this->validate_string_map( $data, 'generator', array( 'plugin', 'version' ) );
		$this->validate_string_map( $data, 'source', array( 'site_url', 'wp_version', 'exported_at' ) );
		$this->validate_entities( $data['entities'] ?? null );
		$this->validate_files( $data['files'] ?? null );
	}

	/**
	 * Validates the schema version.
	 *
	 * @param mixed $version Value of `schema_version`.
	 * @return void
	 * @throws ManifestException When the version is missing or unsupported.
	 */
	private function validate_schema_version( $version ): void {
		if ( ! is_int( $version ) || $version < 1 ) {
			throw new ManifestException( __( 'The manifest has no valid schema version.', 'selective-entity-sync' ) );
		}

		if ( $version > self::VERSION ) {
			throw new ManifestException(
				sprintf(
					/* translators: 1: Manifest schema version, 2: Highest schema version supported by this site. */
					__( 'This package uses manifest format %1$d, but this site only supports up to format %2$d. Please update Selective Entity Sync on this site.', 'selective-entity-sync' ),
					$version,
					self::VERSION
				)
			);
		}
	}

	/**
	 * Validates an object whose listed keys must all be non-empty strings.
	 *
	 * @param array<mixed> $data Decoded manifest.
	 * @param string       $key  Top-level key.
	 * @param string[]     $keys Required string keys.
	 * @return void
	 * @throws ManifestException When the object or one of its keys is invalid.
	 */
	private function validate_string_map( array $data, string $key, array $keys ): void {
		if ( ! isset( $data[ $key ] ) || ! is_array( $data[ $key ] ) ) {
			throw $this->invalid_field( $key );
		}

		foreach ( $keys as $field ) {
			if ( ! isset( $data[ $key ][ $field ] ) || ! is_string( $data[ $key ][ $field ] ) || '' === $data[ $key ][ $field ] ) {
				throw $this->invalid_field( $key . '.' . $field );
			}
		}
	}

	/**
	 * Validates the entity list.
	 *
	 * @param mixed $entities Value of `entities`.
	 * @return void
	 * @throws ManifestException When an entity is invalid or a UUID is duplicated.
	 */
	private function validate_entities( $entities ): void {
		if ( ! is_array( $entities ) || ! $this->is_list( $entities ) ) {
			throw $this->invalid_field( 'entities' );
		}

		$seen = array();
		foreach ( $entities as $index => $entity ) {
			$field = sprintf( 'entities[%d]', $index );

			if ( ! is_array( $entity ) ) {
				throw $this->invalid_field( $field );
			}
			if ( ! isset( $entity['uuid'] ) || ! is_string( $entity['uuid'] ) || ! Uuid::is_valid( $entity['uuid'] ) ) {
				throw $this->invalid_field( $field . '.uuid' );
			}
			if ( ! isset( $entity['type'] ) || ! is_string( $entity['type'] ) || 1 !== preg_match( '/^[a-z][a-z0-9_]{0,31}$/D', $entity['type'] ) ) {
				throw $this->invalid_field( $field . '.type' );
			}
			if ( ! isset( $entity['data'] ) || ! is_array( $entity['data'] ) ) {
				throw $this->invalid_field( $field . '.data' );
			}

			$uuid = strtolower( $entity['uuid'] );
			if ( isset( $seen[ $uuid ] ) ) {
				throw new ManifestException(
					sprintf(
						/* translators: %s: Entity UUID. */
						__( 'The manifest contains the entity %s more than once.', 'selective-entity-sync' ),
						$uuid
					)
				);
			}
			$seen[ $uuid ] = true;
		}
	}

	/**
	 * Validates the file list.
	 *
	 * @param mixed $files Value of `files`.
	 * @return void
	 * @throws ManifestException When a file entry is invalid or a path is duplicated.
	 */
	private function validate_files( $files ): void {
		if ( ! is_array( $files ) || ! $this->is_list( $files ) ) {
			throw $this->invalid_field( 'files' );
		}

		$seen = array();
		foreach ( $files as $index => $file ) {
			$field = sprintf( 'files[%d]', $index );

			if ( ! is_array( $file ) ) {
				throw $this->invalid_field( $field );
			}
			if ( ! isset( $file['path'] ) || ! is_string( $file['path'] ) || ! PackagePath::is_valid( $file['path'] ) ) {
				throw $this->invalid_field( $field . '.path' );
			}
			if ( ! isset( $file['sha256'] ) || ! is_string( $file['sha256'] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $file['sha256'] ) ) {
				throw $this->invalid_field( $field . '.sha256' );
			}
			if ( ! isset( $file['size'] ) || ! is_int( $file['size'] ) || $file['size'] < 0 ) {
				throw $this->invalid_field( $field . '.size' );
			}

			if ( isset( $seen[ $file['path'] ] ) ) {
				throw new ManifestException(
					sprintf(
						/* translators: %s: File path inside the package. */
						__( 'The manifest lists the file %s more than once.', 'selective-entity-sync' ),
						$file['path']
					)
				);
			}
			$seen[ $file['path'] ] = true;
		}
	}

	/**
	 * Whether an array is a list (sequential keys starting at 0).
	 *
	 * @param array<mixed> $value Array to check.
	 * @return bool
	 */
	private function is_list( array $value ): bool {
		return array_keys( $value ) === range( 0, count( $value ) - 1 ) || array() === $value;
	}

	/**
	 * Builds the exception for a missing or invalid field.
	 *
	 * @param string $field Field path, e.g. "entities[2].uuid".
	 * @return ManifestException
	 */
	private function invalid_field( string $field ): ManifestException {
		return new ManifestException(
			sprintf(
				/* translators: %s: Manifest field path, e.g. "entities[2].uuid". */
				__( 'The manifest is invalid: "%s" is missing or malformed.', 'selective-entity-sync' ),
				$field
			)
		);
	}
}
