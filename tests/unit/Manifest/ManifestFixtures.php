<?php
/**
 * Manifest test fixtures.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Unit\Manifest;

/**
 * Builds valid manifest data for tests.
 */
trait ManifestFixtures {

	/**
	 * @param array<string, mixed> $overrides Top-level keys to replace.
	 * @return array<string, mixed>
	 */
	protected function valid_manifest_data( array $overrides = array() ): array {
		return array_merge(
			array(
				'schema_version' => 1,
				'generator'      => array(
					'plugin'  => 'selective-entity-sync',
					'version' => '0.1.0',
				),
				'source'         => array(
					'site_url'    => 'https://staging.example.com',
					'wp_version'  => '6.9',
					'exported_at' => '2026-10-05T12:00:00+00:00',
				),
				'entities'       => array(
					array(
						'uuid'      => '3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b',
						'type'      => 'post',
						'source_id' => 1,
						'data'      => array( 'post_title' => 'Hello' ),
					),
					array(
						'uuid'      => '9a8b7c6d-5e4f-4a3b-9c2d-1e0f9a8b7c6d',
						'type'      => 'term',
						'source_id' => 1,
						'data'      => array( 'name' => 'News' ),
					),
				),
				'files'          => array(
					array(
						'path'   => 'media/3f2b8c1e-4a5d-4e6f-8a9b-0c1d2e3f4a5b/photo.jpg',
						'sha256' => str_repeat( 'a', 64 ),
						'size'   => 1024,
					),
				),
			),
			$overrides
		);
	}
}
