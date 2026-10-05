<?php
/**
 * Tests for Exporter and the default collectors.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Export;

use SelectiveEntitySync\Export\Collectors\EntityCollector;
use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;
use SelectiveEntitySync\Export\ExportException;
use SelectiveEntitySync\Export\ExportResult;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Manifest\ManifestCodec;
use SelectiveEntitySync\Manifest\Schema;
use SelectiveEntitySync\Package\PackageLimits;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Storage\TempStorage;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Export\Exporter
 * @covers \SelectiveEntitySync\Export\ExportContext
 * @covers \SelectiveEntitySync\Export\ExportPlan
 * @covers \SelectiveEntitySync\Export\Collectors\PostCollector
 * @covers \SelectiveEntitySync\Export\Collectors\AttachmentCollector
 * @covers \SelectiveEntitySync\Export\Collectors\TermCollector
 * @covers \SelectiveEntitySync\Export\Collectors\MetaCollector
 */
class ExporterTest extends WP_UnitTestCase {

	use BuildsExporter;

	/**
	 * @var EntityUuid
	 */
	private $uuids;

	public function set_up(): void {
		parent::set_up();
		$this->uuids = new EntityUuid();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	public function tear_down(): void {
		$this->remove_added_uploads();
		parent::tear_down();
	}

	public function test_exports_selection_with_all_dependencies(): void {
		$author      = self::factory()->user->create(
			array(
				'user_login' => 'jane',
				'user_email' => 'jane@example.com',
				'role'       => 'editor',
			)
		);
		$featured    = $this->create_image_attachment();
		$inline      = $this->create_image_attachment( 'test-image.jpg' );
		$in_pattern  = $this->create_image_attachment( 'canola.jpg' );
		$parent_cat  = self::factory()->category->create( array( 'name' => 'News' ) );
		$child_cat   = self::factory()->category->create(
			array(
				'name'   => 'Local',
				'parent' => $parent_cat,
			)
		);
		$pattern     = self::factory()->post->create(
			array(
				'post_type'    => 'wp_block',
				'post_title'   => 'Promo',
				'post_content' => '<!-- wp:image {"id":' . $in_pattern . '} --><figure><img class="wp-image-' . $in_pattern . '"/></figure><!-- /wp:image -->',
			)
		);
		$grandparent = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$parent      = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_parent' => $grandparent,
			)
		);
		$post        = self::factory()->post->create(
			array(
				'post_author'  => $author,
				'post_title'   => 'Hello world',
				'post_status'  => 'draft',
				'post_content' => '<!-- wp:image {"id":' . $inline . '} --><figure><img class="wp-image-' . $inline . '"/></figure><!-- /wp:image --><!-- wp:block {"ref":' . $pattern . '} /-->',
			)
		);
		wp_set_post_categories( $post, array( $child_cat ) );
		wp_set_post_tags( $post, array( 'breaking' ) );
		set_post_thumbnail( $post, $featured );

		$plan     = $this->build_exporter()->plan( array( $post, $parent ) );
		$manifest = $plan->get_manifest();

		$by_source = array();
		foreach ( $manifest->get_entities() as $entity ) {
			$by_source[ $entity['type'] . ':' . $entity['source_id'] ] = $entity;
		}

		// Selected posts, their dependencies, and dependencies of dependencies.
		$expected = array(
			'post:' . $post,
			'post:' . $parent,
			'post:' . $grandparent,
			'post:' . $pattern,
			'attachment:' . $featured,
			'attachment:' . $inline,
			'attachment:' . $in_pattern,
			'term:' . $child_cat,
			'term:' . $parent_cat,
		);
		foreach ( $expected as $key ) {
			$this->assertArrayHasKey( $key, $by_source, "Missing {$key}." );
			$this->assertArrayNotHasKey( 'reference_only', $by_source[ $key ], "{$key} should be bundled." );
		}
		$this->assertCount( 10, $by_source, 'Selection + 7 dependencies + the "breaking" tag.' );

		$entity = $by_source[ 'post:' . $post ];
		$this->assertSame( 'Hello world', $entity['data']['post_title'] );
		$this->assertSame( 'draft', $entity['data']['post_status'], 'Drafts can be exported and keep their status.' );
		$this->assertSame(
			array(
				'login'        => 'jane',
				'email'        => 'jane@example.com',
				'display_name' => get_userdata( $author )->display_name,
			),
			$entity['relations']['author']
		);
		$this->assertSame( array( $this->uuids->find( 'term', $child_cat ) ), $entity['relations']['terms']['category'] );
		$this->assertCount( 1, $entity['relations']['terms']['post_tag'] );
		$this->assertSame( array( (string) $featured ), $entity['meta']['_thumbnail_id'], 'IDs in meta are kept and remapped on import.' );

