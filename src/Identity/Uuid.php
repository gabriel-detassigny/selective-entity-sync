<?php
/**
 * UUID helpers.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Identity;

/**
 * Pure UUID helpers, usable without WordPress loaded.
 */
final class Uuid {

	/**
	 * Whether a string is a canonical (hyphenated, lowercase or uppercase) UUID.
	 *
	 * @param string $uuid Candidate UUID.
	 * @return bool
	 */
	public static function is_valid( string $uuid ): bool {
		return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $uuid );
	}
}
