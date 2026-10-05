<?php
/**
 * Author info.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

/**
 * Describes a post author portably. Users are never exported: authors are
 * mapped to existing users on the target by login, then email.
 */
final class AuthorInfo {

	/**
	 * Returns portable author info, or null if the user does not exist.
	 *
	 * @param int $user_id User ID.
	 * @return array{login: string, email: string, display_name: string}|null
	 */
	public static function for_user( int $user_id ): ?array {
		$user = $user_id > 0 ? get_userdata( $user_id ) : false;
		if ( ! $user instanceof \WP_User ) {
			return null;
		}

		return array(
			'login'        => $user->user_login,
			'email'        => $user->user_email,
			'display_name' => $user->display_name,
		);
	}
}
