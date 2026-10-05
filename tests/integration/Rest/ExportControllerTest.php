<?php
/**
 * Tests for ExportController.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Rest;

use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Rest\ExportController;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Support\Capabilities;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use WP_REST_Request;
use WP_REST_Response;

/**
 * @covers \SelectiveEntitySync\Rest\ExportController
 */
class ExportControllerTest extends RestTestCase {

	use BuildsExporter;

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function test_routes_require_the_sync_capability(): void {
		$this->login_as( 'editor' );
		$post = self::factory()->post->create();

		$this->assertSame( 403, $this->request( 'GET', '/export/options' )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/export/preview', array( 'post_ids' => array( $post ) ) )->get_status() );
		$this->assertSame( 403, $this->request( 'POST', '/export', array( 'post_ids' => array( $post ) ) )->get_status() );
	}

	public function test_options_list_exportable_post_types_and_statuses(): void {
		$this->login_as( 'administrator' );

		$data = $this->request( 'GET', '/export/options' )->get_data();

		$this->assertContains(
			array(
				'name'  => 'page',
				'label' => 'Page',
			),
			$data['post_types']
		);
		$this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), wp_list_pluck( $data['statuses'], 'name' ) );
	}

	public function test_preview_summarises_selection_and_dependencies(): void {
		$this->login_as( 'administrator' );
		$attachment = $this->create_image_attachment();
		$post       = self::factory()->post->create( array( 'post_title' => 'Selected' ) );
		set_post_thumbnail( $post, $attachment );

		$response = $this->request( 'POST', '/export/preview', array( 'post_ids' => array( $post ) ) );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 3, $data['entities'], 'Post, featured image and the default category.' );
		$this->assertSame( 'Selected', $data['entities'][0]['title'] );
		$this->assertTrue( $data['entities'][0]['selected'] );
		$this->assertSame( 1, $data['file_count'] );
		$this->assertSame( filesize( get_attached_file( $attachment ) ), $data['file_bytes'] );
		$this->assertSame( array(), $data['warnings'] );
	}

	public function test_preview_and_export_reject_invalid_selections(): void {
		$this->login_as( 'administrator' );
		$trashed = self::factory()->post->create( array( 'post_status' => 'trash' ) );

		$response = $this->request( 'POST', '/export/preview', array( 'post_ids' => array( $trashed ) ) );
		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'selective_entity_sync_export_failed', $response->get_data()['code'] );

		$this->assertSame( 400, $this->request( 'POST', '/export', array( 'post_ids' => array() ) )->get_status() );
		$this->assertSame( 400, $this->request( 'POST', '/export/preview', array() )->get_status() );
	}

	public function test_export_streams_the_zip_and_deletes_it(): void {
		$this->login_as( 'administrator' );
		$post       = self::factory()->post->create();
		$storage    = new TempStorage();
		$controller = new ExportController( new Capabilities(), new ExportSettings(), $this->build_exporter(), $storage );

		$list_temp_dirs = static function () use ( $storage ) {
			return is_dir( $storage->get_base_directory() ) ? (array) scandir( $storage->get_base_directory() ) : array();
		};
		$dirs_before    = $list_temp_dirs();

		$request = new WP_REST_Request( 'POST', '/selective-entity-sync/v1/export' );
		$request->set_param( 'post_ids', array( $post ) );
		$response = $controller->export( $request );
		$this->assertInstanceOf( WP_REST_Response::class, $response );
		$this->assertCount( count( $dirs_before ) + ( array() === $dirs_before ? 3 : 1 ), $list_temp_dirs(), 'The package is built in a new temp directory.' );

		$other_route = new WP_REST_Request( 'GET', '/selective-entity-sync/v1/entities' );
		$this->assertFalse( $controller->maybe_serve_package( false, $response, $other_route ), 'Other routes are served normally.' );

		ob_start();
		$served = $controller->maybe_serve_package( false, $response, $request );
		$body   = (string) ob_get_clean();

		$this->assertTrue( $served );
		$this->assertStringStartsWith( "PK\x03\x04", $body, 'The response body is a zip file.' );
		$this->assertStringContainsString( 'manifest.json', $body );
		$this->assertSame( $dirs_before, $list_temp_dirs(), 'The package directory is deleted once served.' );
		$this->assertFalse( $controller->maybe_serve_package( false, $response, $request ), 'A package is only served once.' );
		$this->assertNotNull( ( new EntityUuid() )->find( 'post', $post ) );
	}

	public function test_download_filename(): void {
		$controller = new ExportController( new Capabilities(), new ExportSettings(), $this->build_exporter(), new TempStorage() );

		$this->assertMatchesRegularExpression( '/^selective-entity-sync-[a-z0-9-]+-\d{8}-\d{6}\.zip$/', $controller->get_download_filename() );
	}
}
