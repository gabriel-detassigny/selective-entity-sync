<?php
/**
 * Tests for TempStorage.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Storage;

use SelectiveEntitySync\Storage\TempStorage;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Storage\TempStorage
 */
class TempStorageTest extends WP_UnitTestCase {

	/**
	 * @var TempStorage
	 */
	private $storage;

	public function set_up(): void {
		parent::set_up();
		$this->storage = new TempStorage();
	}

	public function test_create_directory_creates_unique_dirs_under_temp_dir(): void {
		$first  = $this->storage->create_directory();
		$second = $this->storage->create_directory();

		$this->assertDirectoryExists( $first );
		$this->assertNotSame( $first, $second );
		$this->assertStringStartsWith( trailingslashit( get_temp_dir() ) . TempStorage::DIRECTORY_NAME . '/', $first );

		$this->storage->delete( $first );
		$this->storage->delete( $second );
	}

	public function test_delete_removes_directory_recursively(): void {
		$dir = $this->storage->create_directory();
		wp_mkdir_p( $dir . '/media/nested' );
		file_put_contents( $dir . '/media/nested/file.txt', 'x' );

		$this->assertTrue( $this->storage->delete( $dir ) );
		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_delete_refuses_paths_outside_base_directory(): void {
		$outside = trailingslashit( get_temp_dir() ) . 'ses-outside-' . wp_generate_password( 8, false );
		wp_mkdir_p( $outside );

		$this->assertFalse( $this->storage->delete( $outside ) );
		$this->assertFalse( $this->storage->delete( $this->storage->get_base_directory() ), 'The base directory itself is protected.' );
		$this->assertFalse( $this->storage->delete( $this->storage->get_base_directory() . '/../' . basename( $outside ) ) );
		$this->assertDirectoryExists( $outside );

		rmdir( $outside );
	}

	public function test_cleanup_expired_deletes_only_stale_directories(): void {
		$stale = $this->storage->create_directory();
		$fresh = $this->storage->create_directory();
		touch( $stale, time() - DAY_IN_SECONDS - 60 );

		$deleted = $this->storage->cleanup_expired();

		$this->assertGreaterThanOrEqual( 1, $deleted );
		$this->assertDirectoryDoesNotExist( $stale );
		$this->assertDirectoryExists( $fresh );

		$this->storage->delete( $fresh );
	}

	public function test_lifetime_is_filterable(): void {
		$dir = $this->storage->create_directory();
		touch( $dir, time() - 120 );

		$set_lifetime = static function () {
			return 60;
		};
		add_filter( 'selective_entity_sync_temp_file_lifetime', $set_lifetime );

		$this->storage->cleanup_expired();

		$this->assertDirectoryDoesNotExist( $dir );
	}

	public function test_cleanup_is_scheduled_daily_and_unscheduled(): void {
		$this->storage->schedule_cleanup();

		$this->assertNotFalse( wp_next_scheduled( TempStorage::CRON_HOOK ) );
		$this->assertSame( 'daily', wp_get_schedule( TempStorage::CRON_HOOK ) );

		TempStorage::unschedule_cleanup();

		$this->assertFalse( wp_next_scheduled( TempStorage::CRON_HOOK ) );
	}
}
