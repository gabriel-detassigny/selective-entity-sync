<?php
/**
 * Post import handler.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import\Handlers;

use SelectiveEntitySync\Content\ContentReferenceFinder;
use SelectiveEntitySync\Content\ContentRewriter;
use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\AuthorResolver;
use SelectiveEntitySync\Import\EntityMatch;
use SelectiveEntitySync\Import\ImportContext;
use SelectiveEntitySync\Import\ImportException;
use SelectiveEntitySync\Import\MetaImporter;
use SelectiveEntitySync\Support\ObjectPermissions;
use WP_Error;
use WP_Post;

/**
 * Imports posts, pages, custom post types and synced patterns.
 */
class PostHandler implements ImportHandler {

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

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
	 * Content reference finder (for dependency ordering).
	 *
	 * @var ContentReferenceFinder
	 */
	private $finder;

	/**
	 * Content rewriter.
	 *
	 * @var ContentRewriter
	 */
	private $rewriter;

	/**
	 * Per-object permissions.
	 *
	 * @var ObjectPermissions
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @param ExportSettings         $settings    Export settings.
	 * @param MetaImporter           $meta        Meta importer.
	 * @param AuthorResolver         $authors     Author resolver.
	 * @param ContentReferenceFinder $finder      Content reference finder.
	 * @param ContentRewriter        $rewriter    Content rewriter.
	 * @param ObjectPermissions      $permissions Per-object permissions.
	 */
	public function __construct( ExportSettings $settings, MetaImporter $meta, AuthorResolver $authors, ContentReferenceFinder $finder, ContentRewriter $rewriter, ObjectPermissions $permissions ) {
		$this->settings    = $settings;
		$this->meta        = $meta;
		$this->authors     = $authors;
		$this->finder      = $finder;
		$this->rewriter    = $rewriter;
		$this->permissions = $permissions;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( array $entity ): bool {
		return 'post' === $entity['type'];
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
		$post_type = (string) ( $entity['data']['post_type'] ?? '' );

		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return sprintf(
				/* translators: %s: Post type name. */
				__( 'The post type "%s" is not registered on this site.', 'selective-entity-sync' ),
				$post_type
			);
		}

		// Only content types can be imported: never templates, styles, changesets or revisions.
		if ( ! in_array( $post_type, $this->settings->get_exportable_post_types(), true ) ) {
			return sprintf(
				/* translators: %s: Post type name. */
				__( 'Content of type "%s" cannot be imported on this site.', 'selective-entity-sync' ),
				$post_type
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

		$ids = get_posts(
			array(
				'post_type'              => (string) $entity['data']['post_type'],
				'name'                   => $slug,
				'post_status'            => array_values( array_diff( array_keys( get_post_stati() ), array( 'trash', 'auto-draft', 'inherit' ) ) ),
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		return isset( $ids[0] ) ? new EntityMatch( (int) $ids[0], EntityMatch::BY_SLUG ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dependencies( array $entity, ImportContext $context ): array {
		$uuids = array();

		if ( ! empty( $entity['relations']['parent'] ) ) {
			$uuids[] = (string) $entity['relations']['parent'];
		}

		foreach ( (array) ( $entity['relations']['terms'] ?? array() ) as $term_uuids ) {
			foreach ( (array) $term_uuids as $uuid ) {
				$uuids[] = (string) $uuid;
			}
		}

		foreach ( $this->settings->get_id_reference_meta_keys( 'post' ) as $key => $object_type ) {
			foreach ( (array) ( $entity['meta'][ $key ] ?? array() ) as $value ) {
				foreach ( preg_split( '/\s*,\s*/', implode( ',', (array) $value ) ) as $id ) {
					$uuid = is_numeric( $id ) ? $context->get_uuid_for_source_id( $object_type, (int) $id ) : null;
					if ( null !== $uuid ) {
						$uuids[] = $uuid;
					}
				}
			}
		}

		foreach ( $this->finder->find_post_ids( (string) ( $entity['data']['post_content'] ?? '' ) ) as $source_id ) {
			$uuid = $context->get_uuid_for_source_id( 'post', $source_id );
			if ( null !== $uuid ) {
				$uuids[] = $uuid;
			}
		}

		return array_values( array_unique( $uuids ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ImportException When the object cannot be saved.
	 */
	public function import( array $entity, ?int $local_id, ImportContext $context ): int {
		$data     = $entity['data'];
		$existing = null !== $local_id ? get_post( $local_id ) : null;
		$status   = in_array( $data['post_status'] ?? '', $this->settings->get_exportable_statuses(), true ) ? (string) $data['post_status'] : 'draft';

		$postarr = array(
			'post_type'      => (string) $data['post_type'],
			'post_status'    => $status,
			'post_title'     => (string) ( $data['post_title'] ?? '' ),
			'post_content'   => $this->rewrite( (string) ( $data['post_content'] ?? '' ), $context ),
			'post_excerpt'   => $this->rewrite( (string) ( $data['post_excerpt'] ?? '' ), $context ),
			'post_name'      => (string) ( $data['post_name'] ?? '' ),
			'post_date'      => (string) ( $data['post_date'] ?? '' ),
			'post_date_gmt'  => (string) ( $data['post_date_gmt'] ?? '' ),
			'menu_order'     => (int) ( $data['menu_order'] ?? 0 ),
			'comment_status' => (string) ( $data['comment_status'] ?? get_default_comment_status( (string) $data['post_type'] ) ),
			'ping_status'    => (string) ( $data['ping_status'] ?? get_default_comment_status( (string) $data['post_type'], 'pingback' ) ),
			'post_password'  => (string) ( $data['post_password'] ?? '' ),
			'post_parent'    => $context->get_local_id( $entity['relations']['parent'] ?? null ) ?? 0,
			'post_author'    => $this->authors->resolve( $entity['relations']['author'] ?? null, $existing instanceof WP_Post ? (int) $existing->post_author : 0 ),
		);

		if ( ! $this->permissions->can_write_post( $postarr['post_type'], $status, $existing instanceof WP_Post ? $existing->ID : null, $postarr['post_author'] ) ) {
			throw new ImportException(
				sprintf(
					/* translators: %s: Post title. */
					__( 'You are not allowed to create or edit "%s".', 'selective-entity-sync' ),
					$postarr['post_title']
				)
			);
		}

		if ( $existing instanceof WP_Post ) {
			$postarr['ID']        = $existing->ID;
			$postarr['edit_date'] = true;
			$result               = wp_update_post( wp_slash( $postarr ), true );
		} else {
			$result = wp_insert_post( wp_slash( $postarr ), true );
		}

		if ( $result instanceof WP_Error ) {
			throw new ImportException(
				sprintf(
					/* translators: 1: Post title, 2: Error message. */
					__( '"%1$s" could not be saved: %2$s', 'selective-entity-sync' ),
					$postarr['post_title'],
					$result->get_error_message()
				)
			);
		}

		$post_id = (int) $result;

		$this->import_terms( $post_id, (string) $data['post_type'], $entity, $context );
		$this->meta->import( 'post', $post_id, $entity, $context );

		if ( 'post' === $data['post_type'] && isset( $data['sticky'] ) ) {
			$data['sticky'] ? stick_post( $post_id ) : unstick_post( $post_id );
		}

		return $post_id;
	}

	/**
	 * Replaces the post's terms in each taxonomy present in the entity.
	 *
	 * @param int                  $post_id   Local post ID.
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $entity    Manifest entity.
	 * @param ImportContext        $context   Import context.
	 * @return void
	 */
	private function import_terms( int $post_id, string $post_type, array $entity, ImportContext $context ): void {
		foreach ( (array) ( $entity['relations']['terms'] ?? array() ) as $taxonomy => $uuids ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				continue;
			}

			$term_ids = array();
			foreach ( (array) $uuids as $uuid ) {
				$term_id = $context->get_local_id( (string) $uuid );
				if ( null !== $term_id ) {
					$term_ids[] = $term_id;
				}
			}

			wp_set_object_terms( $post_id, $term_ids, $taxonomy );
		}
	}

	/**
	 * Rewrites source IDs and URLs in content.
	 *
	 * @param string        $content Content.
	 * @param ImportContext $context Import context.
	 * @return string
	 */
	private function rewrite( string $content, ImportContext $context ): string {
		/**
		 * Filters whether the source site URL is replaced with this site's URL in imported content.
		 *
		 * @since 0.1.0
		 *
		 * @param bool   $replace    Whether to replace it. Default true.
		 * @param string $source_url Source site URL.
		 */
		$source_url = (string) $context->get_manifest()->get_source()['site_url'];
		$replace    = (bool) apply_filters( 'selective_entity_sync_replace_site_url', true, $source_url );

		return $this->rewriter->rewrite(
			$content,
			static function ( int $source_id ) use ( $context ) {
				return $context->map_source_id( 'post', $source_id );
			},
			$context->get_url_replacements(),
			$replace ? $source_url : '',
			home_url()
		);
	}
}
