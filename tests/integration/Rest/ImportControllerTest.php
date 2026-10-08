<?php
/**
 * Tests for ImportController and PackageStore.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Rest;

use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\ImportJobStore;
use SelectiveEntitySync\Import\PackageStore;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use WP_REST_Request;

/**
 * @covers \SelectiveEntitySync\Rest\ImportController
 * @covers \SelectiveEntitySync\Import\PackageStore
 */
class ImportControllerTest extends RestTestCase {

	use BuildsExporter;

	/**
	 * Temp directories to delete.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	public function tear_down(): void {
		$storage = new TempStorage();
		foreach ( $this->dirs as $directory ) {
			$storage->delete( $directory );
		}
		parent::tear_down();
	}

	/**
	 * Exports a post and returns the path of a copy of the package (as if uploaded).
	 */
	private function make_package( int $post_id ): string {
		$result       = $this->build_exporter()->export( array( $post_id ) );
		$this->dirs[] = $result->get_directory();
		$upload       = $result->get_directory() . '/upload.zip';
		copy( $result->get_package_path(), $upload );

		return $upload;
	}

	/**
	 * Stores a package as the current user and returns its token.
	 */
	private function store( string $path, string $name = 'sync.zip' ): string {
		return ( new PackageStore() )->store_file( $path, $name );
	}

	public function test_routes_require_the_sync_capability(): void {
		$this->login_as( 'editor' );

		$this->assertSame( 403, rest_do_request( new WP_REST_Request( 'POST', '/selective-entity-sync/v1/import/packages' ) )->get_status() );
		$this->assertSame( 403, $this->request( 'GET', '/import/packages/' . wp_generate_uuid4() )->get_status() );
	}

