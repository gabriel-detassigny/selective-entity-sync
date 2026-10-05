<?php
/**
 * Attachment collector.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;
use WP_Post;

/**
 * Exports media attachments with their file.
 *
 * The original upload is bundled (not the "-scaled" copy WordPress creates for
 * large images), so the target can regenerate every image size. Attachment
 * parents are not exported: an attachment is a dependency of the content using
 * it, not of the post it was first uploaded to.
 */
class AttachmentCollector implements EntityCollector {

	public const ENTITY_TYPE = 'attachment';

	/**
	 * Maximum length of the bundled file name.
	 */
	private const MAX_FILE_NAME_LENGTH = 100;

	/**
	 * Meta collector.
	 *
	 * @var MetaCollector
	 */
	private $meta;

	/**
	 * Constructor.
	 *
	 * @param MetaCollector $meta Meta collector.
	 */
	public function __construct( MetaCollector $meta ) {
		$this->meta = $meta;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( EntityReference $reference ): bool {
		if ( EntityReference::POST !== $reference->get_object_type() ) {
			return false;
		}

		$post = get_post( $reference->get_id() );

		return $post instanceof WP_Post && 'attachment' === $post->post_type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function collect( EntityReference $reference, ExportContext $context ): array {
		$post   = $this->get_post( $reference );
		$source = $this->get_source_file( $post->ID );

		if ( null === $source ) {
			$context->add_warning(
				sprintf(
					/* translators: 1: Attachment title, 2: Attachment ID. */
					__( 'The file of media "%1$s" (#%2$d) was not found on disk, so it was not included. It will only be linked if it already exists on the target site.', 'selective-entity-sync' ),
					$post->post_title,
					$post->ID
				)
			);
			return $this->collect_reference( $reference, $context );
		}

		$uuid         = $context->uuid( $reference );
		$package_path = 'media/' . $uuid . '/' . $this->safe_file_name( $source );
		$context->add_file( $package_path, $source );

		return array(
			'uuid'      => $uuid,
			'type'      => self::ENTITY_TYPE,
			'source_id' => $post->ID,
			'data'      => array(
				'post_title'     => $post->post_title,
				'post_excerpt'   => $post->post_excerpt,
				'post_content'   => $post->post_content,
				'post_name'      => $post->post_name,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'post_mime_type' => $post->post_mime_type,
				'menu_order'     => (int) $post->menu_order,
			),
			'file'      => array(
				'path'          => $package_path,
				'original_name' => wp_basename( $source ),
				'relative_path' => (string) get_post_meta( $post->ID, '_wp_attached_file', true ),
			),
			'relations' => array(
				'author' => AuthorInfo::for_user( (int) $post->post_author ),
			),
			'meta'      => $this->meta->collect( $reference, $context ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function collect_reference( EntityReference $reference, ExportContext $context ): array {
		$post = $this->get_post( $reference );

		return array(
			'uuid'           => $context->uuid( $reference ),
			'type'           => self::ENTITY_TYPE,
			'source_id'      => $post->ID,
			'reference_only' => true,
			'data'           => array(
				'post_name'      => $post->post_name,
				'post_title'     => $post->post_title,
				'post_mime_type' => $post->post_mime_type,
			),
		);
	}

	/**
	 * Returns the absolute path of the original uploaded file, or null if missing.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|null
	 */
	private function get_source_file( int $attachment_id ): ?string {
		$path = wp_attachment_is_image( $attachment_id ) ? wp_get_original_image_path( $attachment_id ) : false;
		if ( ! is_string( $path ) || ! is_file( $path ) ) {
			$path = get_attached_file( $attachment_id );
		}

		return is_string( $path ) && is_file( $path ) && is_readable( $path ) ? $path : null;
	}

	/**
	 * Builds a package-safe file name ([A-Za-z0-9._-]) that keeps the extension.
	 *
	 * @param string $source Absolute source path.
	 * @return string
	 */
	private function safe_file_name( string $source ): string {
		$name = remove_accents( sanitize_file_name( wp_basename( $source ) ) );
		$name = trim( (string) preg_replace( '/[^A-Za-z0-9._-]+/', '-', $name ), '.-' );

		$extension = pathinfo( $name, PATHINFO_EXTENSION );
		$base      = pathinfo( $name, PATHINFO_FILENAME );
		if ( '' === $base ) {
			$base = 'file';
		}

		$max_base = self::MAX_FILE_NAME_LENGTH - ( '' === $extension ? 0 : strlen( $extension ) + 1 );
		$base     = substr( $base, 0, max( 1, $max_base ) );

		return '' === $extension ? $base : $base . '.' . $extension;
	}

	/**
	 * Loads the attachment.
	 *
	 * @param EntityReference $reference Attachment reference.
	 * @return WP_Post
	 * @throws \InvalidArgumentException When the object does not exist.
	 */
	private function get_post( EntityReference $reference ): WP_Post {
		$post = get_post( $reference->get_id() );
		if ( ! $post instanceof WP_Post ) {
			throw new \InvalidArgumentException( sprintf( 'Attachment %d does not exist.', $reference->get_id() ) );
		}

		return $post;
	}
}
