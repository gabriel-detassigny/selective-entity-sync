<?php
/**
 * Temporary storage.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Storage;

use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Exception\SyncException;
use WP_Filesystem_Direct;

/**
 * Creates and cleans up working directories for building and extracting packages.
 *
 * Directories live under the system temp dir (get_temp_dir()), which is never
 * web-accessible, unlike wp-content/uploads. Stale directories are removed by
 * a daily cron event.
 */
class TempStorage implements Hookable {

	public const CRON_HOOK = 'selective_entity_sync_cleanup_temp';

	public const DIRECTORY_NAME = 'selective-entity-sync';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'init', array( $this, 'schedule_cleanup' ) );
		add_action( self::CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
	}

	/**
	 * Schedules the daily cleanup event if it is not scheduled yet.
	 *
	 * @return void
	 */
	public function schedule_cleanup(): void {
		if ( false === wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Removes the cleanup event. Called on plugin deactivation.
	 *
	 * @return void
	 */
	public static function unschedule_cleanup(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Returns the base directory holding all working directories.
	 *
	 * @return string Absolute path, without trailing slash.
	 */
	public function get_base_directory(): string {
		return trailingslashit( get_temp_dir() ) . self::DIRECTORY_NAME;
	}

	/**
	 * Creates a new, uniquely named working directory.
	 *
	 * @return string Absolute path, without trailing slash.
	 * @throws SyncException When the directory cannot be created.
	 */
	public function create_directory(): string {
		$directory = $this->get_base_directory() . '/' . wp_generate_uuid4();

		if ( ! wp_mkdir_p( $directory ) ) {
			throw new SyncException( __( 'A temporary working directory could not be created.', 'selective-entity-sync' ) );
		}

		// Packages hold unpublished content: keep them private to this system user (shared hosting).
		$this->filesystem()->chmod( $directory, 0700 );

		return $directory;
	}

	/**
	 * Recursively deletes a working directory. Refuses paths outside the base directory.
	 *
	 * @param string $directory Absolute path of a directory returned by create_directory().
	 * @return bool Whether the directory was deleted.
	 */
	public function delete( string $directory ): bool {
		$base = realpath( $this->get_base_directory() );
		$path = realpath( $directory );

		if ( false === $base || false === $path || 0 !== strpos( $path, $base . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		return $this->filesystem()->delete( $path, true );
	}

	/**
	 * Cron callback for the daily cleanup.
	 *
	 * @return void
	 */
	public function run_scheduled_cleanup(): void {
		$this->cleanup_expired();
	}

	/**
	 * Deletes working directories older than the configured lifetime.
	 *
	 * @return int Number of directories deleted.
	 */
	public function cleanup_expired(): int {
		$base = $this->get_base_directory();
		if ( ! is_dir( $base ) ) {
			return 0;
		}

		/**
		 * Filters how long temporary working directories are kept before cleanup.
		 *
		 * @since 0.1.0
		 *
		 * @param int $lifetime Lifetime in seconds. Default one day.
		 */
		$lifetime = apply_filters( 'selective_entity_sync_temp_file_lifetime', DAY_IN_SECONDS );
		$lifetime = is_int( $lifetime ) && $lifetime > 0 ? $lifetime : DAY_IN_SECONDS;

		$deleted = 0;
		$entries = $this->filesystem()->dirlist( $base, false );
		foreach ( is_array( $entries ) ? $entries : array() as $name => $entry ) {
			if ( 'd' !== $entry['type'] || (int) $entry['lastmodunix'] > time() - $lifetime ) {
				continue;
			}
			if ( $this->delete( $base . '/' . $name ) ) {
				++$deleted;
			}
		}

		return $deleted;
	}

	/**
	 * Returns a direct filesystem instance. Only used for our own temp files.
	 *
	 * @return WP_Filesystem_Direct
	 */
	private function filesystem(): WP_Filesystem_Direct {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		return new WP_Filesystem_Direct( null );
	}
}