	public function test_plan_then_import_runs_and_cleans_up(): void {
		$this->login_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_title' => 'Synced' ) );
		$path = $this->make_package( $post );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Changed on target',
			)
		);

		$token    = $this->store( $path );
		$response = $this->request( 'GET', '/import/packages/' . $token );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertSame( $token, $data['token'] );
		$this->assertSame( 'sync.zip', $data['filename'] );
		$this->assertSame( home_url(), $data['source']['site_url'] );
		$this->assertSame( 2, $data['counts']['update'], 'Post and its default category.' );

		$store = new PackageStore();
		$this->assertFileExists( $store->get_directory() . '/.htaccess', 'The storage directory denies web access.' );
		$this->assertFileExists( $store->get_directory() . '/index.php' );
		$this->assertNotNull( $store->get_path( $data['token'] ) );

		$import = $this->request( 'POST', '/import/packages/' . $data['token'] . '/import' );
		$this->assertSame( 200, $import->get_status(), (string) wp_json_encode( $import->get_data() ) );
		$this->assertTrue( $import->get_data()['done'] );
		$this->assertSame( 2, $import->get_data()['report']['counts']['updated'] );
		$this->assertSame( 'Synced', get_post( $post )->post_title );
		$this->assertNull( $store->get_path( $data['token'] ), 'The package is deleted after import.' );
		$this->assertSame( 404, $this->request( 'POST', '/import/packages/' . $data['token'] . '/import' )->get_status() );
	}

	public function test_large_imports_run_in_batches_until_done(): void {
		$this->login_as( 'administrator' );
		$parent = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$child  = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
				'post_title'  => 'Batched child',
			)
		);
		$token  = $this->store( $this->make_package( $child ) );
		wp_update_post(
			array(
				'ID'         => $child,
				'post_title' => 'Changed',
			)
		);
		add_filter(
			'selective_entity_sync_import_batch_limits',
			static function () {
				return array(
					'max_entities' => 1,
					'max_seconds'  => 60,
				);
			}
		);

		$first = $this->request( 'POST', '/import/packages/' . $token . '/import' )->get_data();
		$this->assertFalse( $first['done'] );
		$this->assertSame( 1, $first['processed'] );
		$this->assertSame( 2, $first['total'] );
		$this->assertArrayNotHasKey( 'report', $first );
		$this->assertSame( 409, $this->request( 'POST', '/import/packages/' . $token . '/import' )->get_status(), 'An import starts only once.' );

		$jobs = new ImportJobStore();
		$jobs->acquire_lock( $token );
		$this->assertSame( 409, $this->request( 'POST', '/import/packages/' . $token . '/import/next' )->get_status(), 'Concurrent batches are refused.' );
		$jobs->release_lock( $token );

		$responses = 0;
		do {
			$next = $this->request( 'POST', '/import/packages/' . $token . '/import/next' )->get_data();
			++$responses;
		} while ( ! $next['done'] && $responses < 10 );

		$this->assertTrue( $next['done'] );
		$this->assertSame( 2, $next['report']['counts']['updated'] );
		$this->assertSame( 'Batched child', get_post( $child )->post_title );
		$this->assertNull( ( new PackageStore() )->get_path( $token ), 'The package is deleted once done.' );
		$this->assertNull( $jobs->load( $token ), 'The job is deleted once done.' );
		$this->assertSame( 404, $this->request( 'POST', '/import/packages/' . $token . '/import/next' )->get_status() );
	}

	public function test_discarding_a_started_import_deletes_its_job(): void {
		$this->login_as( 'administrator' );
		$token = $this->store( $this->make_package( self::factory()->post->create() ) );
		add_filter(
			'selective_entity_sync_import_batch_limits',
			static function () {
				return array(
					'max_entities' => 1,
					'max_seconds'  => 60,
				);
			}
		);
		$this->request( 'POST', '/import/packages/' . $token . '/import' );
		$this->assertNotNull( ( new ImportJobStore() )->load( $token ) );

		$this->assertSame( 200, $this->request( 'DELETE', '/import/packages/' . $token )->get_status() );

		$this->assertNull( ( new ImportJobStore() )->load( $token ) );
	}

	public function test_import_honours_skip_list(): void {
		$this->login_as( 'administrator' );
		$post = self::factory()->post->create( array( 'post_title' => 'Synced' ) );
		$path = $this->make_package( $post );
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Keep me',
			)
		);
		$token = $this->store( $path );

		$report = $this->request( 'POST', '/import/packages/' . $token . '/import', array( 'skip' => array( ( new EntityUuid() )->find( 'post', $post ) ) ) )->get_data()['report'];

		$this->assertSame( 1, $report['counts']['skipped'] );
		$this->assertSame( 'Keep me', get_post( $post )->post_title );
	}

	public function test_packages_are_private_to_their_uploader(): void {
		$this->login_as( 'administrator' );
		$token = $this->store( $this->make_package( self::factory()->post->create() ) );

		$this->login_as( 'administrator' );

		$this->assertSame( 404, $this->request( 'GET', '/import/packages/' . $token )->get_status() );
		$this->assertSame( 404, $this->request( 'DELETE', '/import/packages/' . $token )->get_status() );
	}

	public function test_discard_deletes_the_package(): void {
		$this->login_as( 'administrator' );
		$token = $this->store( $this->make_package( self::factory()->post->create() ) );

		$this->assertSame( 200, $this->request( 'DELETE', '/import/packages/' . $token )->get_status() );
		$this->assertSame( 404, $this->request( 'GET', '/import/packages/' . $token )->get_status() );
	}

	public function test_upload_requires_a_file(): void {
		$this->login_as( 'administrator' );

		$response = rest_do_request( new WP_REST_Request( 'POST', '/selective-entity-sync/v1/import/packages' ) );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'selective_entity_sync_no_package', $response->get_data()['code'] );
	}

	public function test_store_rejects_non_zip_files_and_invalid_packages_are_not_kept(): void {
		$this->login_as( 'administrator' );
		$store   = new PackageStore();
		$not_zip = wp_tempnam( 'notes.txt' );
		file_put_contents( $not_zip, 'hello' );

		try {
			$store->store_file( $not_zip, 'notes.txt' );
			$this->fail( 'A non-zip file was accepted.' );
		} catch ( \SelectiveEntitySync\Import\ImportException $e ) {
			$this->assertStringContainsString( 'could not be uploaded', $e->getMessage() );
		}

		// A file named .zip that isn't one is rejected by the content type check.
		$fake = wp_tempnam( 'fake.zip' );
		file_put_contents( $fake, 'not a zip' );
		$this->expectException( \SelectiveEntitySync\Import\ImportException::class );
		try {
			$store->store_file( $fake, 'fake.zip' );
		} finally {
			unlink( $not_zip );
			unlink( $fake );
		}
	}

	public function test_error_messages_are_plain_text(): void {
		$this->login_as( 'administrator' );
		$token = $this->store( $this->make_package( self::factory()->post->create() ) );
		$path  = (string) ( new PackageStore() )->get_path( $token );

		// Replace the stored package with one whose manifest is invalid ("…" quotes in the message).
		$zip = new \ZipArchive();
		$zip->open( $path, \ZipArchive::OVERWRITE );
		$zip->addFromString( 'manifest.json', '{"schema_version":1}' );
		$zip->close();

		$response = $this->request( 'GET', '/import/packages/' . $token );

		$this->assertSame( 400, $response->get_status() );
		$this->assertStringContainsString( 'invalid: "', $response->get_data()['message'], 'The admin app renders messages as text, so they must not hold HTML entities.' );
	}

	public function test_scheduled_cleanup_removes_expired_packages(): void {
		$this->login_as( 'administrator' );
		$token = $this->store( $this->make_package( self::factory()->post->create() ) );
		$store = new PackageStore();
		$path  = (string) $store->get_path( $token );
		touch( $path, time() - DAY_IN_SECONDS - 60 );

		do_action( TempStorage::CRON_HOOK );

		$this->assertFileDoesNotExist( $path );
	}
}
