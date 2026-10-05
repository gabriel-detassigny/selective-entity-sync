<?php
/**
 * Tests for EntityUuid.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Identity;

use InvalidArgumentException;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Identity\Uuid;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Identity\EntityUuid
 */
class EntityUuidTest extends WP_UnitTestCase {

	/**
	 * @var EntityUuid
	 */
	private $uuids;

	public function set_up(): void {
		parent::set_up();
		$this->uuids = new EntityUuid();
	}

	public function test_get_generates_and_persists_a_uuid_for_a_post(): void {
		$post_id = self::factory()->post->create();

		$this->assertNull( $this->uuids->find( 'post', $post_id ) );

		$uuid = $this->uuids->get( 'post', $post_id );

		$this->assertTrue( Uuid::is_valid( $uuid ) );
		$this->assertSame( $uuid, get_post_meta( $post_id, EntityUuid::META_KEY, true ) );
		$this->assertSame( $uuid, $this->uuids->get( 'post', $post_id ), 'UUID is stable across calls.' );
	}

	public function test_get_works_for_terms_and_attachments(): void {
		$term_id       = self::factory()->term->create( array( 'taxonomy' => 'category' ) );
		$attachment_id = self::factory()->attachment->create();

		$term_uuid       = $this->uuids->get( 'term', $term_id );
		$attachment_uuid = $this->uuids->get( 'post', $attachment_id );

		$this->assertSame( $term_uuid, get_term_meta( $term_id, EntityUuid::META_KEY, true ) );
		$this->assertNotSame( $term_uuid, $attachment_uuid );
	}

	public function test_find_object_id_locates_posts_of_any_type_and_status(): void {
		$page_id  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft_id = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$trash_id = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$media_id = self::factory()->attachment->create();

		foreach ( array( $page_id, $draft_id, $trash_id, $media_id ) as $id ) {
			$uuid = $this->uuids->get( 'post', $id );
			$this->assertSame( $id, $this->uuids->find_object_id( 'post', $uuid ) );
		}
	}

	public function test_find_object_id_locates_terms_including_empty_ones(): void {
		$term_id = self::factory()->term->create( array( 'taxonomy' => 'post_tag' ) );
		$uuid    = $this->uuids->get( 'term', $term_id );

		$this->assertSame( $term_id, $this->uuids->find_object_id( 'term', $uuid ) );
	}

	public function test_find_object_id_is_case_insensitive_and_returns_null_when_missing(): void {
		$post_id = self::factory()->post->create();
		$uuid    = $this->uuids->get( 'post', $post_id );

		$this->assertSame( $post_id, $this->uuids->find_object_id( 'post', strtoupper( $uuid ) ) );
		$this->assertNull( $this->uuids->find_object_id( 'post', wp_generate_uuid4() ) );
		$this->assertNull( $this->uuids->find_object_id( 'post', 'not-a-uuid' ) );
		$this->assertNull( $this->uuids->find_object_id( 'term', $uuid ), 'Post UUIDs are not found as terms.' );
	}

	public function test_find_object_id_prefers_oldest_entity_when_uuid_is_duplicated(): void {
		$original  = self::factory()->post->create();
		$duplicate = self::factory()->post->create();
		$uuid      = $this->uuids->get( 'post', $original );
		update_post_meta( $duplicate, EntityUuid::META_KEY, $uuid );

		$this->assertSame( $original, $this->uuids->find_object_id( 'post', $uuid ) );
	}

	public function test_assign_sets_a_given_uuid(): void {
		$post_id = self::factory()->post->create();
		$uuid    = '3F2B8C1E-4A5D-4E6F-8A9B-0C1D2E3F4A5B';

		$this->uuids->assign( 'post', $post_id, $uuid );

		$this->assertSame( strtolower( $uuid ), $this->uuids->find( 'post', $post_id ) );
	}

	public function test_assign_rejects_invalid_uuid(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->uuids->assign( 'post', self::factory()->post->create(), 'nope' );
	}

	public function test_invalid_stored_value_is_ignored(): void {
		$post_id = self::factory()->post->create();
		update_post_meta( $post_id, EntityUuid::META_KEY, 'garbage' );

		$this->assertNull( $this->uuids->find( 'post', $post_id ) );
	}

	public function test_get_rejects_missing_objects(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->uuids->get( 'post', PHP_INT_MAX );
	}

	public function test_rejects_unsupported_object_types(): void {
		$this->expectException( InvalidArgumentException::class );

		$this->uuids->find( 'user', 1 );
	}
}
