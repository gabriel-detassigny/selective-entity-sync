<?php
/**
 * Manifest model.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Manifest;

use SelectiveEntitySync\Identity\Uuid;

/**
 * In-memory representation of an entity manifest.
 *
 * Entities are referenced by UUID, never by database ID, so a manifest can be
 * imported on any site. Files are the binary payloads (e.g. media) shipped
 * alongside the manifest in a package.
 */
class Manifest {

	/**
	 * Schema version.
	 *
	 * @var int
	 */
	private $schema_version;

	/**
	 * Generator info: plugin slug and version.
	 *
	 * @var array<string, string>
	 */
	private $generator;

	/**
	 * Source site info: site_url, wp_version, exported_at.
	 *
	 * @var array<string, string>
	 */
	private $source;

	/**
	 * Entities keyed by lowercase UUID, in insertion order.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private $entities = array();

	/**
	 * File entries keyed by package path.
	 *
	 * @var array<string, array{path: string, sha256: string, size: int}>
	 */
	private $files = array();

	/**
	 * Constructor.
	 *
	 * @param array<string, string> $generator      Generator info (plugin, version).
	 * @param array<string, string> $source         Source site info (site_url, wp_version, exported_at).
	 * @param int                   $schema_version Schema version.
	 */
	public function __construct( array $generator, array $source, int $schema_version = Schema::VERSION ) {
		$this->generator      = $generator;
		$this->source         = $source;
		$this->schema_version = $schema_version;
	}

	/**
	 * Builds a manifest from decoded data that has already passed Schema::validate().
	 *
	 * @param array<string, mixed> $data Validated manifest data.
	 * @return self
	 */
	public static function from_array( array $data ): self {
		$manifest = new self( $data['generator'], $data['source'], $data['schema_version'] );

		foreach ( $data['entities'] as $entity ) {
			$manifest->add_entity( $entity );
		}
		foreach ( $data['files'] as $file ) {
			$manifest->add_file( $file['path'], $file['sha256'], $file['size'] );
		}

		return $manifest;
	}

	/**
	 * Adds an entity.
	 *
	 * @param array<string, mixed> $entity Entity with at least `uuid`, `type`, `source_id` and `data`.
	 * @return void
	 * @throws ManifestException When the entity is malformed or its UUID is already present.
	 */
	public function add_entity( array $entity ): void {
		if (
			! isset( $entity['uuid'], $entity['type'], $entity['source_id'], $entity['data'] )
			|| ! is_string( $entity['uuid'] ) || ! Uuid::is_valid( $entity['uuid'] )
			|| ! is_int( $entity['source_id'] ) || ! is_array( $entity['data'] )
		) {
			throw new ManifestException( esc_html__( 'Cannot add an entity without a valid UUID, type, source ID and data.', 'selective-entity-sync' ) );
		}

		$uuid = strtolower( $entity['uuid'] );
		if ( isset( $this->entities[ $uuid ] ) ) {
			throw new ManifestException(
				sprintf(
					/* translators: %s: Entity UUID. */
					esc_html__( 'The entity %s is already in the manifest.', 'selective-entity-sync' ),
					esc_html( $uuid )
				)
			);
		}

		$entity['uuid']          = $uuid;
		$this->entities[ $uuid ] = $entity;
	}

	/**
	 * Whether the manifest contains an entity.
	 *
	 * @param string $uuid Entity UUID.
	 * @return bool
	 */
	public function has_entity( string $uuid ): bool {
		return isset( $this->entities[ strtolower( $uuid ) ] );
	}

	/**
	 * Returns an entity by UUID.
	 *
	 * @param string $uuid Entity UUID.
	 * @return array<string, mixed>|null
	 */
	public function get_entity( string $uuid ): ?array {
		return $this->entities[ strtolower( $uuid ) ] ?? null;
	}

	/**
	 * Returns all entities, in insertion order.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_entities(): array {
		return array_values( $this->entities );
	}

	/**
	 * Adds a file entry.
	 *
	 * @param string $path   Relative path inside the package.
	 * @param string $sha256 Lowercase hex SHA-256 of the file contents.
	 * @param int    $size   File size in bytes.
	 * @return void
	 * @throws ManifestException When the path is unsafe or already present.
	 */
	public function add_file( string $path, string $sha256, int $size ): void {
		if ( ! PackagePath::is_valid( $path ) ) {
			throw new ManifestException(
				sprintf(
					/* translators: %s: File path inside the package. */
					esc_html__( 'The file path "%s" is not allowed in a package.', 'selective-entity-sync' ),
					esc_html( $path )
				)
			);
		}
		if ( isset( $this->files[ $path ] ) ) {
			throw new ManifestException(
				sprintf(
					/* translators: %s: File path inside the package. */
					esc_html__( 'The file %s is already in the manifest.', 'selective-entity-sync' ),
					esc_html( $path )
				)
			);
		}

		$this->files[ $path ] = array(
			'path'   => $path,
			'sha256' => strtolower( $sha256 ),
			'size'   => $size,
		);
	}

	/**
	 * Returns a file entry by path.
	 *
	 * @param string $path Relative path inside the package.
	 * @return array{path: string, sha256: string, size: int}|null
	 */
	public function get_file( string $path ): ?array {
		return $this->files[ $path ] ?? null;
	}

	/**
	 * Returns all file entries.
	 *
	 * @return array<int, array{path: string, sha256: string, size: int}>
	 */
	public function get_files(): array {
		return array_values( $this->files );
	}

	/**
	 * Returns the schema version.
	 *
	 * @return int
	 */
	public function get_schema_version(): int {
		return $this->schema_version;
	}

	/**
	 * Returns generator info.
	 *
	 * @return array<string, string>
	 */
	public function get_generator(): array {
		return $this->generator;
	}

	/**
	 * Returns source site info.
	 *
	 * @return array<string, string>
	 */
	public function get_source(): array {
		return $this->source;
	}

	/**
	 * Returns the manifest as an array ready to be encoded.
	 *
	 * @return array{schema_version: int, generator: array<string, string>, source: array<string, string>, entities: array<int, array<string, mixed>>, files: array<int, array{path: string, sha256: string, size: int}>}
	 */
	public function to_array(): array {
		return array(
			'schema_version' => $this->schema_version,
			'generator'      => $this->generator,
			'source'         => $this->source,
			'entities'       => $this->get_entities(),
			'files'          => $this->get_files(),
		);
	}
}
