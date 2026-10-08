<?php
/**
 * Development helper (mapped into the test and target wp-env sites; never shipped):
 * forces small import batches so batching can be exercised with a few items.
 *
 * - Manual QA: set `SELECTIVE_ENTITY_SYNC_DEV_BATCH_SIZE` in `.wp-env.target.override.json`, e.g.
 *   { "config": { "SELECTIVE_ENTITY_SYNC_DEV_BATCH_SIZE": 2 } }
 * - E2E tests: send an `X-SES-Batch-Size` request header.
 *
 * @package SelectiveEntitySync
 */

add_filter(
	'selective_entity_sync_import_batch_limits',
	static function ( $limits ) {
		$size = isset( $_SERVER['HTTP_X_SES_BATCH_SIZE'] ) ? absint( wp_unslash( $_SERVER['HTTP_X_SES_BATCH_SIZE'] ) ) : 0;
		if ( 0 === $size && defined( 'SELECTIVE_ENTITY_SYNC_DEV_BATCH_SIZE' ) ) {
			$size = absint( SELECTIVE_ENTITY_SYNC_DEV_BATCH_SIZE );
		}
		if ( $size > 0 ) {
			$limits['max_entities'] = $size;
		}
		return $limits;
	}
);
