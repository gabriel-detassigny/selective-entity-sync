<?php
/**
 * Block reference map.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Content;

/**
 * Declares which block attributes hold IDs of other posts (media, synced
 * patterns, …). Used to find dependencies on export and to remap IDs on import.
 */
class BlockReferenceMap {

	/**
	 * Default map: block name => list of attributes holding a post ID or a list of post IDs.
	 */
	public const DEFAULTS = array(
		'core/image'      => array( 'id' ),
		'core/gallery'    => array( 'ids' ),
		'core/cover'      => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/file'       => array( 'id' ),
		'core/block'      => array( 'ref' ),
	);

	/**
	 * Returns the map.
	 *
	 * @return array<string, string[]> Block name => attribute names.
	 */
	public function get(): array {
		/**
		 * Filters which block attributes hold IDs of other posts.
		 *
		 * Referenced posts (usually attachments or synced patterns) are exported
		 * as dependencies, and the attribute values are remapped on import.
		 * Attribute values may be a single ID or an array of IDs.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string[]> $map Block name => attribute names.
		 */
		$filtered = apply_filters( 'selective_entity_sync_block_reference_attributes', self::DEFAULTS );

		if ( ! is_array( $filtered ) ) {
			return self::DEFAULTS;
		}

		$map = array();
		foreach ( $filtered as $block_name => $attributes ) {
			if ( is_string( $block_name ) && is_array( $attributes ) ) {
				$map[ $block_name ] = array_values( array_filter( $attributes, 'is_string' ) );
			}
		}

		return $map;
	}
}
