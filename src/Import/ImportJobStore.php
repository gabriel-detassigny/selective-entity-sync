<?php
/**
 * Import job store.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Storage\TempStorage;

/**
 * Persists import jobs between batch requests.
 *
 * Jobs are stored in non-autoloaded options rather than transients, which a
 * persistent object cache could evict mid-import. An index option lists them
 * for cleanup. A short-lived lock, taken with add_option() (atomic), stops two
 * requests from running the same job at once.
 */
class ImportJobStore implements Hookable {

	public const OPTION_PREFIX = 'selective_entity_sync_job_';

	public const LOCK_PREFIX = 'selective_entity_sync_job_lock_';

	public const INDEX_OPTION = 'selective_entity_sync_jobs';

	/**
	 * Seconds after which a lock is considered abandoned (e.g. a fatal error mid-batch).
	 */
	private const LOCK_TIMEOUT = 300;

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( TempStorage::CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
	}

	/**
	 * Saves a job for the current user.
	 *
	 * @param string    $token Package token the job belongs to.
	 * @param ImportJob $job   Job.
	 * @return void
	 */
	public function save( string $token, ImportJob $job ): void {
		update_option(
			self::OPTION_PREFIX . $token,
			array(
				'user_id' => get_current_user_id(),
				'updated' => time(),
				'job'     => $job->to_array(),
			),
			false
		);

		$index           = $this->get_index();
		$index[ $token ] = time();
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Loads a job owned by the current user.
	 *
	 * @param string $token Package token.
	 * @return ImportJob|null
	 */
	public function load( string $token ): ?ImportJob {
		$stored = get_option( self::OPTION_PREFIX . $token );
		if ( ! is_array( $stored ) || (int) ( $stored['user_id'] ?? -1 ) !== get_current_user_id() || ! is_array( $stored['job'] ?? null ) ) {
			return null;
		}

		return ImportJob::from_array( $stored['job'] );
	}

	/**
	 * Deletes a job and its lock.
	 *
	 * @param string $token Package token.
	 * @return void
	 */
	public function delete( string $token ): void {
		delete_option( self::OPTION_PREFIX . $token );
		delete_option( self::LOCK_PREFIX . $token );

		$index = $this->get_index();
		unset( $index[ $token ] );
		update_option( self::INDEX_OPTION, $index, false );
	}

	/**
	 * Takes the job's lock.
	 *
	 * @param string $token Package token.
	 * @return bool False if another request holds it.
	 */
	public function acquire_lock( string $token ): bool {
		if ( add_option( self::LOCK_PREFIX . $token, time(), '', false ) ) {
			return true;
		}

		$since = (int) get_option( self::LOCK_PREFIX . $token, 0 );
		if ( $since > 0 && time() - $since < self::LOCK_TIMEOUT ) {
			return false;
		}

		// Abandoned lock: take it over.
		delete_option( self::LOCK_PREFIX . $token );

		return add_option( self::LOCK_PREFIX . $token, time(), '', false );
	}

	/**
	 * Releases the job's lock.
	 *
	 * @param string $token Package token.
	 * @return void
	 */
	public function release_lock( string $token ): void {
		delete_option( self::LOCK_PREFIX . $token );
	}

	/**
	 * Cron callback: deletes jobs not updated within the temp file lifetime.
	 *
	 * @return void
	 */
	public function run_scheduled_cleanup(): void {
		/** This filter is documented in src/Storage/TempStorage.php */
		$lifetime = apply_filters( 'selective_entity_sync_temp_file_lifetime', DAY_IN_SECONDS );
		$lifetime = is_int( $lifetime ) && $lifetime > 0 ? $lifetime : DAY_IN_SECONDS;

		foreach ( $this->get_index() as $token => $updated ) {
			if ( (int) $updated < time() - $lifetime ) {
				$this->delete( (string) $token );
			}
		}
	}

	/**
	 * Returns the job index: token => last update time.
	 *
	 * @return array<string, int>
	 */
	private function get_index(): array {
		$index = get_option( self::INDEX_OPTION, array() );

		return is_array( $index ) ? $index : array();
	}
}
