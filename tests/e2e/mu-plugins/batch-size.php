<?php
/**
 * Test site only (mapped by .wp-env.test.json): lets e2e tests force small
 * import batches by sending an `X-SES-Batch-Size` request header.
 *
 * @package SelectiveEntitySync
 */

add_filter(
	'selective_entity_sync_import_batch_limits',
	static function ( $limits ) {
		$size = isset( $_SERVER['HTTP_X_SES_BATCH_SIZE'] ) ? absint( wp_unslash( $_SERVER['HTTP_X_SES_BATCH_SIZE'] ) ) : 0;
		if ( $size > 0 ) {
			$limits['max_entities'] = $size;
		}
		return $limits;
	}
);
