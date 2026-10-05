<?php
/**
 * Tests for ContentReferenceFinder.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Content;

use SelectiveEntitySync\Content\BlockReferenceMap;
use SelectiveEntitySync\Content\ContentReferenceFinder;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Content\ContentReferenceFinder
 * @covers \SelectiveEntitySync\Content\BlockReferenceMap
 */
class ContentReferenceFinderTest extends WP_UnitTestCase {

	private function finder(): ContentReferenceFinder {
		return new ContentReferenceFinder( new BlockReferenceMap() );
	}

	public function test_finds_ids_in_core_blocks_including_nested_ones(): void {
		$content = <<<'HTML'
<!-- wp:image {"id":11} --><figure class="wp-block-image"><img src="a.jpg" class="wp-image-11"/></figure><!-- /wp:image -->
<!-- wp:group --><div class="wp-block-group">
<!-- wp:media-text {"mediaId":12} --><div class="wp-block-media-text"></div><!-- /wp:media-text -->
<!-- wp:cover {"id":13} --><div class="wp-block-cover"></div><!-- /wp:cover -->
</div><!-- /wp:group -->
<!-- wp:gallery {"ids":[14,15]} --><figure class="wp-block-gallery"></figure><!-- /wp:gallery -->
<!-- wp:file {"id":16} /-->
<!-- wp:video {"id":17} /-->
<!-- wp:audio {"id":18} /-->
<!-- wp:block {"ref":19} /-->
HTML;

		$this->assertSame( array( 11, 12, 13, 14, 15, 16, 17, 18, 19 ), $this->finder()->find_post_ids( $content ) );
	}

	public function test_finds_wp_image_classes_in_classic_content(): void {
		$content = '<p><img class="alignnone size-medium wp-image-21" src="x.jpg"/> <img class="wp-image-22 aligncenter" src="y.jpg"/></p>';

		$this->assertSame( array( 21, 22 ), $this->finder()->find_post_ids( $content ) );
	}

	public function test_finds_gallery_shortcode_ids(): void {
		$this->assertSame( array( 31, 32, 33 ), $this->finder()->find_post_ids( 'Before [gallery columns="2" ids="31, 32,33"] after' ) );
	}

	public function test_returns_unique_ids_and_ignores_empty_content(): void {
		$content = '<!-- wp:image {"id":41} --><img class="wp-image-41"/><!-- /wp:image -->';

		$this->assertSame( array( 41 ), $this->finder()->find_post_ids( $content ) );
		$this->assertSame( array(), $this->finder()->find_post_ids( '' ) );
		$this->assertSame( array(), $this->finder()->find_post_ids( '<p>No references here.</p>' ) );
	}

	public function test_custom_blocks_can_be_declared_with_a_filter(): void {
		add_filter(
			'selective_entity_sync_block_reference_attributes',
			static function ( array $map ) {
				$map['acme/hero'] = array( 'imageId', 'backgroundIds' );
				return $map;
			}
		);

		$content = '<!-- wp:acme/hero {"imageId":51,"backgroundIds":[52,53],"title":"x"} /-->';

		$this->assertSame( array( 51, 52, 53 ), $this->finder()->find_post_ids( $content ) );
	}
}
