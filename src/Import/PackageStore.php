<?php
/**
 * Uploaded package store.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Storage\TempStorage;
use WP_Filesystem_Direct;

/**
 * Keeps uploaded packages between the preview and the import requests.
 *
 * Packages are stored under wp-content/uploads (shared between web servers,
 * unlike the system temp dir) in a directory that denies web access, with
 * unguessable UUID file names. Each package belongs to the user who uploaded
 * it and is deleted after import, on discard, or by the daily cleanup.
 */
class PackageStore implements Hookable {

	public const DIRECTORY_NAME = 'selective-entity-sync-packages';

	private const TRANSIENT_PREFIX = 'selective_entity_sync_pkg_';

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( TempStorage::CRON_HOOK, array( $this, 'run_scheduled_cleanup' ) );
	}

	/**
	 * Stores an uploaded file (an entry of $_FILES). Only genuine HTTP uploads are accepted.
	 *
	 * @param array<string, mixed> $file Uploaded file array.
	 * @return string Package token.
	 * @throws ImportException When the upload is rejected.
	 */
	public function store_upload( array $file ): string {
		return $this->store( $file, 'wp_handle_upload' );
	}

	/**
	 * Stores a local file, e.g. one fetched by code rather than uploaded through a form.
	 *
	 * The file is copied; the same type checks as uploads apply.
	 *
	 * @param string $path Absolute path of the zip file.
	 * @param string $name File name to remember.
	 * @return string Package token.
	 * @throws ImportException When the file is rejected.
	 */
	public function store_file( string $path, string $name = 'package.zip' ): string {
		$tmp = wp_tempnam( $name );
		if ( ! $tmp || ! copy( $path, $tmp ) ) {
			throw new ImportException( esc_html__( 'The package could not be copied.', 'selective-entity-sync' ) );
		}

		try {
			return $this->store(
				array(
					'name'     => $name,
					'tmp_name' => $tmp,
					'size'     => (int) filesize( $tmp ),
					'error'    => 0,
				),
				'wp_handle_sideload'
			);
		} finally {
			if ( is_file( $tmp ) ) {
				wp_delete_file( $tmp );
			}
		}
	}

	/**
	 * Moves a file into the store with WordPress's upload handling (size, type and extension checks).
	 *
	 * @param array<string, mixed> $file   File array.
	 * @param string               $action 'wp_handle_upload' or 'wp_handle_sideload'.
	 * @return string Package token.
	 * @throws ImportException When the file is rejected.
	 */
	private function store( array $file, string $action ): string {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$token     = wp_generate_uuid4();
		$directory = $this->ensure_directory();

		$set_directory = static function ( array $dirs ) use ( $directory ) {
			$dirs['path']   = $directory;
			$dirs['subdir'] = '';
			$dirs['url']    = $dirs['baseurl'] . '/' . self::DIRECTORY_NAME;
			return $dirs;
		};

		$overrides = array(
			'test_form'                => false,
			'mimes'                    => array( 'zip' => 'application/zip' ),
			'unique_filename_callback' => static function () use ( $token ) {
				return $token . '.zip';
			},
		);

		add_filter( 'upload_dir', $set_directory );
		$result = 'wp_handle_sideload' === $action ? wp_handle_sideload( $file, $overrides ) : wp_handle_upload( $file, $overrides );
		remove_filter( 'upload_dir', $set_directory );

		if ( isset( $result['error'] ) || ! isset( $result['file'] ) ) {
			throw new ImportException(
				sprintf(
					/* translators: %s: Upload error message. */
					esc_html__( 'The package could not be uploaded: %s', 'selective-entity-sync' ),
					esc_html( (string) ( $result['error'] ?? __( 'unknown error', 'selective-entity-sync' ) ) )
				)
			);
		}

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'user_id'  => get_current_user_id(),
				'filename' => sanitize_file_name( (string) ( $file['name'] ?? 'package.zip' ) ),
			),
			$this->get_lifetime()
		);

		return $token;
	}

	/**
	 * Returns the path of a stored package owned by the current user, or null.
	 *
	 * @param string $token Package token.
	 * @return string|null
	 */
	public function get_path( string $token ): ?string {
		$info = $this->get_info( $token );
		if ( null === $info ) {
			return null;
		}

		$path = $this->get_directory() . '/' . strtolower( $token ) . '.zip';

		return is_file( $path ) ? $path : null;
	}

	/**
	 * Returns the stored package's info (owner, original file name), or null if unknown or not owned by the current user.
	 *
	 * @param string $token Package token.
	 * @return array{user_id: int, filename: string}|null
	 */
	public function get_info( string $token ): ?array {
		if ( 1 !== preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', strtolower( $token ) ) ) {
			return null;
		}

		$info = get_transient( self::TRANSIENT_PREFIX . strtolower( $token ) );
		if ( ! is_array( $info ) || (int) ( $info['user_id'] ?? -1 ) !== get_current_user_id() ) {
			return null;
		}

		return array(
			'user_id'  => (int) $info['user_id'],
			'filename' => (string) ( $info['filename'] ?? '' ),
		);
	}

	/**
	 * Deletes a stored package.
	 *
	 * @param string $token Package token.
	 * @return void
	 */
	public function delete( string $token ): void {
		$path = $this->get_path( $token );
		if ( null !== $path ) {
			wp_delete_file( $path );
		}
		delete_transient( self::TRANSIENT_PREFIX . strtolower( $token ) );
	}

	/**
	 * Cron callback: deletes packages older than the temp file lifetime.
	 *
	 * @return void
	 */
	public function run_scheduled_cleanup(): void {
		$directory = $this->get_directory();
		if ( ! is_dir( $directory ) ) {
			return;
		}

		$entries = $this->filesystem()->dirlist( $directory, false );
		foreach ( is_array( $entries ) ? $entries : array() as $name => $entry ) {
			if ( 'f' === $entry['type'] && '.zip' === substr( (string) $name, -4 ) && (int) $entry['lastmodunix'] < time() - $this->get_lifetime() ) {
				wp_delete_file( $directory . '/' . $name );
			}
		}
	}

	/**
	 * Returns the storage directory.
	 *
	 * @return string Absolute path, without trailing slash.
	 */
	public function get_directory(): string {
		return untrailingslashit( wp_upload_dir( null, false )['basedir'] ) . '/' . self::DIRECTORY_NAME;
	}

	/**
	 * Creates the storage directory and its access protection.
	 *
	 * @return string Absolute path.
	 * @throws ImportException When the directory cannot be created.
	 */
	private function ensure_directory(): string {
		$directory = $this->get_directory();

		if ( ! wp_mkdir_p( $directory ) ) {
			throw new ImportException( esc_html__( 'The package storage directory could not be created.', 'selective-entity-sync' ) );
		}

		$filesystem = $this->filesystem();
		if ( ! $filesystem->exists( $directory . '/index.php' ) ) {
			// Explicit mode: FS_CHMOD_FILE is only defined once WP_Filesystem() has run, which REST requests don't do.
			$filesystem->put_contents( $directory . '/index.php', "<?php\n// Silence is golden.\n", 0644 );
		}
		if ( ! $filesystem->exists( $directory . '/.htaccess' ) ) {
			$filesystem->put_contents( $directory . '/.htaccess', "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n\tDeny from all\n</IfModule>\n", 0644 );
		}

		return $directory;
	}

	/**
	 * Returns how long packages are kept.
	 *
	 * @return int Seconds.
	 */
	private function get_lifetime(): int {
		/** This filter is documented in src/Storage/TempStorage.php */
		$lifetime = apply_filters( 'selective_entity_sync_temp_file_lifetime', DAY_IN_SECONDS );

		return is_int( $lifetime ) && $lifetime > 0 ? $lifetime : DAY_IN_SECONDS;
	}

	/**
	 * Returns a direct filesystem instance.
	 *
	 * @return WP_Filesystem_Direct
	 */
	private function filesystem(): WP_Filesystem_Direct {
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';

		return new WP_Filesystem_Direct( null );
	}
}
