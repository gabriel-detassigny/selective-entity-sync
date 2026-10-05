<?php
/**
 * Import context.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Package\Package;

/**
 * State of a single import run.
 *
 * Holds the UUID → local ID map, resolves IDs embedded in content and meta
 * (source site IDs) to local IDs, collects media URL replacements and records
 * entities whose references could not be resolved yet (dependency cycles),
 * so they can be written again once everything exists.
 */
class ImportContext {

	/**
	 * Manifest being imported.
	 *
	 * @var Manifest
	 */
	private $manifest;

	/**
	 * Extracted package, or null when only planning.
	 *
	 * @var Package|null
	 */
	private $package;

	/**
	 * UUID → local ID.
	 *
	 * @var array<string, int>
	 */
	private $ids = array();

	/**
	 * "post:12" / "term:3" source keys → entity UUID.
	 *
	 * @var array<string, string>
	 */
	private $source_index = array();

	/**
	 * UUIDs that will be written during this import.
	 *
	 * @var array<string, true>
	 */
	private $pending = array();

	/**
	 * UUID of the entity currently being written.
	 *
	 * @var string|null
	 */
	private $current;

	/**
	 * UUIDs of entities that referenced not-yet-written entities.
	 *
	 * @var array<string, true>
	 */
	private $needs_fixup = array();

	/**
	 * Source URL → local URL, for media files.
	 *
	 * @var array<string, string>
	 */
	private $url_replacements = array();

	/**
	 * Warnings for the user.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param Manifest     $manifest Manifest being imported.
	 * @param Package|null $package  Extracted package, or null when only planning.
	 */
	public function __construct( Manifest $manifest, ?Package $package = null ) {
		$this->manifest = $manifest;
		$this->package  = $package;

		foreach ( $manifest->get_entities() as $entity ) {
			$this->source_index[ self::object_type_for( $entity['type'] ) . ':' . $entity['source_id'] ] = $entity['uuid'];
		}
	}

