<?php
/**
 * Content reference finder.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Content;

/**
 * Finds the IDs of posts (attachments, synced patterns, …) referenced by post content.
 *
 * Looks at block attributes (see BlockReferenceMap), `wp-image-{id}` classes
 * (block and classic content) and `[gallery ids="…"]` shortcodes.
 */
class ContentReferenceFinder {

	/**
	 * Block reference map.
	 *
	 * @var BlockReferenceMap
	 */
	private $map;

	/**
	 * Constructor.
	 *
	 * @param BlockReferenceMap $map Block reference map.
	 */
	public function __construct( BlockReferenceMap $map ) {
		$this->map = $map;
	}

	/**
	 * Returns the unique post IDs referenced by some content, in order of appearance.
	 *
	 * @param string $content Post content.
	 * @return int[]
	 */
	public function find_post_ids( string $content ): array {
		if ( '' === $content ) {
			return array();
		}

		$ids = array();

		if ( has_blocks( $content ) ) {
			$this->collect_from_blocks( parse_blocks( $content ), $this->map->get(), $ids );
		}

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $content, $matches ) ) {
			foreach ( $matches[1] as $id ) {
				$ids[] = (int) $id;
			}
		}

		if ( false !== strpos( $content, '[gallery' ) && preg_match_all( '/' . get_shortcode_regex( array( 'gallery' ) ) . '/', $content, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $shortcode ) {
				$atts = shortcode_parse_atts( $shortcode[3] );
				if ( is_array( $atts ) && isset( $atts['ids'] ) ) {
					foreach ( explode( ',', $atts['ids'] ) as $id ) {
						$ids[] = (int) trim( $id );
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Recursively collects IDs from parsed blocks.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 * @param array<string, string[]>          $map    Block name => attribute names.
	 * @param int[]                            $ids    Collected IDs, by reference.
	 * @return void
	 */
	private function collect_from_blocks( array $blocks, array $map, array &$ids ): void {
		foreach ( $blocks as $block ) {
			$name = $block['blockName'] ?? null;
			if ( is_string( $name ) && isset( $map[ $name ] ) && is_array( $block['attrs'] ?? null ) ) {
				foreach ( $map[ $name ] as $attribute ) {
					foreach ( (array) ( $block['attrs'][ $attribute ] ?? array() ) as $value ) {
						if ( is_numeric( $value ) ) {
							$ids[] = (int) $value;
						}
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$this->collect_from_blocks( $block['innerBlocks'], $map, $ids );
			}
		}
	}
}
