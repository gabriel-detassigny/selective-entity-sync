<?php
/**
 * Uninstall routine.
 *
 * Removes plugin settings and temporary files only. Content that was imported
 * with the plugin is regular WordPress content and is intentionally kept.
 *
 * @package SelectiveEntitySync
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Plugin options and temporary export storage will be cleaned up here once they exist.