	/**
	 * Returns the WordPress object type ('post' or 'term') an entity type is stored as.
	 *
	 * @param string $entity_type Entity type, e.g. 'post', 'attachment', 'term'.
	 * @return string
	 */
	public static function object_type_for( string $entity_type ): string {
		return 'term' === $entity_type ? 'term' : 'post';
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
	 * Returns the extracted package.
	 *
	 * @return Package
	 * @throws ImportException When the context has no package (planning only).
	 */
	public function get_package(): Package {
		if ( null === $this->package ) {
			throw new ImportException( 'No package is available while planning.' );
		}

		return $this->package;
	}

	/**
	 * Records the local ID of an entity.
	 *
	 * @param string $uuid     Entity UUID.
	 * @param int    $local_id Local ID.
	 * @return void
	 */
	public function set_local_id( string $uuid, int $local_id ): void {
		$this->ids[ strtolower( $uuid ) ] = $local_id;
	}

	/**
	 * Marks entities as about to be written.
	 *
	 * @param string[] $uuids Entity UUIDs.
	 * @return void
	 */
	public function set_pending( array $uuids ): void {
		$this->pending = array_fill_keys( array_map( 'strtolower', $uuids ), true );
	}

	/**
	 * Sets the entity currently being written.
	 *
	 * @param string|null $uuid Entity UUID, or null when done.
	 * @return void
	 */
	public function set_current( ?string $uuid ): void {
		$this->current = null === $uuid ? null : strtolower( $uuid );
	}

	/**
	 * Returns the local ID for an entity UUID, or null if it does not exist locally (yet).
	 *
	 * @param string|null $uuid Entity UUID.
	 * @return int|null
	 */
	public function get_local_id( ?string $uuid ): ?int {
		if ( null === $uuid || '' === $uuid ) {
			return null;
		}

		$uuid = strtolower( $uuid );
		if ( isset( $this->ids[ $uuid ] ) ) {
			return $this->ids[ $uuid ];
		}

		// Referenced entity will be written later: write the current one again at the end.
		if ( isset( $this->pending[ $uuid ] ) && null !== $this->current ) {
			$this->needs_fixup[ $this->current ] = true;
		}

		return null;
	}

	/**
	 * Returns the local ID already recorded for a UUID, without side effects.
	 *
	 * @param string $uuid Entity UUID.
	 * @return int|null
	 */
	public function peek_local_id( string $uuid ): ?int {
		return $this->ids[ strtolower( $uuid ) ] ?? null;
	}

	/**
	 * Returns the UUID of the entity that had a given ID on the source site.
	 *
	 * @param string $object_type 'post' (posts and attachments) or 'term'.
	 * @param int    $source_id   ID on the source site.
	 * @return string|null
	 */
	public function get_uuid_for_source_id( string $object_type, int $source_id ): ?string {
		return $this->source_index[ $object_type . ':' . $source_id ] ?? null;
	}

	/**
	 * Maps an ID from the source site to the corresponding local ID.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $source_id   ID on the source site.
	 * @return int|null Null when the entity is not in the manifest or does not exist locally.
	 */
	public function map_source_id( string $object_type, int $source_id ): ?int {
		return $this->get_local_id( $this->get_uuid_for_source_id( $object_type, $source_id ) );
	}

	/**
	 * Whether an entity referenced something that was not written yet.
	 *
	 * @param string $uuid Entity UUID.
	 * @return bool
	 */
	public function needs_fixup( string $uuid ): bool {
		return isset( $this->needs_fixup[ strtolower( $uuid ) ] );
	}

	/**
	 * Clears the fixup flag of an entity.
	 *
	 * @param string $uuid Entity UUID.
	 * @return void
	 */
	public function clear_fixup( string $uuid ): void {
		unset( $this->needs_fixup[ strtolower( $uuid ) ] );
	}

	/**
	 * Registers a media URL replacement.
	 *
	 * @param string $from Source URL.
	 * @param string $to   Local URL.
	 * @return void
	 */
	public function add_url_replacement( string $from, string $to ): void {
		if ( '' !== $from && '' !== $to && $from !== $to ) {
			$this->url_replacements[ $from ] = $to;
		}
	}

	/**
	 * Returns the media URL replacements.
	 *
	 * @return array<string, string> Source URL => local URL.
	 */
	public function get_url_replacements(): array {
		return $this->url_replacements;
	}

	/**
	 * Returns the state needed to resume the import in another request.
	 *
	 * @return array{ids: array<string, int>, pending: string[], needs_fixup: string[], url_replacements: array<string, string>, warnings: string[]}
	 */
	public function get_state(): array {
		return array(
			'ids'              => $this->ids,
			'pending'          => array_keys( $this->pending ),
			'needs_fixup'      => array_keys( $this->needs_fixup ),
			'url_replacements' => $this->url_replacements,
			'warnings'         => $this->warnings,
		);
	}

	/**
	 * Restores state saved by get_state().
	 *
	 * @param array<string, mixed> $state Saved state.
	 * @return void
	 */
	public function restore_state( array $state ): void {
		$this->ids              = array_map( 'intval', (array) ( $state['ids'] ?? array() ) );
		$this->pending          = array_fill_keys( array_map( 'strval', (array) ( $state['pending'] ?? array() ) ), true );
		$this->needs_fixup      = array_fill_keys( array_map( 'strval', (array) ( $state['needs_fixup'] ?? array() ) ), true );
		$this->url_replacements = array_map( 'strval', (array) ( $state['url_replacements'] ?? array() ) );
		$this->warnings         = array_map( 'strval', (array) ( $state['warnings'] ?? array() ) );
	}

	/**
	 * Adds a warning for the user.
	 *
	 * @param string $message Translated message.
	 * @return void
	 */
	public function add_warning( string $message ): void {
		$this->warnings[] = $message;
	}

	/**
	 * Returns the warnings.
	 *
	 * @return string[]
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}
}