		$this->assertSame( $this->uuids->find( 'post', $grandparent ), $by_source[ 'post:' . $parent ]['relations']['parent'] );
		$this->assertSame( $this->uuids->find( 'term', $parent_cat ), $by_source[ 'term:' . $child_cat ]['relations']['parent'] );

		$this->assertTrue( $plan->is_selected( $entity['uuid'] ) );
		$this->assertTrue( $plan->is_selected( $by_source[ 'post:' . $parent ]['uuid'] ) );
		$this->assertFalse( $plan->is_selected( $by_source[ 'post:' . $grandparent ]['uuid'] ) );

		$this->assertCount( 3, $plan->get_files() );
		$this->assertSame( array(), $plan->get_warnings() );
	}

	public function test_attachment_entities_reference_their_file(): void {
		$attachment = $this->create_image_attachment();
		update_post_meta( $attachment, '_wp_attachment_image_alt', 'A canola field' );
		$post = self::factory()->post->create();
		set_post_thumbnail( $post, $attachment );

		$plan   = $this->build_exporter()->plan( array( $post ) );
		$entity = $plan->get_manifest()->get_entity( $this->uuids->find( 'post', $attachment ) );

		$this->assertSame( 'attachment', $entity['type'] );
		$this->assertSame( 'image/jpeg', $entity['data']['post_mime_type'] );
		$this->assertSame( 'media/' . $entity['uuid'] . '/canola.jpg', $entity['file']['path'] );
		$this->assertSame( 'canola.jpg', $entity['file']['original_name'] );
		$this->assertSame( wp_get_attachment_url( $attachment ), $entity['file']['urls']['full'] );
		$this->assertSame( wp_get_attachment_image_src( $attachment, 'thumbnail' )[0], $entity['file']['urls']['sizes']['thumbnail'] );
		$this->assertSame( array( 'A canola field' ), $entity['meta']['_wp_attachment_image_alt'] );
		$this->assertArrayNotHasKey( '_wp_attached_file', $entity['meta'] );
		$this->assertArrayNotHasKey( '_wp_attachment_metadata', $entity['meta'] );
		$this->assertSame( get_attached_file( $attachment ), $plan->get_files()[ $entity['file']['path'] ] );
	}

	public function test_dependencies_can_be_downgraded_to_reference_only(): void {
		$parent_cat = self::factory()->category->create();
		$child_cat  = self::factory()->category->create( array( 'parent' => $parent_cat ) );
		$post       = self::factory()->post->create();
		wp_set_post_categories( $post, array( $child_cat ) );

		add_filter(
			'selective_entity_sync_include_dependency',
			static function ( bool $bundle, EntityReference $dependency ) {
				return EntityReference::TERM === $dependency->get_object_type() ? false : $bundle;
			},
			10,
			2
		);

		$manifest = $this->build_exporter()->plan( array( $post ) )->get_manifest();
		$child    = $manifest->get_entity( $this->uuids->find( 'term', $child_cat ) );

		$this->assertTrue( $child['reference_only'] );
		$this->assertSame( 'category', $child['data']['taxonomy'] );
		$this->assertArrayNotHasKey( 'relations', $child );
		$this->assertNull( $this->uuids->find( 'term', $parent_cat ), 'Dependencies of reference-only entities are not walked.' );
	}

	public function test_meta_export_rules(): void {
		$related = self::factory()->post->create();
		$post    = self::factory()->post->create();
		update_post_meta( $post, 'subtitle', 'A subtitle' );
		update_post_meta( $post, 'settings', array( 'layout' => 'wide' ) );
		update_post_meta( $post, 'secret_cache', 'x' );
		update_post_meta( $post, 'related_post', (string) $related );
		update_post_meta( $post, '_edit_lock', '123:1' );

		add_filter(
			'selective_entity_sync_excluded_meta_keys',
			static function ( array $keys ) {
				$keys[] = 'secret_cache';
				return $keys;
			}
		);
		add_filter(
			'selective_entity_sync_id_reference_meta_keys',
			static function ( array $keys ) {
				$keys['related_post'] = 'post';
				return $keys;
			}
		);

		$manifest = $this->build_exporter()->plan( array( $post ) )->get_manifest();
		$meta     = $manifest->get_entity( $this->uuids->find( 'post', $post ) )['meta'];

		$this->assertSame( array( 'A subtitle' ), $meta['subtitle'] );
		$this->assertSame( array( array( 'layout' => 'wide' ) ), $meta['settings'], 'Serialized values are exported unserialized.' );
		$this->assertArrayNotHasKey( 'secret_cache', $meta );
		$this->assertArrayNotHasKey( '_edit_lock', $meta );
		$this->assertArrayNotHasKey( EntityUuid::META_KEY, $meta );
		$this->assertTrue( $manifest->has_entity( (string) $this->uuids->find( 'post', $related ) ), 'Posts referenced by ID meta are exported.' );
	}

	/**
	 * @dataProvider invalid_selections
	 *
	 * @param callable $make_ids Returns the selection to export.
	 */
	public function test_rejects_invalid_selections( callable $make_ids ): void {
		$this->expectException( ExportException::class );

		$this->build_exporter()->plan( $make_ids( $this ) );
	}

	/**
	 * @return array<string, array{callable}>
	 */
	public function invalid_selections(): array {
		return array(
			'empty'               => array(
				static function () {
					return array();
				},
			),
			'missing post'        => array(
				static function () {
					return array( PHP_INT_MAX );
				},
			),
			'trashed post'        => array(
				static function () {
					return array( self::factory()->post->create( array( 'post_status' => 'trash' ) ) );
				},
			),
			'attachment directly' => array(
				static function () {
					return array( self::factory()->attachment->create() );
				},
			),
			'revision'            => array(
				static function () {
					$post = self::factory()->post->create();
					return array(
						self::factory()->post->create(
							array(
								'post_type'   => 'revision',
								'post_parent' => $post,
							)
						),
					);
				},
			),
		);
	}

	public function test_post_types_excluded_by_filter_cannot_be_exported(): void {
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		add_filter(
			'selective_entity_sync_exportable_post_types',
			static function () {
				return array( 'post' );
			}
		);

		$this->expectException( ExportException::class );
		$this->build_exporter()->plan( array( $page ) );
	}

	public function test_missing_files_and_missing_references_produce_warnings(): void {
		$attachment = $this->create_image_attachment();
		wp_delete_file( get_attached_file( $attachment ) );
		$post = self::factory()->post->create(
			array( 'post_content' => '<img class="wp-image-' . PHP_INT_MAX . '"/>' )
		);
		set_post_thumbnail( $post, $attachment );

		$plan   = $this->build_exporter()->plan( array( $post ) );
		$entity = $plan->get_manifest()->get_entity( $this->uuids->find( 'post', $attachment ) );

		$this->assertTrue( $entity['reference_only'] );
		$this->assertSame( array(), $plan->get_files() );
		$warnings = implode( "\n", $plan->get_warnings() );
		$this->assertCount( 2, $plan->get_warnings() );
		$this->assertStringContainsString( 'was not found on disk', $warnings );
		$this->assertStringContainsString( 'no longer exists', $warnings );
	}

	public function test_export_writes_a_readable_package_and_fires_hooks(): void {
		$post = self::factory()->post->create();
		set_post_thumbnail( $post, $this->create_image_attachment() );

		$before = array();
		$after  = null;
		add_action(
			'selective_entity_sync_before_export',
			static function ( array $post_ids ) use ( &$before ) {
				$before = $post_ids;
			}
		);
		add_action(
			'selective_entity_sync_after_export',
			static function ( ExportResult $result ) use ( &$after ) {
				$after = $result;
			}
		);

		$result = $this->build_exporter()->export( array( $post ) );

		$this->assertSame( array( $post ), $before );
		$this->assertSame( $result, $after );
		$this->assertFileExists( $result->get_package_path() );

		$storage     = new TempStorage();
		$extract_dir = $storage->create_directory();
		$reader      = new PackageReader( new ManifestCodec( new Schema() ), new PackageLimits() );
		$package     = $reader->read( $result->get_package_path(), $extract_dir );

		$this->assertSame( $result->get_manifest()->to_array(), $package->get_manifest()->to_array() );
		$this->assertCount( 1, $package->get_manifest()->get_files() );
		$this->assertSame( '0.1.0-test', $package->get_manifest()->get_generator()['version'] );
		$this->assertSame( home_url(), $package->get_manifest()->get_source()['site_url'] );

		$storage->delete( $extract_dir );
		$storage->delete( $result->get_directory() );
	}

	public function test_custom_collectors_and_entity_filter(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Original' ) );

		$custom = new class() implements EntityCollector {
			public function supports( EntityReference $reference ): bool {
				return EntityReference::POST === $reference->get_object_type();
			}

			public function collect( EntityReference $reference, ExportContext $context ): array {
				return array(
					'uuid'      => $context->uuid( $reference ),
					'type'      => 'acme_post',
					'source_id' => $reference->get_id(),
					'data'      => array( 'custom' => true ),
				);
			}

			public function collect_reference( EntityReference $reference, ExportContext $context ): array {
				return $this->collect( $reference, $context );
			}
		};

		add_filter(
			'selective_entity_sync_collectors',
			static function ( array $collectors ) use ( $custom ) {
				array_unshift( $collectors, $custom );
				return $collectors;
			}
		);
		add_filter(
			'selective_entity_sync_export_entity_data',
			static function ( array $entity ) {
				$entity['data']['filtered'] = true;
				return $entity;
			}
		);

		$entity = $this->build_exporter()->plan( array( $post ) )->get_manifest()->get_entities()[0];

		$this->assertSame( 'acme_post', $entity['type'] );
		$this->assertSame(
			array(
				'custom'   => true,
				'filtered' => true,
			),
			$entity['data']
		);
	}
}
