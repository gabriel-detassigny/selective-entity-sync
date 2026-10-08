<?php
/**
 * Tests for Importer, the planner and the default handlers.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Import;

use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\Handlers\ImportHandler;
use SelectiveEntitySync\Import\EntityMatch;
use SelectiveEntitySync\Import\ImportContext;
use SelectiveEntitySync\Import\ImportException;
use SelectiveEntitySync\Import\ImportPlan;
use SelectiveEntitySync\Import\ImportReport;
use SelectiveEntitySync\Tests\Integration\Export\BuildsExporter;
use SelectiveEntitySync\Storage\TempStorage;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Import\Importer
 * @covers \SelectiveEntitySync\Import\ImportPlanner
 * @covers \SelectiveEntitySync\Import\ImportContext
 * @covers \SelectiveEntitySync\Import\ImportReport
 * @covers \SelectiveEntitySync\Import\MetaImporter
 * @covers \SelectiveEntitySync\Import\AuthorResolver
 * @covers \SelectiveEntitySync\Import\Handlers\PostHandler
 * @covers \SelectiveEntitySync\Import\Handlers\AttachmentHandler
 * @covers \SelectiveEntitySync\Import\Handlers\TermHandler
 */
class ImporterTest extends WP_UnitTestCase {

	use BuildsExporter;
	use BuildsImporter;

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
	 * Exports posts and returns the package zip path.
	 *
	 * @param int[] $post_ids Post IDs.
	 */
	private function export( array $post_ids ): string {
		$result              = $this->build_exporter()->export( $post_ids );
		$this->export_dirs[] = $result->get_directory();

		return $result->get_package_path();
	}

