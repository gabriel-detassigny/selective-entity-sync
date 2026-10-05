<?php
/**
 * Meta importer.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;

/**
 * Writes imported meta: keys in the package replace the local values for those
 * keys; keys that only exist locally are kept. IDs in ID-bearing keys are
 * remapped to local IDs.
 */
class MetaImporter {

	/**
	 * Export settings (excluded and ID-bearing keys apply on import too).
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ExportSettings $settings Settings.
	 */
	public function __construct( ExportSettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Imports an entity's meta onto a local object.
	 *
	 * @param string               $object_type 'post' or 'term'.
	 * @param int                  $local_id    Local object ID.
	 * @param array<string, mixed> $entity      Manifest entity.
	 * @param ImportContext        $context     Import context.
	 * @return void
	 */
	public function import( string $object_type, int $local_id, array $entity, ImportContext $context ): void {
		if ( empty( $entity['meta'] ) || ! is_array( $entity['meta'] ) ) {
			return;
		}

		$excluded                         = array_flip( $this->settings->get_excluded_meta_keys( $object_type ) );
		$excluded[ EntityUuid::META_KEY ] = true;
		$id_keys                          = $this->settings->get_id_reference_meta_keys( $object_type );

		foreach ( $entity['meta'] as $key => $values ) {
			$key = (string) $key;
			if ( '' === $key || isset( $excluded[ $key ] ) || ! is_array( $values ) ) {
				continue;
			}

			if ( isset( $id_keys[ $key ] ) ) {
				$values = array_map(
					function ( $value ) use ( $id_keys, $key, $context ) {
						return $this->remap_ids( $value, $id_keys[ $key ], $context );
					},
					$values
				);
			}

			delete_metadata( $object_type, $local_id, $key );
			foreach ( $values as $value ) {
				add_metadata( $object_type, $local_id, $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * Remaps IDs in a meta value, keeping its shape (ID, comma-separated list or array).
	 *
	 * IDs that cannot be resolved are kept as-is for single values and dropped from lists.
	 *
	 * @param mixed         $value       Meta value.
	 * @param string        $object_type Referenced object type.
	 * @param ImportContext $context     Import context.
	 * @return mixed
	 */
	private function remap_ids( $value, string $object_type, ImportContext $context ) {
		if ( is_array( $value ) ) {
			$mapped = array();
			foreach ( $value as $item_key => $item ) {
				$id = is_numeric( $item ) ? $context->map_source_id( $object_type, (int) $item ) : null;
				if ( null !== $id ) {
					$mapped[ $item_key ] = is_int( $item ) ? $id : (string) $id;
				}
			}
			return $this->is_list( $value ) ? array_values( $mapped ) : $mapped;
		}

		if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
			$ids = array();
			foreach ( explode( ',', $value ) as $item ) {
				$id = is_numeric( trim( $item ) ) ? $context->map_source_id( $object_type, (int) $item ) : null;
				if ( null !== $id ) {
					$ids[] = $id;
				}
			}
			return implode( ',', $ids );
		}

		if ( is_numeric( $value ) ) {
			$id = $context->map_source_id( $object_type, (int) $value );
			if ( null !== $id ) {
				return is_int( $value ) ? $id : (string) $id;
			}
		}

		return $value;
	}

	/**
	 * Whether an array is a list (array_is_list() needs PHP 8.1).
	 *
	 * @param array<mixed> $value Array.
	 * @return bool
	 */
	private function is_list( array $value ): bool {
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
