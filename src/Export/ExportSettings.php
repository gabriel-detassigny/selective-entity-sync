<?php
/**
 * Export settings.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

/**
 * Filterable rules deciding what can be exported and how.
 */
class ExportSettings {

	/**
	 * Post types with an admin UI that are not exportable content.
	 */
	public const NON_CONTENT_POST_TYPES = array(
		'attachment', // Exported as a dependency of the content that uses it.
		'wp_template',
		'wp_template_part',
		'wp_global_styles',
		'wp_navigation',
		'wp_font_family',
		'wp_font_face',
	);

	/**
	 * Statuses that can be exported. Trash and auto-drafts never are.
	 */
	public const DEFAULT_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Meta keys that are never exported, by object type.
	 */
	public const DEFAULT_EXCLUDED_META_KEYS = array(
		'post' => array(
			'_edit_lock',
			'_edit_last',
			'_encloseme',
			'_pingme',
			'_wp_old_slug',
			'_wp_old_date',
			'_wp_desired_post_slug',
			'_wp_trash_meta_status',
			'_wp_trash_meta_time',
			'_wp_trash_meta_comments_status',
			// Attachment file data: regenerated from the file on import.
			'_wp_attached_file',
			'_wp_attachment_metadata',
			'_wp_attachment_backup_sizes',
		),
		'term' => array(),
	);

	/**
	 * Meta keys whose values are IDs of other entities, by object type.
	 * Maps meta key => object type of the referenced entity.
	 */
	public const DEFAULT_ID_REFERENCE_META_KEYS = array(
		'post' => array(
			'_thumbnail_id' => 'post',
		),
		'term' => array(),
	);

	/**
	 * Returns the post types whose content can be selected for export.
	 *
	 * @return string[]
	 */
	public function get_exportable_post_types(): array {
		$post_types = array_values( array_diff( get_post_types( array( 'show_ui' => true ) ), self::NON_CONTENT_POST_TYPES ) );

		/**
		 * Filters the post types whose content can be selected for export.
		 *
		 * Attachments are always exported as dependencies of the content that
		 * uses them, so they are not selectable by default.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $post_types Post type names. Default: post types with an admin UI, minus templates, navigation, styles and fonts.
		 */
		$filtered = apply_filters( 'selective_entity_sync_exportable_post_types', $post_types );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $post_types;
	}

	/**
	 * Returns the post statuses that can be exported.
	 *
	 * @return string[]
	 */
	public function get_exportable_statuses(): array {
		/**
		 * Filters the post statuses that can be exported.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $statuses Status names. Default: publish, future, draft, pending, private.
		 */
		$filtered = apply_filters( 'selective_entity_sync_exportable_statuses', self::DEFAULT_STATUSES );

		$statuses = is_array( $filtered ) ? array_filter( $filtered, 'is_string' ) : self::DEFAULT_STATUSES;

		// Never export trashed content or auto-drafts.
		return array_values( array_diff( $statuses, array( 'trash', 'auto-draft', 'inherit' ) ) );
	}

	/**
	 * Returns the taxonomies whose terms are exported with a post.
	 *
	 * @param string $post_type Post type name.
	 * @return string[]
	 */
	public function get_exportable_taxonomies( string $post_type ): array {
		$taxonomies = get_object_taxonomies( $post_type );

		/**
		 * Filters the taxonomies whose terms are exported with posts of a given type.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $taxonomies Taxonomy names. Default: all taxonomies registered for the post type.
		 * @param string   $post_type  Post type name.
		 */
		$filtered = apply_filters( 'selective_entity_sync_exportable_taxonomies', $taxonomies, $post_type );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $taxonomies;
	}

	/**
	 * Returns the meta keys that are never exported.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @return string[]
	 */
	public function get_excluded_meta_keys( string $object_type ): array {
		$keys = self::DEFAULT_EXCLUDED_META_KEYS[ $object_type ] ?? array();

		/**
		 * Filters the meta keys that are never exported.
		 *
		 * The plugin's own UUID key is always excluded from regular meta.
		 *
		 * @since 0.1.0
		 *
		 * @param string[] $keys        Meta keys.
		 * @param string   $object_type 'post' (including attachments) or 'term'.
		 */
		$filtered = apply_filters( 'selective_entity_sync_excluded_meta_keys', $keys, $object_type );

		return is_array( $filtered ) ? array_values( array_filter( $filtered, 'is_string' ) ) : $keys;
	}

	/**
	 * Returns the meta keys whose values are IDs of other entities.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @return array<string, string> Meta key => referenced object type ('post' or 'term').
	 */
	public function get_id_reference_meta_keys( string $object_type ): array {
		$keys = self::DEFAULT_ID_REFERENCE_META_KEYS[ $object_type ] ?? array();

		/**
		 * Filters the meta keys whose values are IDs of other posts or terms.
		 *
		 * Referenced entities are exported as dependencies, and the IDs are
		 * remapped to the target site's IDs on import. Values may be a single
		 * ID, a comma-separated list, or an array of IDs.
		 *
		 * @since 0.1.0
		 *
		 * @param array<string, string> $keys        Meta key => referenced object type ('post' or 'term'). Default: `_thumbnail_id` => 'post'.
		 * @param string                $object_type Object type owning the meta: 'post' or 'term'.
		 */
		$filtered = apply_filters( 'selective_entity_sync_id_reference_meta_keys', $keys, $object_type );

		if ( ! is_array( $filtered ) ) {
			return $keys;
		}

		return array_filter(
			$filtered,
			static function ( $type, $key ) {
				return is_string( $key ) && in_array( $type, array( EntityReference::POST, EntityReference::TERM ), true );
			},
			ARRAY_FILTER_USE_BOTH
		);
	}
}
