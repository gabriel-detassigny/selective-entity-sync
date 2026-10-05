<?php
/**
 * Export context.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

use SelectiveEntitySync\Identity\EntityUuid;

/**
 * State of a single export run: the queue of entities to collect, the files to
 * bundle and the warnings to report.
 *
 * Collectors call reference() for every entity they depend on. It returns the
 * dependency's UUID (to store in the manifest instead of a database ID) and
 * queues the dependency so it gets exported too.
 */
class ExportContext {

	/**
	 * UUID service.
	 *
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Queue of references still to process.
	 *
	 * @var EntityReference[]
	 */
	private $queue = array();

	/**
	 * Keys of every reference ever queued.
	 *
	 * @var array<string, true>
	 */
	private $queued = array();

	/**
	 * Keys of references selected by the user.
	 *
	 * @var array<string, true>
	 */
	private $selected = array();

	/**
	 * First entity that required each dependency, by dependency key.
	 *
	 * @var array<string, EntityReference>
	 */
	private $required_by = array();

	/**
	 * Files to bundle: package path => absolute source path.
	 *
	 * @var array<string, string>
	 */
	private $files = array();

	/**
	 * Warnings to report to the user.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Constructor.
	 *
	 * @param EntityUuid $uuids UUID service.
	 */
	public function __construct( EntityUuid $uuids ) {
		$this->uuids = $uuids;
	}

	/**
	 * Queues an entity selected by the user.
	 *
	 * @param EntityReference $reference Selected entity.
	 * @return void
	 */
	public function select( EntityReference $reference ): void {
		$this->selected[ $reference->get_key() ] = true;
		$this->enqueue( $reference );
	}

	/**
	 * Whether an entity was selected by the user (as opposed to being a dependency).
	 *
	 * @param EntityReference $reference Entity.
	 * @return bool
	 */
	public function is_selected( EntityReference $reference ): bool {
		return isset( $this->selected[ $reference->get_key() ] );
	}

	/**
	 * Declares a dependency and returns its UUID, or null if it does not exist.
	 *
	 * @param EntityReference $dependency  Entity depended upon.
	 * @param EntityReference $required_by Entity that depends on it.
	 * @return string|null
	 */
	public function reference( EntityReference $dependency, EntityReference $required_by ): ?string {
		if ( ! $this->exists( $dependency ) ) {
			$this->add_warning(
				sprintf(
					/* translators: 1: Entity type and ID, e.g. "post #12". 2: Missing entity type and ID, e.g. "post #34". */
					__( '%1$s references %2$s, which no longer exists. The reference will not be remapped.', 'selective-entity-sync' ),
					$this->describe( $required_by ),
					$this->describe( $dependency )
				)
			);
			return null;
		}

		if ( ! isset( $this->required_by[ $dependency->get_key() ] ) ) {
			$this->required_by[ $dependency->get_key() ] = $required_by;
		}
		$this->enqueue( $dependency );

		return $this->uuid( $dependency );
	}

	/**
	 * Returns the entity that first required a dependency, if any.
	 *
	 * @param EntityReference $dependency Dependency.
	 * @return EntityReference|null
	 */
	public function get_required_by( EntityReference $dependency ): ?EntityReference {
		return $this->required_by[ $dependency->get_key() ] ?? null;
	}

	/**
	 * Returns (and assigns if needed) the UUID of an entity.
	 *
	 * @param EntityReference $reference Entity.
	 * @return string
	 */
	public function uuid( EntityReference $reference ): string {
		return $this->uuids->get( $reference->get_object_type(), $reference->get_id() );
	}

	/**
	 * Returns the next entity to process, or null when the queue is empty.
	 *
	 * @return EntityReference|null
	 */
	public function next(): ?EntityReference {
		return array_shift( $this->queue );
	}

	/**
	 * Adds a file to bundle in the package.
	 *
	 * @param string $package_path Relative path inside the package.
	 * @param string $source_path  Absolute local path.
	 * @return void
	 */
	public function add_file( string $package_path, string $source_path ): void {
		$this->files[ $package_path ] = $source_path;
	}

	/**
	 * Returns the files to bundle.
	 *
	 * @return array<string, string> Package path => absolute source path.
	 */
	public function get_files(): array {
		return $this->files;
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

	/**
	 * Returns a human-readable label for an entity, e.g. "post #12".
	 *
	 * @param EntityReference $reference Entity.
	 * @return string
	 */
	public function describe( EntityReference $reference ): string {
		return sprintf(
			/* translators: 1: "post" or "term", 2: ID. */
			__( '%1$s #%2$d', 'selective-entity-sync' ),
			EntityReference::TERM === $reference->get_object_type() ? __( 'term', 'selective-entity-sync' ) : __( 'post', 'selective-entity-sync' ),
			$reference->get_id()
		);
	}

	/**
	 * Queues a reference if it was never queued.
	 *
	 * @param EntityReference $reference Entity.
	 * @return void
	 */
	private function enqueue( EntityReference $reference ): void {
		if ( isset( $this->queued[ $reference->get_key() ] ) ) {
			return;
		}
		$this->queued[ $reference->get_key() ] = true;
		$this->queue[]                         = $reference;
	}

	/**
	 * Whether the referenced object exists.
	 *
	 * @param EntityReference $reference Entity.
	 * @return bool
	 */
	private function exists( EntityReference $reference ): bool {
		if ( EntityReference::POST === $reference->get_object_type() ) {
			return null !== get_post( $reference->get_id() );
		}

		return get_term( $reference->get_id() ) instanceof \WP_Term;
	}
}
