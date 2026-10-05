<?php
/**
 * Plugin Name:       Selective Entity Sync
 * Plugin URI:        https://github.com/gabriel-detassigny/selective-entity-sync
 * Description:       Push selected content (posts, pages, custom post types, terms and media) from one WordPress site to another (e.g. staging to production) using entity manifests, with automatic ID remapping, so live data is never overwritten.
 * Version:           0.1.0-dev
 * Requires at least: 6.9
 * Requires PHP:      7.4
 * Author:            Gabriel de Tassigny
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       selective-entity-sync
 * Domain Path:       /languages
 *
 * @package SelectiveEntitySync
 */

defined( 'ABSPATH' ) || exit;

define( 'SELECTIVE_ENTITY_SYNC_VERSION', '0.1.0-dev' );
define( 'SELECTIVE_ENTITY_SYNC_FILE', __FILE__ );
define( 'SELECTIVE_ENTITY_SYNC_MIN_PHP', '7.4' );
define( 'SELECTIVE_ENTITY_SYNC_MIN_WP', '6.9' );

/*
 * Bail out early, with an admin notice, if the environment is not supported.
 * This file must stay parseable by old PHP versions, so keep it simple.
 */
if (
	version_compare( PHP_VERSION, SELECTIVE_ENTITY_SYNC_MIN_PHP, '<' )
	|| version_compare( get_bloginfo( 'version' ), SELECTIVE_ENTITY_SYNC_MIN_WP, '<' )
	|| ! is_readable( __DIR__ . '/vendor/autoload.php' )
) {
	add_action(
		'admin_notices',
		static function () {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}

			if ( ! is_readable( __DIR__ . '/vendor/autoload.php' ) ) {
				$message = __( 'Selective Entity Sync is missing its dependencies. Please run "composer install" or install a release build of the plugin.', 'selective-entity-sync' );
			} else {
				$message = sprintf(
					/* translators: 1: Minimum required PHP version, 2: Minimum required WordPress version. */
					__( 'Selective Entity Sync requires PHP %1$s and WordPress %2$s or higher. The plugin is not running.', 'selective-entity-sync' ),
					SELECTIVE_ENTITY_SYNC_MIN_PHP,
					SELECTIVE_ENTITY_SYNC_MIN_WP
				);
			}

			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		}
	);

	return;
}

require_once __DIR__ . '/vendor/autoload.php';

add_action(
	'plugins_loaded',
	static function () {
		( new \SelectiveEntitySync\Plugin( SELECTIVE_ENTITY_SYNC_FILE, SELECTIVE_ENTITY_SYNC_VERSION ) )->boot();
	}
);
