<?php
/**
 * Author resolver.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

/**
 * Maps exported author info to a local user. Users are never created.
 */
class AuthorResolver {

	/**
	 * Returns the local user ID to use as author.
	 *
	 * Matches by login, then email. Without a match, an existing post keeps its
	 * author and a new post is attributed to the importing user.
	 *
	 * @param array<string, string>|null $author      Exported author info (login, email, display_name), or null.
	 * @param int                        $current_id  Current author of the local post, or 0 for a new post.
	 * @return int
	 */
	public function resolve( ?array $author, int $current_id ): int {
		$user_id = 0;

		if ( is_array( $author ) ) {
			$user = ! empty( $author['login'] ) ? get_user_by( 'login', (string) $author['login'] ) : false;
			if ( ! $user instanceof \WP_User && ! empty( $author['email'] ) ) {
				$user = get_user_by( 'email', (string) $author['email'] );
			}
			$user_id = $user instanceof \WP_User ? $user->ID : 0;
		}

		if ( 0 === $user_id ) {
			$user_id = $current_id > 0 ? $current_id : get_current_user_id();
		}

		/**
		 * Filters the local user an imported post is attributed to.
		 *
		 * @since 0.1.0
		 *
		 * @param int        $user_id    Resolved local user ID (0 for none).
		 * @param array|null $author     Exported author info: login, email, display_name.
		 * @param int        $current_id Current author of the local post (0 for a new post).
		 */
		$filtered = apply_filters( 'selective_entity_sync_import_author', $user_id, $author, $current_id );

		return is_int( $filtered ) && $filtered >= 0 ? $filtered : $user_id;
	}
}
