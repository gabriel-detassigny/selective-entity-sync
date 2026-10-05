<?php
/**
 * Attachment import handler.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import\Handlers;

use SelectiveEntitySync\Import\AuthorResolver;
use SelectiveEntitySync\Import\EntityMatch;
use SelectiveEntitySync\Import\ImportContext;
use SelectiveEntitySync\Import\ImportException;
use SelectiveEntitySync\Import\MetaImporter;
use WP_Error;
use WP_Post;

/**
 * Imports media attachments and their files.
 *
 * New attachments are sideloaded like regular uploads (file type checks apply)
 * and their image sizes generated. For existing attachments the file is only
 * replaced when its checksum differs. Every source URL of the attachment (full
 * size, original, each image size) is mapped to the local URL, so content can
 * be rewritten.
 */
class AttachmentHandler implements ImportHandler, LinksExistingEntities {

	/**
	 * Meta importer.
	 *
	 * @var MetaImporter
	 */
	private $meta;

	/**
	 * Author resolver.
	 *
	 * @var AuthorResolver
	 */
	private $authors;

	/**
	 * Constructor.
	 *
	 * @param MetaImporter   $meta    Meta importer.
	 * @param AuthorResolver $authors Author resolver.
	 */
	public function __construct( MetaImporter $meta, AuthorResolver $authors ) {
		$this->meta    = $meta;
		$this->authors = $authors;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( array $entity ): bool {
		return 'attachment' === $entity['type'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_object_type(): string {
		return 'post';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_validation_error( array $entity ): ?string {
		if ( ! empty( $entity['reference_only'] ) ) {
			return null;
		}

		$name = (string) ( $entity['file']['original_name'] ?? '' );
		$type = wp_check_filetype( $name, get_allowed_mime_types() );

		if ( '' === $name || empty( $type['ext'] ) ) {
			return sprintf(
				/* translators: %s: File name. */
				__( 'The file type of "%s" is not allowed on this site.', 'selective-entity-sync' ),
				$name
			);
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_by_natural_key( array $entity, ImportContext $context ): ?EntityMatch {
		$slug = (string) ( $entity['data']['post_name'] ?? '' );
		if ( '' === $slug ) {
			return null;
		}

		$candidates = get_posts(
			array(
				'post_type'              => 'attachment',
				'post_status'            => 'inherit',
				'name'                   => $slug,
				'post_mime_type'         => (string) ( $entity['data']['post_mime_type'] ?? '' ),
				'posts_per_page'         => 5,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$file = isset( $entity['file']['path'] ) ? $context->get_manifest()->get_file( (string) $entity['file']['path'] ) : null;

		foreach ( $candidates as $candidate ) {
			// Reference-only entities carry no file: the slug and MIME type must do.
			if ( null === $file ) {
				return new EntityMatch( (int) $candidate, EntityMatch::BY_SLUG );
			}

			$path = $this->get_local_file( (int) $candidate );
			if ( null !== $path && hash_equals( $file['sha256'], (string) hash_file( 'sha256', $path ) ) ) {
				return new EntityMatch( (int) $candidate, EntityMatch::BY_FILE );
			}
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dependencies( array $entity, ImportContext $context ): array {
		return array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ImportException When the object cannot be saved.
	 */
	public function import( array $entity, ?int $local_id, ImportContext $context ): int {
		$this->load_media_api();

		$data     = $entity['data'];
		$package  = $context->get_package();
		$source   = $package->get_file_path( (string) $entity['file']['path'] );
		$file     = $context->get_manifest()->get_file( (string) $entity['file']['path'] );
		$existing = null !== $local_id ? get_post( $local_id ) : null;

		$fields = array(
			'post_title'   => (string) ( $data['post_title'] ?? '' ),
			'post_excerpt' => (string) ( $data['post_excerpt'] ?? '' ),
			'post_content' => (string) ( $data['post_content'] ?? '' ),
			'post_name'    => (string) ( $data['post_name'] ?? '' ),
			'menu_order'   => (int) ( $data['menu_order'] ?? 0 ),
			'post_author'  => $this->authors->resolve( $entity['relations']['author'] ?? null, $existing instanceof WP_Post ? (int) $existing->post_author : 0 ),
		);

		if ( $existing instanceof WP_Post ) {
			$attachment_id = $existing->ID;
			$local_file    = $this->get_local_file( $attachment_id );

			if ( null === $local_file || null === $file || ! hash_equals( $file['sha256'], (string) hash_file( 'sha256', $local_file ) ) ) {
				$this->replace_file( $attachment_id, $source, $entity );
			}

			$result = wp_update_post( wp_slash( array_merge( $fields, array( 'ID' => $attachment_id ) ) ), true );
			if ( $result instanceof WP_Error ) {
				throw $this->error( $fields['post_title'], $result );
			}
		} else {
			$attachment_id = $this->create( $source, $entity, $fields );
		}

		$this->meta->import( 'post', $attachment_id, $entity, $context );
		$this->register_url_replacements( $attachment_id, $entity, $context );

		return $attachment_id;
	}

	/**
	 * {@inheritDoc}
	 */
	public function link( array $entity, int $local_id, ImportContext $context ): void {
		$this->register_url_replacements( $local_id, $entity, $context );
	}

	/**
	 * Creates an attachment from a package file.
	 *
	 * @param string               $source Absolute path of the extracted file.
	 * @param array<string, mixed> $entity Manifest entity.
	 * @param array<string, mixed> $fields Post fields.
	 * @return int Attachment ID.
	 * @throws ImportException When the file or the attachment cannot be saved.
	 */
	private function create( string $source, array $entity, array $fields ): int {
		$upload = $this->sideload( $source, $entity );

		$attachment_id = wp_insert_attachment(
			wp_slash(
				array_merge(
					$fields,
					array(
						'post_mime_type' => $upload['type'],
						'post_status'    => 'inherit',
						'guid'           => $upload['url'],
					)
				)
			),
			$upload['file'],
			0,
			true
		);

		if ( $attachment_id instanceof WP_Error ) {
			wp_delete_file( $upload['file'] );
			throw $this->error( (string) $fields['post_title'], $attachment_id );
		}

		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		return $attachment_id;
	}

	/**
	 * Replaces an attachment's file, regenerates its sizes and deletes the old files.
	 *
	 * @param int                  $attachment_id Attachment ID.
	 * @param string               $source        Absolute path of the extracted file.
	 * @param array<string, mixed> $entity        Manifest entity.
	 * @return void
	 */
	private function replace_file( int $attachment_id, string $source, array $entity ): void {
		$old_metadata = wp_get_attachment_metadata( $attachment_id );
		$old_backups  = get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true );
		$old_file     = get_attached_file( $attachment_id );

		$upload = $this->sideload( $source, $entity );

		update_attached_file( $attachment_id, $upload['file'] );
		wp_update_post(
			array(
				'ID'             => $attachment_id,
				'post_mime_type' => $upload['type'],
			)
		);
		wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );

		if ( is_string( $old_file ) && '' !== $old_file && $old_file !== $upload['file'] ) {
			wp_delete_attachment_files( $attachment_id, is_array( $old_metadata ) ? $old_metadata : array(), is_array( $old_backups ) ? $old_backups : array(), $old_file );
		}
	}

	/**
	 * Copies a package file into the uploads directory, with upload checks.
	 *
	 * Keeps the source's year/month folder when uploads are organised that way.
	 *
	 * @param string               $source Absolute path of the extracted file.
	 * @param array<string, mixed> $entity Manifest entity.
	 * @return array{file: string, url: string, type: string}
	 * @throws ImportException When the file is rejected or cannot be copied.
	 */
	private function sideload( string $source, array $entity ): array {
		$name = sanitize_file_name( (string) ( $entity['file']['original_name'] ?? wp_basename( $source ) ) );
		$tmp  = wp_tempnam( $name );

		if ( ! $tmp || ! copy( $source, $tmp ) ) {
			throw new ImportException(
				sprintf(
					/* translators: %s: File name. */
					__( 'The file "%s" could not be copied for import.', 'selective-entity-sync' ),
					$name
				)
			);
		}

		$time = null;
		if ( preg_match( '#^(\d{4}/\d{2})/#', (string) ( $entity['file']['relative_path'] ?? '' ), $match ) ) {
			$time = $match[1];
		}

		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
			'size'     => (int) filesize( $tmp ),
			'error'    => 0,
		);
		$upload     = wp_handle_sideload( $file_array, array( 'test_form' => false ), $time );

		if ( isset( $upload['error'] ) || ! isset( $upload['file'], $upload['url'], $upload['type'] ) ) {
			wp_delete_file( $tmp );
			throw new ImportException(
				sprintf(
					/* translators: 1: File name, 2: Error message. */
					__( 'The file "%1$s" could not be imported: %2$s', 'selective-entity-sync' ),
					$name,
					(string) ( $upload['error'] ?? __( 'unknown error', 'selective-entity-sync' ) )
				)
			);
		}

		return array(
			'file' => (string) $upload['file'],
			'url'  => (string) $upload['url'],
			'type' => (string) $upload['type'],
		);
	}

	/**
	 * Maps every source URL of the attachment to its local equivalent.
	 *
	 * @param int                  $attachment_id Local attachment ID.
	 * @param array<string, mixed> $entity        Manifest entity.
	 * @param ImportContext        $context       Import context.
	 * @return void
	 */
	private function register_url_replacements( int $attachment_id, array $entity, ImportContext $context ): void {
		$urls      = (array) ( $entity['file']['urls'] ?? array() );
		$local_url = (string) wp_get_attachment_url( $attachment_id );

		if ( isset( $urls['full'] ) ) {
			$context->add_url_replacement( (string) $urls['full'], $local_url );
		}

		if ( isset( $urls['original'] ) ) {
			$original = wp_get_original_image_url( $attachment_id );
			$context->add_url_replacement( (string) $urls['original'], is_string( $original ) ? $original : $local_url );
		}

		foreach ( (array) ( $urls['sizes'] ?? array() ) as $size => $url ) {
			$image = wp_get_attachment_image_src( $attachment_id, (string) $size );
			$context->add_url_replacement( (string) $url, is_array( $image ) ? (string) $image[0] : $local_url );
		}
	}

	/**
	 * Returns the absolute path of an attachment's original file, or null if missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	private function get_local_file( int $attachment_id ): ?string {
		$path = wp_attachment_is_image( $attachment_id ) ? wp_get_original_image_path( $attachment_id ) : false;
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			$path = get_attached_file( $attachment_id );
		}

		return is_string( $path ) && is_file( $path ) ? $path : null;
	}

	/**
	 * Loads the admin media functions (not loaded on REST/CLI requests).
	 *
	 * @return void
	 */
	private function load_media_api(): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
	}

	/**
	 * Builds an exception from a WP_Error.
	 *
	 * @param string   $title Attachment title.
	 * @param WP_Error $error Error.
	 * @return ImportException
	 */
	private function error( string $title, WP_Error $error ): ImportException {
		return new ImportException(
			sprintf(
				/* translators: 1: Media title, 2: Error message. */
				__( 'The media "%1$s" could not be saved: %2$s', 'selective-entity-sync' ),
				$title,
				$error->get_error_message()
			)
		);
	}
}
