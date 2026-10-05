<?php
/**
 * Tests for EntitiesController.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Rest;

/**
 * @covers \SelectiveEntitySync\Rest\EntitiesController
 * @covers \SelectiveEntitySync\Rest\Controller
 */
class EntitiesControllerTest extends RestTestCase {

	public function test_requires_the_sync_capability(): void {
		$this->assertSame( 401, $this->request( 'GET', '/entities' )->get_status() );

		$this->login_as( 'editor' );
		$this->assertSame( 403, $this->request( 'GET', '/entities' )->get_status() );
	}

	public function test_lists_exportable_content_only(): void {
		$this->login_as( 'administrator' );
		$post  = self::factory()->post->create( array( 'post_title' => 'A &amp; B' ) );
		$page  = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$draft = self::factory()->post->create( array( 'post_status' => 'draft' ) );
		$trash = self::factory()->post->create( array( 'post_status' => 'trash' ) );
		$media = self::factory()->attachment->create();

		$response = $this->request( 'GET', '/entities', array( 'per_page' => 100 ) );
		$ids      = wp_list_pluck( $response->get_data(), 'id' );

		$this->assertSame( 200, $response->get_status() );
		$this->assertContains( $post, $ids );
		$this->assertContains( $page, $ids );
		$this->assertContains( $draft, $ids );
		$this->assertNotContains( $trash, $ids );
		$this->assertNotContains( $media, $ids );

		$item = wp_list_filter( $response->get_data(), array( 'id' => $post ) );
		$item = reset( $item );
		$this->assertSame( 'A & B', $item['title'] );
		$this->assertSame( 'post', $item['post_type'] );
		$this->assertSame( 'Post', $item['post_type_label'] );
		$this->assertSame( 'publish', $item['status'] );
		$this->assertNull( $item['uuid'] );
	}

	public function test_filters_search_and_pagination(): void {
		$this->login_as( 'administrator' );
		self::factory()->post->create_many( 3, array( 'post_title' => 'Alpha' ) );
		$page  = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Beta page',
			)
		);
		$draft = self::factory()->post->create(
			array(
				'post_status' => 'draft',
				'post_title'  => 'Gamma',
			)
		);

		$by_type = $this->request( 'GET', '/entities', array( 'post_type' => 'page' ) )->get_data();
		$this->assertSame( array( $page ), wp_list_pluck( $by_type, 'id' ) );

		$by_status = $this->request( 'GET', '/entities', array( 'status' => 'draft' ) )->get_data();
		$this->assertSame( array( $draft ), wp_list_pluck( $by_status, 'id' ) );

		$search = $this->request( 'GET', '/entities', array( 'search' => 'Beta' ) )->get_data();
		$this->assertSame( array( $page ), wp_list_pluck( $search, 'id' ) );

		$paged = $this->request(
			'GET',
			'/entities',
			array(
				'search'   => 'Alpha',
				'per_page' => 2,
			)
		);
		$this->assertCount( 2, $paged->get_data() );
		$this->assertSame( '3', $paged->get_headers()['X-WP-Total'] );
		$this->assertSame( '2', $paged->get_headers()['X-WP-TotalPages'] );
	}

	public function test_rejects_non_exportable_filters(): void {
		$this->login_as( 'administrator' );

		$this->assertSame( 400, $this->request( 'GET', '/entities', array( 'post_type' => 'attachment' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/entities', array( 'status' => 'trash' ) )->get_status() );
		$this->assertSame( 400, $this->request( 'GET', '/entities', array( 'per_page' => 500 ) )->get_status() );
	}
}
