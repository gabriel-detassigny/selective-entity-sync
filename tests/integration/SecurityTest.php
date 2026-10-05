<?php
/**
 * Security regression tests.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration;

use SelectiveEntitySync\Export\ExportException;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\ImportPlan;
use SelectiveEntitySync\Import\ImportReport;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use SelectiveEntitySync\Tests\Integration\Import\BuildsImporter;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Support\ObjectPermissions
 * @covers \SelectiveEntitySync\Import\Handlers\PostHandler
 * @covers \SelectiveEntitySync\Export\Exporter
 */
class SecurityTest extends WP_UnitTestCase {

	use BuildsExporter;
	use BuildsImporter;

	/**
	 * @var int
	 */
	private $admin;

	/**
	 * Export temp directories to delete.
	 *
	 * @var string[]
	 */
	private $export_dirs = array();

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		wp_set_current_user( $this->admin );
	}

	public function tear_down(): void {
		$this->delete_temp_dirs();
		$storage = new TempStorage();
		foreach ( $this->export_dirs as $directory ) {
			$storage->delete( $directory );
		}
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
	 * Lets authors use the plugin, as a site might do.
	 */
	private function login_as_author_with_access(): int {
		add_filter(
			'selective_entity_sync_capability',
			static function () {
				return 'edit_posts';
			}
		);
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		wp_set_current_user( $author );

		return $author;
	}

	public function test_import_refuses_non_content_post_types(): void {
		$post = self::factory()->post->create();
		add_filter(
			'selective_entity_sync_manifest_data',
			static function ( array $data ) {
				foreach ( $data['entities'] as &$entity ) {
					if ( 'post' === $entity['type'] ) {
						$entity['data']['post_type'] = 'customize_changeset';
					}
				}
				return $data;
			}
		);
		$zip  = $this->export( array( $post ) );
		$uuid = ( new EntityUuid() )->find( 'post', $post );

		$item = $this->build_importer()->plan( $this->read_package( $zip )->get_manifest() )->get_item( $uuid );

		$this->assertSame( ImportPlan::SKIP, $item['action'] );
		$this->assertStringContainsString( 'cannot be imported', (string) $item['reason'] );
	}

	public function test_non_exportable_statuses_are_imported_as_drafts(): void {
		$post = self::factory()->post->create();
		add_filter(
			'selective_entity_sync_manifest_data',
			static function ( array $data ) {
				foreach ( $data['entities'] as &$entity ) {
					if ( 'post' === $entity['type'] ) {
						$entity['data']['post_status'] = 'inherit';
					}
				}
				return $data;
			}
		);
		$zip = $this->export( array( $post ) );

		$this->build_importer()->import( $this->read_package( $zip ) );

		$this->assertSame( 'draft', get_post_status( $post ) );
	}

	public function test_lowered_capability_cannot_export_content_the_user_cannot_read(): void {
		$private = self::factory()->post->create(
			array(
				'post_status' => 'private',
				'post_author' => $this->admin,
			)
		);
		$this->login_as_author_with_access();

		$this->expectException( ExportException::class );
		$this->build_exporter()->plan( array( $private ) );
	}

	public function test_unreadable_dependencies_are_only_referenced(): void {
		$author = $this->login_as_author_with_access();
		wp_set_current_user( $this->admin );
		$private_parent = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'private',
			)
		);
		$child          = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $private_parent,
				'post_author' => $author,
			)
		);
		wp_set_current_user( $author );

		$manifest = $this->build_exporter()->plan( array( $child ) )->get_manifest();
		$parent   = $manifest->get_entity( (string) ( new EntityUuid() )->find( 'post', $private_parent ) );

		$this->assertTrue( $parent['reference_only'] );
		$this->assertArrayNotHasKey( 'post_content', $parent['data'] );
	}

	public function test_lowered_capability_import_respects_per_item_rights(): void {
		$author_id = self::factory()->user->create(
			array(
				'role'       => 'author',
				'user_login' => 'importing-author',
			)
		);
		$other     = self::factory()->user->create( array( 'role' => 'author' ) );
		$page      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$own       = self::factory()->post->create(
			array(
				'post_author' => $author_id,
				'post_status' => 'draft',
				'post_title'  => 'Own draft',
			)
		);
		$others    = self::factory()->post->create(
			array(
				'post_author' => $other,
				'post_status' => 'draft',
			)
		);
		$zip       = $this->export( array( $page, $own, $others ) );
		$uuids     = new EntityUuid();
		$page_uuid = $uuids->find( 'post', $page );
		$own_uuid  = $uuids->find( 'post', $own );
		$oth_uuid  = $uuids->find( 'post', $others );
		foreach ( array( $page, $own, $others ) as $id ) {
			wp_delete_post( $id, true );
		}

		add_filter(
			'selective_entity_sync_capability',
			static function () {
				return 'edit_posts';
			}
		);
		wp_set_current_user( $author_id );

		$report = $this->build_importer()->import( $this->read_package( $zip ) );

		$this->assertSame( ImportReport::FAILED, $report->get_item( $page_uuid )['result'], 'Authors cannot create pages.' );
		$this->assertStringContainsString( 'not allowed', (string) $report->get_item( $page_uuid )['message'] );
		$this->assertSame( ImportReport::FAILED, $report->get_item( $oth_uuid )['result'], 'Authors cannot attribute content to others.' );
		$this->assertSame( ImportReport::CREATED, $report->get_item( $own_uuid )['result'] );
	}

	public function test_temp_directories_are_private(): void {
		$storage   = new TempStorage();
		$directory = $storage->create_directory();

		$this->assertSame( '0700', substr( sprintf( '%o', fileperms( $directory ) ), -4 ) );

		$storage->delete( $directory );
	}

	public function test_uninstall_removes_temporary_files_and_package_records(): void {
		$storage  = new TempStorage();
		$temp_dir = $storage->create_directory();
		$packages = trailingslashit( wp_upload_dir()['basedir'] ) . 'selective-entity-sync-packages';
		wp_mkdir_p( $packages );
		file_put_contents( $packages . '/abc.zip', 'x' );
		set_transient( 'selective_entity_sync_pkg_abc', array( 'user_id' => 1 ), HOUR_IN_SECONDS );
		wp_schedule_event( time(), 'daily', TempStorage::CRON_HOOK );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'selective-entity-sync/selective-entity-sync.php' );
		}
		include dirname( __DIR__, 2 ) . '/uninstall.php';

		$this->assertDirectoryDoesNotExist( $temp_dir );
		$this->assertDirectoryDoesNotExist( $packages );
		global $wpdb;
		$this->assertSame( '0', $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '%selective_entity_sync_pkg_%'" ) );
		$this->assertFalse( wp_next_scheduled( TempStorage::CRON_HOOK ) );
	}
}
