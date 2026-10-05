<?php
/**
 * Per-object permissions.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Support;

/**
 * Checks the current user's rights on individual posts, terms and media.
 *
 * Access to the plugin is gated by Capabilities (manage_options by default,
 * which implies all of the checks below). These finer checks matter when a
 * site lowers that capability, e.g. to let editors sync content: users can
 * then only export what they can read and import what they could create or
 * edit by hand.
 *
 * WP-CLI runs are trusted, like WordPress's own CLI commands, and skip these checks.
 */
class ObjectPermissions {

	/**
	 * Whether checks are bypassed (WP-CLI).
	 *
	 * @return bool
	 */
	public function is_unrestricted(): bool {
		return defined( 'WP_CLI' ) && WP_CLI;
	}

	/**
	 * Whether the current user can read (and so export) a post.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public function can_read_post( int $post_id ): bool {
		return $this->is_unrestricted() || current_user_can( 'read_post', $post_id );
	}

	/**
	 * Whether the current user can create or update a post as imported.
	 *
	 * @param string   $post_type Post type.
	 * @param string   $status    Post status being written.
	 * @param int|null $local_id  Existing post ID, or null when creating.
	 * @param int      $author_id Author being assigned.
	 * @return bool
	 */
	public function can_write_post( string $post_type, string $status, ?int $local_id, int $author_id ): bool {
		if ( $this->is_unrestricted() ) {
			return true;
		}

		$type = get_post_type_object( $post_type );
		if ( null === $type ) {
			return false;
		}

		$can = null !== $local_id ? current_user_can( 'edit_post', $local_id ) : current_user_can( $type->cap->create_posts );

		if ( $can && $author_id > 0 && get_current_user_id() !== $author_id ) {
			$can = current_user_can( $type->cap->edit_others_posts );
		}

		if ( $can && in_array( $status, array( 'publish', 'future', 'private' ), true ) ) {
			$can = current_user_can( $type->cap->publish_posts );
		}

		return $can;
	}

	/**
	 * Whether the current user can create or update a term.
	 *
	 * @param string   $taxonomy Taxonomy.
	 * @param int|null $local_id Existing term ID, or null when creating.
	 * @return bool
	 */
	public function can_write_term( string $taxonomy, ?int $local_id ): bool {
		if ( $this->is_unrestricted() ) {
			return true;
		}

		if ( null !== $local_id ) {
			return current_user_can( 'edit_term', $local_id );
		}

		$object = get_taxonomy( $taxonomy );

		return false !== $object && current_user_can( $object->cap->edit_terms );
	}

	/**
	 * Whether the current user can create or update a media item.
	 *
	 * @param int|null $local_id Existing attachment ID, or null when creating.
	 * @return bool
	 */
	public function can_write_media( ?int $local_id ): bool {
		if ( $this->is_unrestricted() ) {
			return true;
		}

		return current_user_can( 'upload_files' ) && ( null === $local_id || current_user_can( 'edit_post', $local_id ) );
	}
}
