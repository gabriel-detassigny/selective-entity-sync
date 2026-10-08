<?php
/**
 * Post collector.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

use SelectiveEntitySync\Content\ContentReferenceFinder;
use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;
use SelectiveEntitySync\Export\ExportSettings;
use WP_Post;

/**
 * Exports posts, pages, custom post types and synced patterns (anything but attachments).
 *
 * Dependencies: parent post, terms, entities referenced by meta (e.g. the
 * featured image) and posts referenced by content (media, synced patterns).
 */
class PostCollector implements EntityCollector {

	public const ENTITY_TYPE = 'post';

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * Meta collector.
	 *
	 * @var MetaCollector
	 */
	private $meta;

	/**
	 * Content reference finder.
	 *
	 * @var ContentReferenceFinder
	 */
	private $finder;

	/**
	 * Constructor.
	 *
	 * @param ExportSettings         $settings Export settings.
	 * @param MetaCollector          $meta     Meta collector.
	 * @param ContentReferenceFinder $finder   Content reference finder.
	 */
	public function __construct( ExportSettings $settings, MetaCollector $meta, ContentReferenceFinder $finder ) {
		$this->settings = $settings;
		$this->meta     = $meta;
		$this->finder   = $finder;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( EntityReference $reference ): bool {
		if ( EntityReference::POST !== $reference->get_object_type() ) {
			return false;
		}

		$post = get_post( $reference->get_id() );

		return $post instanceof WP_Post && 'attachment' !== $post->post_type;
	}

	/**
	 * {@inheritDoc}
	 */
	public function collect( EntityReference $reference, ExportContext $context ): array {
		$post = $this->get_post( $reference );

		$entity = array(
			'uuid'      => $context->uuid( $reference ),
			'type'      => self::ENTITY_TYPE,
			'source_id' => $post->ID,
			'data'      => array(
				'post_type'      => $post->post_type,
				'post_status'    => $post->post_status,
				'post_title'     => $post->post_title,
				'post_content'   => $post->post_content,
				'post_excerpt'   => $post->post_excerpt,
				'post_name'      => $post->post_name,
				'post_date'      => $post->post_date,
				'post_date_gmt'  => $post->post_date_gmt,
				'menu_order'     => (int) $post->menu_order,
				'comment_status' => $post->comment_status,
				'ping_status'    => $post->ping_status,
				'post_password'  => $post->post_password,
				'sticky'         => is_sticky( $post->ID ),
			),
			'relations' => array(
				'parent' => $post->post_parent > 0 ? $context->reference( EntityReference::post( $post->post_parent ), $reference ) : null,
				'author' => AuthorInfo::for_user( (int) $post->post_author ),
				'terms'  => $this->collect_terms( $post, $reference, $context ),
			),
			'meta'      => $this->meta->collect( $reference, $context ),
		);

		foreach ( $this->finder->find_post_ids( $post->post_content ) as $post_id ) {
			$context->reference( EntityReference::post( $post_id ), $reference );
		}

		return $entity;
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
				'post_type'  => $post->post_type,
				'post_name'  => $post->post_name,
				'post_title' => $post->post_title,
			),
		);
	}

	/**
	 * Returns the post's terms as UUIDs, by taxonomy.
	 *
	 * @param WP_Post         $post      Post.
	 * @param EntityReference $reference Post reference.
	 * @param ExportContext   $context   Export context.
	 * @return array<string, string[]>
	 */
	private function collect_terms( WP_Post $post, EntityReference $reference, ExportContext $context ): array {
		$terms = array();

		foreach ( $this->settings->get_exportable_taxonomies( $post->post_type ) as $taxonomy ) {
			$post_terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $post_terms ) ) {
				continue;
			}

			foreach ( $post_terms as $term ) {
				$uuid = $context->reference( EntityReference::term( $term->term_id ), $reference );
				if ( null !== $uuid ) {
					$terms[ $taxonomy ][] = $uuid;
				}
			}
		}

		return $terms;
	}

	/**
	 * Loads the post.
	 *
	 * @param EntityReference $reference Post reference.
	 * @return WP_Post
	 * @throws \InvalidArgumentException When the object does not exist.
	 */
	private function get_post( EntityReference $reference ): WP_Post {
		$post = get_post( $reference->get_id() );
		if ( ! $post instanceof WP_Post ) {
			throw new \InvalidArgumentException( sprintf( 'Post %d does not exist.', (int) $reference->get_id() ) );
		}

		return $post;
	}
}
