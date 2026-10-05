<?php
/**
 * Tests for ExportSettings.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Export;

use SelectiveEntitySync\Export\ExportSettings;
use WP_UnitTestCase;

/**
 * @covers \SelectiveEntitySync\Export\ExportSettings
 */
class ExportSettingsTest extends WP_UnitTestCase {

	public function test_default_exportable_post_types(): void {
		$types = ( new ExportSettings() )->get_exportable_post_types();

		$this->assertContains( 'post', $types );
		$this->assertContains( 'page', $types );
		$this->assertContains( 'wp_block', $types );
		$this->assertNotContains( 'attachment', $types );
		$this->assertNotContains( 'wp_template', $types );
		$this->assertNotContains( 'wp_navigation', $types );
		$this->assertNotContains( 'revision', $types );
	}

	public function test_trash_and_auto_drafts_can_never_be_exported(): void {
		add_filter(
			'selective_entity_sync_exportable_statuses',
			static function ( array $statuses ) {
				return array_merge( $statuses, array( 'trash', 'auto-draft' ) );
			}
		);

		$statuses = ( new ExportSettings() )->get_exportable_statuses();

		$this->assertSame( array( 'publish', 'future', 'draft', 'pending', 'private' ), $statuses );
	}

	public function test_id_reference_meta_keys_filter_ignores_invalid_entries(): void {
		add_filter(
			'selective_entity_sync_id_reference_meta_keys',
			static function ( array $keys ) {
				$keys['related_post'] = 'post';
				$keys['bad_type']     = 'user';
				$keys[0]              = 'post';
				return $keys;
			}
		);

		$this->assertSame(
			array(
				'_thumbnail_id' => 'post',
				'related_post'  => 'post',
			),
			( new ExportSettings() )->get_id_reference_meta_keys( 'post' )
		);
	}

	public function test_invalid_filter_results_fall_back_to_defaults(): void {
		add_filter( 'selective_entity_sync_exportable_post_types', '__return_false' );
		add_filter( 'selective_entity_sync_excluded_meta_keys', '__return_null' );

		$settings = new ExportSettings();

		$this->assertContains( 'post', $settings->get_exportable_post_types() );
		$this->assertContains( '_edit_lock', $settings->get_excluded_meta_keys( 'post' ) );
	}
}
