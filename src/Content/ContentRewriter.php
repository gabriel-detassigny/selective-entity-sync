<?php
/**
 * Content rewriter.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Content;

/**
 * Rewrites source-site IDs and URLs in imported content.
 *
 * - Block attributes declared in BlockReferenceMap (`{"id":12}`, `{"ids":[1,2]}`, `{"ref":5}`, …).
 *   Only block comment delimiters are touched; block HTML is left as-is.
 * - `wp-image-{id}` classes and `data-id="{id}"` attributes.
 * - `[gallery ids="…"]` shortcodes.
 * - Media file URLs (source uploads → local uploads).
 * - The source site URL, replaced with this site's URL.
 */
class ContentRewriter {

	/**
	 * Block comment delimiter pattern, from WP_Block_Parser.
	 */
	private const BLOCK_DELIMITER = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

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
	 * Rewrites content.
	 *
	 * @param string                $content          Content from the source site.
	 * @param callable              $map_post_id      Maps a source post ID to a local ID: fn( int $source_id ): ?int.
	 * @param array<string, string> $url_replacements Source URL => local URL (media files).
	 * @param string                $source_site_url  Source site URL, or '' to skip site URL replacement.
	 * @param string                $target_site_url  This site's URL.
	 * @return string
	 */
	public function rewrite( string $content, callable $map_post_id, array $url_replacements, string $source_site_url, string $target_site_url ): string {
		if ( '' === $content ) {
			return $content;
		}

		$content = $this->rewrite_block_attributes( $content, $map_post_id );
		$content = $this->rewrite_html_ids( $content, $map_post_id );
		$content = $this->rewrite_gallery_shortcodes( $content, $map_post_id );

		if ( array() !== $url_replacements ) {
			// strtr() replaces the longest matches first, so size URLs win over shorter prefixes.
			$content = strtr( $content, $url_replacements );
		}

		if ( '' !== $source_site_url && untrailingslashit( $source_site_url ) !== untrailingslashit( $target_site_url ) ) {
			$content = (string) preg_replace(
				'#' . preg_quote( untrailingslashit( $source_site_url ), '#' ) . '(?=[/"\'\s<>)?\#]|$)#',
				untrailingslashit( $target_site_url ),
				$content
			);
		}

		return $content;
	}

	/**
	 * Rewrites IDs in block attributes.
	 *
	 * @param string   $content     Content.
	 * @param callable $map_post_id ID mapper.
	 * @return string
	 */
	private function rewrite_block_attributes( string $content, callable $map_post_id ): string {
		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$map = $this->map->get();

		return (string) preg_replace_callback(
			self::BLOCK_DELIMITER,
			function ( array $matches ) use ( $map, $map_post_id ) {
				if ( ! empty( $matches['closer'] ) || empty( $matches['attrs'] ) ) {
					return $matches[0];
				}

				$name = ( '' !== $matches['namespace'] ? $matches['namespace'] : 'core/' ) . $matches['name'];
				if ( ! isset( $map[ $name ] ) ) {
					return $matches[0];
				}

				$attrs = json_decode( $matches['attrs'], true );
				if ( ! is_array( $attrs ) ) {
					return $matches[0];
				}

				$changed = false;
				foreach ( $map[ $name ] as $attribute ) {
					if ( ! isset( $attrs[ $attribute ] ) ) {
						continue;
					}
					$mapped = $this->map_value( $attrs[ $attribute ], $map_post_id );
					if ( $mapped !== $attrs[ $attribute ] ) {
						$attrs[ $attribute ] = $mapped;
						$changed             = true;
					}
				}

				if ( ! $changed ) {
					return $matches[0];
				}

				$void = ! empty( $matches['void'] ) ? '/' : '';

				return sprintf(
					'<!-- wp:%s%s %s %s-->',
					'' !== $matches['namespace'] ? $matches['namespace'] : '',
					$matches['name'],
					serialize_block_attributes( $attrs ),
					$void
				);
			},
			$content
		);
	}

	/**
	 * Maps an attribute value: an ID or a list of IDs. Unmapped IDs are kept.
	 *
	 * @param mixed    $value       Attribute value.
	 * @param callable $map_post_id ID mapper.
	 * @return mixed
	 */
	private function map_value( $value, callable $map_post_id ) {
		if ( is_array( $value ) ) {
			return array_map(
				function ( $item ) use ( $map_post_id ) {
					return $this->map_value( $item, $map_post_id );
				},
				$value
			);
		}

		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			$mapped = $map_post_id( (int) $value );
			if ( null !== $mapped ) {
				return is_int( $value ) ? $mapped : (string) $mapped;
			}
		}

		return $value;
	}

	/**
	 * Rewrites `wp-image-{id}` classes and `data-id="{id}"` attributes.
	 *
	 * @param string   $content     Content.
	 * @param callable $map_post_id ID mapper.
	 * @return string
	 */
	private function rewrite_html_ids( string $content, callable $map_post_id ): string {
		return (string) preg_replace_callback(
			'/\b(wp-image-|data-id=["\'])(\d+)\b/',
			static function ( array $matches ) use ( $map_post_id ) {
				$mapped = $map_post_id( (int) $matches[2] );
				return null === $mapped ? $matches[0] : $matches[1] . $mapped;
			},
			$content
		);
	}

	/**
	 * Rewrites `[gallery ids="…"]` shortcodes.
	 *
	 * @param string   $content     Content.
	 * @param callable $map_post_id ID mapper.
	 * @return string
	 */
	private function rewrite_gallery_shortcodes( string $content, callable $map_post_id ): string {
		if ( false === strpos( $content, '[gallery' ) ) {
			return $content;
		}

		return (string) preg_replace_callback(
			'/(\[gallery\b[^\]]*\bids=["\'])([\d,\s]+)(["\'])/',
			static function ( array $matches ) use ( $map_post_id ) {
				$ids = array();
				foreach ( explode( ',', $matches[2] ) as $id ) {
					$id     = (int) trim( $id );
					$mapped = $map_post_id( $id );
					$ids[]  = null === $mapped ? $id : $mapped;
				}
				return $matches[1] . implode( ',', $ids ) . $matches[3];
			},
			$content
		);
	}
}
