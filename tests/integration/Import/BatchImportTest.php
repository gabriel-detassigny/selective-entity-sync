<?php
/**
 * Tests for batched imports.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Import;

use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\ImportJob;
use SelectiveEntitySync\Import\ImportJobStore;
use SelectiveEntitySync\Import\ImportReport;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\Package;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Import\Importer
 * @covers \SelectiveEntitySync\Import\ImportJob
 * @covers \SelectiveEntitySync\Import\ImportJobStore
 * @covers \SelectiveEntitySync\Import\ImportContext
 * @covers \SelectiveEntitySync\Package\Package
 */
class BatchImportTest extends WP_UnitTestCase {

	use BuildsExporter;
	use BuildsImporter;

	private const ONE_PER_BATCH = array(
		'max_entities' => 1,
		'max_seconds'  => 60,
	);

	/**
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Export temp directories to delete.
	 *
	 * @var string[]
	 */
	private $export_dirs = array();

	public function set_up(): void {
		parent::set_up();
		$this->uuids = new EntityUuid();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->delete_temp_dirs();
		$storage = new TempStorage();
		foreach ( $this->export_dirs as $directory ) {
			$storage->delete( $directory );
		}
		$this->remove_added_uploads();
		parent::tear_down();
	}

	/**
	 * @param int[] $post_ids Post IDs.
	 */
	private function export( array $post_ids ): string {
		$result              = $this->build_exporter()->export( $post_ids );
		$this->export_dirs[] = $result->get_directory();

		return $result->get_package_path();
	}

	/**
	 * Opens a package lazily in a fresh directory, as each batch request does.
	 */
	private function open_package( string $zip ): Package {
		$directory         = ( new TempStorage() )->create_directory();
		$this->temp_dirs[] = $directory;

		return ( new PackageReader( new ManifestCodec( new Schema() ), new PackageLimits() ) )->open( $zip, $directory );
	}

	/**
	 * Imports one entity per "request": the job goes through JSON between batches
	 * and the package is re-opened each time, like the REST flow.
	 *
	 * @return array{report: ImportReport, batches: int}
	 */
	private function import_in_batches( string $zip ): array {
		$importer = $this->build_importer();
		$job      = $importer->start( $this->open_package( $zip ) );
		$batches  = 0;

		while ( ! $job->is_done() ) {
			$job = ImportJob::from_array( json_decode( (string) wp_json_encode( $job->to_array() ), true ) );
			$importer->run( $job, $this->open_package( $zip ), self::ONE_PER_BATCH );
			++$batches;
			$this->assertLessThan( 50, $batches, 'The job must finish.' );
		}

		return array(
			'report'  => $job->get_report(),
			'batches' => $batches,
		);
	}