	public function test_round_trip_creates_everything_and_remaps_every_reference(): void {
		// Source content.
		$author     = self::factory()->user->create( array( 'user_login' => 'jane' ) );
		$featured   = $this->create_image_attachment();
		$inline     = $this->create_image_attachment( 'test-image.jpg' );
		$parent_cat = self::factory()->category->create( array( 'slug' => 'news' ) );
		$child_cat  = self::factory()->category->create(
			array(
				'slug'   => 'local',
				'parent' => $parent_cat,
			)
		);
		$pattern    = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Promo',
				'post_content' => '<!-- wp:paragraph --><p>Promo</p><!-- /wp:paragraph -->',
			)
		);
		$page       = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'about',
			)
		);
		$inline_url = wp_get_attachment_image_src( $inline, 'thumbnail' )[0];
		$post       = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_title'   => 'Hello world',
				'post_parent'  => 0,
				'post_content' => '<!-- wp:image {"id":' . $inline . '} --><figure><img src="' . $inline_url . '" class="wp-image-' . $inline . '"/></figure><!-- /wp:image -->'
					. '<!-- wp:block {"ref":' . $pattern . '} /-->'
					. '<p>[gallery ids="' . $featured . ',' . $inline . '"]</p>'
					. '<p><a href="' . home_url( '/about/' ) . '">About</a></p>',
			)
		);
		wp_set_post_categories( $post, array( $child_cat ) );
		set_post_thumbnail( $post, $featured );
		update_post_meta( $post, 'related_page', (string) $page );
		update_post_meta( $post, 'subtitle', wp_slash( 'A "quoted" subtitle \\ with backslash' ) );
		add_filter(
			'selective_entity_sync_id_reference_meta_keys',
			static function ( array $keys ) {
				$keys['related_page'] = 'post';
				return $keys;
			}
		);

		$zip = $this->export( array( $post, $page ) );

		$uuid = array(
			'post'       => $this->uuids->find( 'post', $post ),
			'page'       => $this->uuids->find( 'post', $page ),
			'pattern'    => $this->uuids->find( 'post', $pattern ),
			'featured'   => $this->uuids->find( 'post', $featured ),
			'inline'     => $this->uuids->find( 'post', $inline ),
			'parent_cat' => $this->uuids->find( 'term', $parent_cat ),
			'child_cat'  => $this->uuids->find( 'term', $child_cat ),
		);

		// Simulate a target site: the content doesn't exist, and IDs differ.
		foreach ( array( $post, $page, $pattern, $featured, $inline ) as $id ) {
			wp_delete_post( $id, true );
		}
		wp_delete_term( $child_cat, 'category' );
		wp_delete_term( $parent_cat, 'category' );
		$unrelated = self::factory()->post->create_many( 3, array( 'post_title' => 'Live content' ) );
		self::factory()->category->create_many( 2 );

		$report = $this->build_importer()->import( $this->read_package( $zip ) );

		foreach ( $unrelated as $unrelated_id ) {
			$this->assertSame( 'Live content', get_post( $unrelated_id )->post_title, 'Unrelated content is untouched.' );
			$this->assertNull( $this->uuids->find( 'post', $unrelated_id ) );
		}

		$this->assertFalse( $report->has_failures(), (string) wp_json_encode( $report->to_array() ) );
		$this->assertSame( 7, $report->get_counts()[ ImportReport::CREATED ] );

		$new = array();
		foreach ( $uuid as $key => $value ) {
			$new[ $key ] = $this->uuids->find_object_id( in_array( $key, array( 'parent_cat', 'child_cat' ), true ) ? 'term' : 'post', $value );
			$this->assertNotNull( $new[ $key ], "{$key} was not imported." );
		}

		$imported = get_post( $new['post'] );
		$this->assertSame( 'Hello world', $imported->post_title );
		$this->assertSame( $author, (int) $imported->post_author, 'Author mapped by login.' );
		$this->assertSame( array( $new['child_cat'] ), wp_get_post_categories( $new['post'] ) );
		$this->assertSame( $new['parent_cat'], get_term( $new['child_cat'] )->parent );
		$this->assertSame( $new['featured'], (int) get_post_thumbnail_id( $new['post'] ) );
		$this->assertSame( (string) $new['page'], get_post_meta( $new['post'], 'related_page', true ) );
		$this->assertSame( 'A "quoted" subtitle \\ with backslash', get_post_meta( $new['post'], 'subtitle', true ), 'Meta survives slashing.' );

		$content = $imported->post_content;
		$this->assertStringContainsString( '{"id":' . $new['inline'] . '}', $content );
		$this->assertStringContainsString( 'wp-image-' . $new['inline'], $content );
		$this->assertStringContainsString( '{"ref":' . $new['pattern'] . '}', $content );
		$this->assertStringContainsString( '[gallery ids="' . $new['featured'] . ',' . $new['inline'] . '"]', $content );
		$this->assertStringContainsString( wp_get_attachment_image_src( $new['inline'], 'thumbnail' )[0], $content, 'Media URLs point to the new files.' );

		$this->assertSame( 'about', get_post( $new['page'] )->post_name );
		$this->assertFileExists( get_attached_file( $new['featured'] ) );
		$this->assertNotEmpty( wp_get_attachment_metadata( $new['featured'] )['sizes'], 'Image sizes are generated.' );
	}

	public function test_reimport_updates_in_place_merges_meta_and_keeps_unchanged_files(): void {
		$attachment = $this->create_image_attachment();
		$post       = self::factory()->post->create( array( 'post_title' => 'Original' ) );
		set_post_thumbnail( $post, $attachment );
		update_post_meta( $post, 'source_meta', 'v1' );
		$zip = $this->export( array( $post ) );

		// Edit the "target" copy.
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Edited on target',
			)
		);
		update_post_meta( $post, 'source_meta', 'changed' );
		update_post_meta( $post, 'target_only_meta', 'keep me' );
		$file_before = get_attached_file( $attachment );

		$report = $this->build_importer()->import( $this->read_package( $zip ) );

		$this->assertSame( 0, $report->get_counts()[ ImportReport::CREATED ] );
		$this->assertSame( ImportReport::UPDATED, $report->get_item( $this->uuids->find( 'post', $post ) )['result'] );
		$this->assertSame( 'Original', get_post( $post )->post_title );
		$this->assertSame( 'v1', get_post_meta( $post, 'source_meta', true ) );
		$this->assertSame( 'keep me', get_post_meta( $post, 'target_only_meta', true ), 'Target-only meta is kept.' );
		$this->assertSame( $file_before, get_attached_file( $attachment ), 'Identical files are not replaced.' );
	}

	public function test_changed_media_files_are_replaced(): void {
		$attachment = $this->create_image_attachment();
		$post       = self::factory()->post->create();
		set_post_thumbnail( $post, $attachment );
		$zip = $this->export( array( $post ) );

		// The target's file differs from the package's.
		$old_file = get_attached_file( $attachment );
		copy( DIR_TESTDATA . '/images/test-image.jpg', $old_file );

		$this->build_importer()->import( $this->read_package( $zip ) );

		$new_file = get_attached_file( $attachment );
		$this->assertNotSame( $old_file, $new_file );
		$this->assertFileDoesNotExist( $old_file, 'The old file is deleted.' );
		$this->assertSame( hash_file( 'sha256', DIR_TESTDATA . '/images/canola.jpg' ), hash_file( 'sha256', $new_file ) );
	}

	public function test_slug_matches_are_linked_unless_owned_by_another_uuid(): void {
		$page = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_name'  => 'contact',
				'post_title' => 'Contact (source)',
			)
		);
		$zip  = $this->export( array( $page ) );
		$uuid = $this->uuids->find( 'post', $page );
		wp_delete_post( $page, true );

		// A separately created page with the same slug and no UUID: linked.
		$target = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'contact',
			)
		);

		$plan = $this->build_importer()->plan( $this->read_package( $zip )->get_manifest() );
		$item = $plan->get_item( $uuid );
		$this->assertSame( ImportPlan::UPDATE, $item['action'] );
		$this->assertSame( EntityMatch::BY_SLUG, $item['match'] );
		$this->assertSame( $target, $item['local_id'] );

		$this->build_importer()->import( $this->read_package( $zip ) );
		$this->assertSame( 'Contact (source)', get_post( $target )->post_title );
		$this->assertSame( $uuid, $this->uuids->find( 'post', $target ), 'The UUID is stored for future syncs.' );

		// Same slug, but synced from elsewhere (different UUID): not hijacked.
		$this->uuids->assign( 'post', $target, wp_generate_uuid4() );
		$item = $this->build_importer()->plan( $this->read_package( $zip )->get_manifest() )->get_item( $uuid );
		$this->assertSame( ImportPlan::CREATE, $item['action'] );
	}

	public function test_deselected_and_filtered_entities_are_skipped(): void {
		$parent      = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$child       = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $parent,
				'post_title'  => 'Child',
			)
		);
		$zip         = $this->export( array( $child ) );
		$child_uuid  = $this->uuids->find( 'post', $child );
		$parent_uuid = $this->uuids->find( 'post', $parent );
		wp_update_post(
			array(
				'ID'         => $child,
				'post_title' => 'Child edited',
			)
		);

		$report = $this->build_importer()->import( $this->read_package( $zip ), array( strtoupper( $child_uuid ) ) );

		$this->assertSame( ImportReport::SKIPPED, $report->get_item( $child_uuid )['result'] );
		$this->assertSame( 'Child edited', get_post( $child )->post_title );
		$this->assertSame( ImportReport::UPDATED, $report->get_item( $parent_uuid )['result'] );

		add_filter(
			'selective_entity_sync_import_action',
			static function ( string $action, array $entity ) use ( $parent_uuid ) {
				return $parent_uuid === $entity['uuid'] ? 'skip' : $action;
			},
			10,
			2
		);
		$plan = $this->build_importer()->plan( $this->read_package( $zip )->get_manifest() );
		$this->assertSame( ImportPlan::SKIP, $plan->get_item( $parent_uuid )['action'] );
	}

	public function test_reference_only_entities_are_linked_or_reported_missing(): void {
		$category = self::factory()->category->create( array( 'slug' => 'shared' ) );
		$tag      = self::factory()->tag->create( array( 'slug' => 'gone' ) );
		$post     = self::factory()->post->create();
		wp_set_post_categories( $post, array( $category ) );
		wp_set_post_tags( $post, array( $tag ) );
		add_filter(
			'selective_entity_sync_include_dependency',
			static function ( bool $bundle, $dependency ) {
				return 'term' === $dependency->get_object_type() ? false : $bundle;
			},
			10,
			2
		);
		$zip       = $this->export( array( $post ) );
		$tag_uuid  = $this->uuids->find( 'term', $tag );
		$post_uuid = (string) $this->uuids->find( 'post', $post );
		wp_delete_post( $post, true );
		wp_delete_term( $tag, 'post_tag' );

		$report = $this->build_importer()->import( $this->read_package( $zip ) );
		$new_id = $this->uuids->find_object_id( 'post', $post_uuid );

		$this->assertSame( ImportReport::CREATED, $report->get_item( $post_uuid )['result'] );

		$this->assertSame( ImportReport::LINKED, $report->get_item( $this->uuids->find( 'term', $category ) )['result'] );
		$this->assertSame( ImportReport::MISSING, $report->get_item( $tag_uuid )['result'] );
		$this->assertSame( array( $category ), wp_get_post_categories( (int) $new_id ) );
		$this->assertSame( array(), wp_get_post_tags( (int) $new_id ) );
	}

	public function test_unsupported_entities_are_skipped_and_failures_do_not_stop_the_import(): void {
		register_post_type( 'temp_type', array( 'public' => true ) );
		$custom = self::factory()->post->create( array( 'post_type' => 'temp_type' ) );
		$post   = self::factory()->post->create( array( 'post_title' => 'Survivor' ) );
		$page   = self::factory()->post->create( array( 'post_type' => 'page' ) );
		add_filter(
			'selective_entity_sync_exportable_post_types',
			static function ( array $types ) {
				$types[] = 'temp_type';
				return $types;
			}
		);
		$zip = $this->export( array( $custom, $post, $page ) );
		unregister_post_type( 'temp_type' );

		$failing = new class() implements ImportHandler {
			public function supports( array $entity ): bool {
				return 'post' === $entity['type'] && 'page' === $entity['data']['post_type'];
			}

			public function get_object_type(): string {
				return 'post';
			}

			public function get_validation_error( array $entity ): ?string {
				return null;
			}

			public function find_by_natural_key( array $entity, ImportContext $context ): ?EntityMatch {
				return null;
			}

			public function get_dependencies( array $entity, ImportContext $context ): array {
				return array();
			}

			public function import( array $entity, ?int $local_id, ImportContext $context ): int {
				// Plugin exceptions are HTML-escaped when thrown.
				throw new ImportException( esc_html( 'Boom: "Tom & Jerry\'s"' ) );
			}
		};
		add_filter(
			'selective_entity_sync_import_handlers',
			static function ( array $handlers ) use ( $failing ) {
				array_unshift( $handlers, $failing );
				return $handlers;
			}
		);
		$failures = array();
		add_action(
			'selective_entity_sync_import_failed',
			static function ( array $entity, string $message ) use ( &$failures ) {
				$failures[] = $message;
			},
			10,
			2
		);

		$report = $this->build_importer()->import( $this->read_package( $zip ) );

		$custom_item = $report->get_item( $this->uuids->find( 'post', $custom ) );
		$this->assertSame( ImportReport::SKIPPED, $custom_item['result'] );
		$this->assertStringContainsString( 'temp_type', (string) $custom_item['message'] );
		$failed_item = $report->get_item( $this->uuids->find( 'post', $page ) );
		$this->assertSame( ImportReport::FAILED, $failed_item['result'] );
		$this->assertSame( 'Boom: "Tom & Jerry\'s"', $failed_item['message'], 'The report holds plain text.' );
		$this->assertSame( array( 'Boom: "Tom & Jerry\'s"' ), $failures, 'Hooks receive plain text.' );
		$this->assertSame( ImportReport::UPDATED, $report->get_item( $this->uuids->find( 'post', $post ) )['result'] );
		$this->assertTrue( $report->has_failures() );
	}

	public function test_source_site_url_is_replaced_unless_disabled(): void {
		$post = self::factory()->post->create( array( 'post_content' => '<a href="https://staging.example.com/about/">About</a>' ) );
		add_filter(
			'selective_entity_sync_manifest_data',
			static function ( array $data ) {
				$data['source']['site_url'] = 'https://staging.example.com';
				return $data;
			}
		);
		$zip = $this->export( array( $post ) );

		$this->build_importer()->import( $this->read_package( $zip ) );
		$this->assertStringContainsString( 'href="' . home_url( '/about/' ) . '"', get_post( $post )->post_content );

		wp_update_post(
			array(
				'ID'           => $post,
				'post_content' => 'x',
			)
		);
		add_filter( 'selective_entity_sync_replace_site_url', '__return_false' );
		$this->build_importer()->import( $this->read_package( $zip ) );
		$this->assertStringContainsString( 'https://staging.example.com/about/', get_post( $post )->post_content );
	}

	public function test_lifecycle_hooks_fire(): void {
		$post   = self::factory()->post->create();
		$zip    = $this->export( array( $post ) );
		$events = array();
		add_action(
			'selective_entity_sync_before_import',
			static function () use ( &$events ) {
				$events[] = 'before';
			}
		);
		add_action(
			'selective_entity_sync_entity_imported',
			static function ( array $entity, int $local_id, string $result ) use ( &$events ) {
				$events[] = $entity['type'] . ':' . $result;
			},
			10,
			3
		);
		add_action(
			'selective_entity_sync_after_import',
			static function ( ImportReport $report ) use ( &$events ) {
				$events[] = 'after:' . count( $report->get_items() );
			}
		);

		$this->build_importer()->import( $this->read_package( $zip ) );

		$this->assertSame( array( 'before', 'term:updated', 'post:updated', 'after:2' ), $events );
	}
}
