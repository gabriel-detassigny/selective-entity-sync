<?php
/**
 * Uninstall routine.
 *
 * Removes plugin settings and temporary files only. Content that was imported
 * with the plugin is regular WordPress content and is intentionally kept,
 * including the entity UUIDs stored in post and term meta, so that syncing
 * still works if the plugin is reinstalled.
 *
 * @package SelectiveEntitySync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

wp_clear_scheduled_hook( 'selective_entity_sync_cleanup_temp' );

require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
$selective_entity_sync_filesystem = new WP_Filesystem_Direct( null );

// Working directories and packages uploaded for import but never imported.
$selective_entity_sync_directories = array(
	trailingslashit( get_temp_dir() ) . 'selective-entity-sync',
	trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'selective-entity-sync-packages',
);
foreach ( $selective_entity_sync_directories as $selective_entity_sync_directory ) {
	if ( is_dir( $selective_entity_sync_directory ) ) {
		$selective_entity_sync_filesystem->delete( $selective_entity_sync_directory, true );
	}
}

// Ownership records of uploaded packages. With a persistent object cache they live in the cache
// instead, and expire on their own within the package lifetime (a day by default).
global $wpdb;
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off cleanup on uninstall; transients have no API to delete by prefix.
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( '_transient_selective_entity_sync_pkg_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_selective_entity_sync_pkg_' ) . '%'
	)
);