	public function test_batched_import_remaps_everything_like_a_single_run(): void {
		$image      = $this->create_image_attachment();
		$parent_cat = self::factory()->category->create();
		$child_cat  = self::factory()->category->create( array( 'parent' => $parent_cat ) );
		$parent     = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$image_url  = wp_get_attachment_image_src( $image, 'thumbnail' )[0];
		$post       = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_parent'  => $parent,
				'post_content' => '<!-- wp:image {"id":' . $image . '} --><figure><img src="' . $image_url . '" class="wp-image-' . $image . '"/></figure><!-- /wp:image -->',
			)
		);
		set_post_thumbnail( $post, $image );
		$news = self::factory()->post->create();
		wp_set_post_categories( $news, array( $child_cat ) );

		$zip  = $this->export( array( $post, $news ) );
		$uuid = array(
			'post'       => $this->uuids->find( 'post', $post ),
			'parent'     => $this->uuids->find( 'post', $parent ),
			'image'      => $this->uuids->find( 'post', $image ),
			'child_cat'  => $this->uuids->find( 'term', $child_cat ),
			'parent_cat' => $this->uuids->find( 'term', $parent_cat ),
			'news'       => $this->uuids->find( 'post', $news ),
		);
		foreach ( array( $post, $parent, $image, $news ) as $id ) {
			wp_delete_post( $id, true );
		}
		wp_delete_term( $child_cat, 'category' );
		wp_delete_term( $parent_cat, 'category' );

		$events = array();
		add_action(
			'selective_entity_sync_before_import',
			static function () use ( &$events ) {
				$events[] = 'before';
			}
		);
		add_action(
			'selective_entity_sync_after_import',
			static function () use ( &$events ) {
				$events[] = 'after';
			}
		);

		$result = $this->import_in_batches( $zip );
		$report = $result['report'];

		$this->assertFalse( $report->has_failures(), (string) wp_json_encode( $report->to_array() ) );
		$this->assertGreaterThanOrEqual( 6, $result['batches'], 'One entity per batch.' );
		$this->assertSame( array( 'before', 'after' ), $events, 'Lifecycle actions fire once per import, not per batch.' );

		$new_post  = (int) $this->uuids->find_object_id( 'post', $uuid['post'] );
		$new_image = (int) $this->uuids->find_object_id( 'post', $uuid['image'] );
		$new_child = (int) $this->uuids->find_object_id( 'term', $uuid['child_cat'] );

		$this->assertSame( $this->uuids->find_object_id( 'post', $uuid['parent'] ), wp_get_post_parent_id( $new_post ) );
		$this->assertSame( $new_image, (int) get_post_thumbnail_id( $new_post ) );
		$this->assertStringContainsString( '{"id":' . $new_image . '}', get_post( $new_post )->post_content );
		$this->assertStringContainsString( wp_get_attachment_image_src( $new_image, 'thumbnail' )[0], get_post( $new_post )->post_content, 'Media URL replacements survive between batches.' );
		$this->assertSame( $this->uuids->find_object_id( 'term', $uuid['parent_cat'] ), get_term( $new_child )->parent );
		$this->assertSame( array( $new_child ), wp_get_post_categories( (int) $this->uuids->find_object_id( 'post', $uuid['news'] ) ) );
	}

	public function test_reference_cycles_are_resolved_across_batches(): void {
		add_filter(
			'selective_entity_sync_id_reference_meta_keys',
			static function ( array $keys ) {
				$keys['related'] = 'post';
				return $keys;
			}
		);
		$first  = self::factory()->post->create();
		$second = self::factory()->post->create();
		update_post_meta( $first, 'related', (string) $second );
		update_post_meta( $second, 'related', (string) $first );

		$zip = $this->export( array( $first ) );
		$a   = $this->uuids->find( 'post', $first );
		$b   = $this->uuids->find( 'post', $second );
		wp_delete_post( $first, true );
		wp_delete_post( $second, true );

		$this->import_in_batches( $zip );

		$new_a = (int) $this->uuids->find_object_id( 'post', $a );
		$new_b = (int) $this->uuids->find_object_id( 'post', $b );
		$this->assertSame( (string) $new_b, get_post_meta( $new_a, 'related', true ) );
		$this->assertSame( (string) $new_a, get_post_meta( $new_b, 'related', true ) );
	}

	public function test_lazy_packages_extract_only_requested_files_and_verify_them(): void {
		$post = self::factory()->post->create();
		set_post_thumbnail( $post, $this->create_image_attachment() );
		$zip     = $this->export( array( $post ) );
		$package = $this->open_package( $zip );
		$path    = $package->get_manifest()->get_files()[0]['path'];

		$this->assertFileDoesNotExist( $package->get_directory() . '/' . $path, 'Nothing is extracted on open.' );
		$this->assertFileExists( $package->get_file_path( $path ) );

		// The stored zip is swapped for one with different file contents after validation.
		$tampered = $this->open_package( $zip );
		$archive  = new \ZipArchive();
		$archive->open( $zip );
		$archive->addFromString( $path, 'tampered' );
		$archive->close();

		$this->expectException( \SelectiveEntitySync\Package\PackageException::class );
		$this->expectExceptionMessage( 'checksum does not match' );
		$tampered->get_file_path( $path );
	}

	public function test_job_store_locks_ownership_and_cleanup(): void {
		$store = new ImportJobStore();
		$job   = new ImportJob( array(), array(), new ImportReport() );
		$store->save( 'token-a', $job );

		$this->assertInstanceOf( ImportJob::class, $store->load( 'token-a' ) );

		$this->assertTrue( $store->acquire_lock( 'token-a' ) );
		$this->assertFalse( $store->acquire_lock( 'token-a' ), 'A running batch blocks a second one.' );
		update_option( ImportJobStore::LOCK_PREFIX . 'token-a', time() - 3600 );
		$this->assertTrue( $store->acquire_lock( 'token-a' ), 'Abandoned locks are taken over.' );
		$store->release_lock( 'token-a' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertNull( $store->load( 'token-a' ), 'Jobs belong to the user who started them.' );

		$index            = get_option( ImportJobStore::INDEX_OPTION );
		$index['token-a'] = time() - DAY_IN_SECONDS - 60;
		update_option( ImportJobStore::INDEX_OPTION, $index );
		do_action( TempStorage::CRON_HOOK );

		$this->assertFalse( get_option( ImportJobStore::OPTION_PREFIX . 'token-a' ) );
	}
}
