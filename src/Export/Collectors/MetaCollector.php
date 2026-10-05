<?php
/**
 * Meta collector.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;
use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;

/**
 * Exports an object's meta and declares entities referenced by ID-bearing meta keys.
 */
class MetaCollector {

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * Constructor.
	 *
	 * @param ExportSettings $settings Export settings.
	 */
	public function __construct( ExportSettings $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Returns an object's exportable meta.
	 *
	 * Values are unserialized; each key maps to the list of its values, since
	 * a meta key can have several rows. IDs in ID-bearing keys are kept as-is
	 * (they are remapped on import) and the referenced entities are declared
	 * as dependencies.
	 *
	 * @param EntityReference $reference Object owning the meta.
	 * @param ExportContext   $context   Export context.
	 * @return array<string, array<int, mixed>>
	 */
	public function collect( EntityReference $reference, ExportContext $context ): array {
		$object_type = $reference->get_object_type();
		$all_meta    = get_metadata( $object_type, $reference->get_id() );
		if ( ! is_array( $all_meta ) ) {
			return array();
		}

		$excluded                         = array_flip( $this->settings->get_excluded_meta_keys( $object_type ) );
		$id_keys                          = $this->settings->get_id_reference_meta_keys( $object_type );
		$meta                             = array();
		$excluded[ EntityUuid::META_KEY ] = true;

		foreach ( $all_meta as $key => $values ) {
			$key = (string) $key;
			if ( isset( $excluded[ $key ] ) || ! is_array( $values ) ) {
				continue;
			}

			$meta[ $key ] = array_map( 'maybe_unserialize', $values );

			if ( isset( $id_keys[ $key ] ) ) {
				foreach ( $meta[ $key ] as $value ) {
					foreach ( $this->extract_ids( $value ) as $id ) {
						$context->reference( new EntityReference( $id_keys[ $key ], $id ), $reference );
					}
				}
			}
		}

		return $meta;
	}

	/**
	 * Extracts IDs from a meta value: an ID, a comma-separated list, or an array of IDs.
	 *
	 * @param mixed $value Meta value.
	 * @return int[]
	 */
	private function extract_ids( $value ): array {
		if ( is_string( $value ) && false !== strpos( $value, ',' ) ) {
			$value = explode( ',', $value );
		}

		$ids = array();
		foreach ( (array) $value as $item ) {
			if ( is_numeric( $item ) && (int) $item > 0 ) {
				$ids[] = (int) $item;
			}
		}

		return $ids;
	}
}
