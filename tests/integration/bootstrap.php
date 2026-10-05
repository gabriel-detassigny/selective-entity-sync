<?php
/**
 * Integration test bootstrap. Runs inside wp-env: `npm run test:php`.
 *
 * @package SelectiveEntitySync
 */

$selective_entity_sync_root = dirname( __DIR__, 2 );

require_once $selective_entity_sync_root . '/vendor/yoast/phpunit-polyfills/phpunitpolyfills-autoload.php';

$selective_entity_sync_tests_dir = getenv( 'WP_TESTS_DIR' );
if ( ! $selective_entity_sync_tests_dir ) {
	$selective_entity_sync_tests_dir = $selective_entity_sync_root . '/vendor/wp-phpunit/wp-phpunit';
}

if ( ! file_exists( $selective_entity_sync_tests_dir . '/includes/functions.php' ) ) {
	fwrite( STDERR, "Could not find the WordPress test suite. Run integration tests with `npm run test:php`.\n" );
	exit( 1 );
}

require_once $selective_entity_sync_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function () use ( $selective_entity_sync_root ) {
		require $selective_entity_sync_root . '/selective-entity-sync.php';
	}
);

require $selective_entity_sync_tests_dir . '/includes/bootstrap.php';

// Fresh environments (wp-env, CI) have no uploads directory yet, but the test suite scans it.
wp_upload_dir( null, true, true );
