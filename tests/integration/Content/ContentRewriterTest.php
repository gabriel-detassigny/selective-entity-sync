<?php
/**
 * Tests for ContentRewriter.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Content;

use SelectiveEntitySync\Content\BlockReferenceMap;
use SelectiveEntitySync\Content\ContentRewriter;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Content\ContentRewriter
 */
class ContentRewriterTest extends WP_UnitTestCase {

	/**
	 * Source ID => local ID.
	 */
	private const IDS = array(
		11 => 111,
		12 => 112,
		13 => 113,
		19 => 119,
	);

	private function rewrite( string $content, array $urls = array(), string $source_url = '', string $target_url = 'https://prod.example.com' ): string {
		return ( new ContentRewriter( new BlockReferenceMap() ) )->rewrite(
			$content,
			static function ( int $id ) {
				return self::IDS[ $id ] ?? null;
			},
			$urls,
			$source_url,
			$target_url
		);
	}

	public function test_rewrites_block_attributes_and_keeps_block_html(): void {
		$content = '<!-- wp:image {"id":11,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-11"/></figure><!-- /wp:image -->';

		$this->assertSame(
			'<!-- wp:image {"id":111,"sizeSlug":"large"} --><figure class="wp-block-image"><img src="a.jpg" alt="" class="wp-image-111"/></figure><!-- /wp:image -->',
			$this->rewrite( $content )
		);
	}

	public function test_rewrites_nested_blocks_lists_and_self_closing_blocks(): void {
		$content = '<!-- wp:group --><div><!-- wp:gallery {"ids":[11,12,99]} --><figure></figure><!-- /wp:gallery --><!-- wp:block {"ref":19} /--></div><!-- /wp:group -->';

		$this->assertSame(
			'<!-- wp:group --><div><!-- wp:gallery {"ids":[111,112,99]} --><figure></figure><!-- /wp:gallery --><!-- wp:block {"ref":119} /--></div><!-- /wp:group -->',
			$this->rewrite( $content ),
			'Unknown IDs (99) are kept.'
		);
	}

	public function test_leaves_unmapped_blocks_and_other_attributes_untouched(): void {
		$content = '<!-- wp:paragraph {"id":11} --><p>id 11 is not a reference here</p><!-- /wp:paragraph --><!-- wp:image {"id":12,"caption":"Café <b>"} /-->';

		$result = $this->rewrite( $content );

		$this->assertStringStartsWith( '<!-- wp:paragraph {"id":11} -->', $result );
		$this->assertStringContainsString( '"id":112', $result );
		$this->assertSame(
			array(
				'id'      => 112,
				'caption' => 'Café <b>',
			),
			parse_blocks( $result )[1]['attrs'],
			'Attributes survive re-serialization.' 
		);
	}

	public function test_rewrites_classic_content_ids_and_gallery_shortcodes(): void {
		$content = '<img class="alignleft wp-image-13" data-id="12"/> [gallery ids="11, 12,99" columns="2"]';

		$this->assertSame(
			'<img class="alignleft wp-image-113" data-id="112"/> [gallery ids="111,112,99" columns="2"]',
			$this->rewrite( $content )
		);
	}

	public function test_replaces_media_urls_longest_first(): void {
		$urls    = array(
			'https://staging.example.com/wp-content/uploads/2026/10/a.jpg'         => 'https://prod.example.com/wp-content/uploads/2026/11/a.jpg',
			'https://staging.example.com/wp-content/uploads/2026/10/a-300x200.jpg' => 'https://prod.example.com/wp-content/uploads/2026/11/a-300x200.jpg',
		);
		$content = '<img src="https://staging.example.com/wp-content/uploads/2026/10/a-300x200.jpg" srcset="https://staging.example.com/wp-content/uploads/2026/10/a.jpg 1024w"/>';

		$this->assertSame(
			'<img src="https://prod.example.com/wp-content/uploads/2026/11/a-300x200.jpg" srcset="https://prod.example.com/wp-content/uploads/2026/11/a.jpg 1024w"/>',
			$this->rewrite( $content, $urls )
		);
	}

	public function test_replaces_source_site_url_on_boundaries_only(): void {
		$content = '<a href="https://staging.example.com/about/">About</a> <a href="https://staging.example.com">Home</a> https://staging.example.com.evil.test/x';

		$this->assertSame(
			'<a href="https://prod.example.com/about/">About</a> <a href="https://prod.example.com">Home</a> https://staging.example.com.evil.test/x',
			$this->rewrite( $content, array(), 'https://staging.example.com/' )
		);
	}

	public function test_no_site_url_replacement_when_disabled_or_identical(): void {
		$content = '<a href="https://staging.example.com/about/">About</a>';

		$this->assertSame( $content, $this->rewrite( $content, array(), '' ) );
		$this->assertSame( $content, $this->rewrite( $content, array(), 'https://staging.example.com', 'https://staging.example.com/' ) );
	}
}
