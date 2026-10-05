<?php
/**
 * Export plan.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

use SelectiveEntitySync\Manifest\Manifest;

/**
 * Everything an export would contain, before the package is written.
 */
class ExportPlan {

	/**
	 * Manifest without file entries.
	 *
	 * @var Manifest
	 */
	private $manifest;

	/**
	 * Files to bundle: package path => absolute source path.
	 *
	 * @var array<string, string>
	 */
	private $files;

	/**
	 * Warnings for the user.
	 *
	 * @var string[]
	 */
	private $warnings;

	/**
	 * UUIDs of the entities selected by the user.
	 *
	 * @var string[]
	 */
	private $selected_uuids;

	/**
	 * Constructor.
	 *
	 * @param Manifest              $manifest       Manifest without file entries.
	 * @param array<string, string> $files          Package path => absolute source path.
	 * @param string[]              $warnings       Warnings.
	 * @param string[]              $selected_uuids UUIDs of selected entities.
	 */
	public function __construct( Manifest $manifest, array $files, array $warnings, array $selected_uuids ) {
		$this->manifest       = $manifest;
		$this->files          = $files;
		$this->warnings       = $warnings;
		$this->selected_uuids = $selected_uuids;
	}

	/**
	 * Returns the manifest (without file entries).
	 *
	 * @return Manifest
	 */
	public function get_manifest(): Manifest {
		return $this->manifest;
	}

	/**
	 * Returns the files to bundle.
	 *
	 * @return array<string, string>
	 */
	public function get_files(): array {
		return $this->files;
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
	 * Whether an entity was selected by the user (as opposed to added as a dependency).
	 *
	 * @param string $uuid Entity UUID.
	 * @return bool
	 */
	public function is_selected( string $uuid ): bool {
		return in_array( strtolower( $uuid ), $this->selected_uuids, true );
	}

	/**
	 * Returns a human-readable label for an entity's subtype, e.g. "Page", "Category" or "Media".
	 *
	 * @param string $type    Entity type.
	 * @param string $subtype Post type, taxonomy or MIME type.
	 * @return string
	 */
	private function get_subtype_label( string $type, string $subtype ): string {
		if ( 'attachment' === $type ) {
			return __( 'Media', 'selective-entity-sync' );
		}

		if ( 'term' === $type ) {
			$taxonomy = get_taxonomy( $subtype );
			return false !== $taxonomy ? (string) $taxonomy->labels->singular_name : $subtype;
		}

		$post_type = get_post_type_object( $subtype );
		return null !== $post_type ? (string) $post_type->labels->singular_name : $subtype;
	}

	/**
	 * Returns a summary for display: one row per entity, plus file totals.
	 *
	 * @return array{entities: array<int, array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, source_id: int, selected: bool, reference_only: bool}>, file_count: int, file_bytes: int, file_size: string, warnings: string[]}
	 */
	public function get_summary(): array {
		$entities = array();
		foreach ( $this->manifest->get_entities() as $entity ) {
			$data       = $entity['data'];
			$subtype    = (string) ( $data['post_type'] ?? $data['taxonomy'] ?? $data['post_mime_type'] ?? '' );
			$entities[] = array(
				'uuid'           => $entity['uuid'],
				'type'           => $entity['type'],
				'subtype'        => $subtype,
				'subtype_label'  => $this->get_subtype_label( $entity['type'], $subtype ),
				'title'          => (string) ( $data['post_title'] ?? $data['name'] ?? '' ),
				'source_id'      => $entity['source_id'],
				'selected'       => $this->is_selected( $entity['uuid'] ),
				'reference_only' => ! empty( $entity['reference_only'] ),
			);
		}

		$bytes = 0;
		foreach ( $this->files as $source ) {
			$bytes += (int) filesize( $source );
		}

		return array(
			'entities'   => $entities,
			'file_count' => count( $this->files ),
			'file_bytes' => $bytes,
			'file_size'  => (string) size_format( $bytes ),
			'warnings'   => $this->warnings,
		);
	}
}
