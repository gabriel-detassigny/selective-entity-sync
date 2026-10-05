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

$selective_entity_sync_temp_dir = trailingslashit( get_temp_dir() ) . 'selective-entity-sync';
if ( is_dir( $selective_entity_sync_temp_dir ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
	require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
	( new WP_Filesystem_Direct( null ) )->delete( $selective_entity_sync_temp_dir, true );
}
