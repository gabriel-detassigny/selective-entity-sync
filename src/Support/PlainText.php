<?php
/**
 * Plain-text exception messages.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Support;

use Throwable;

/**
 * Turns exception messages back into plain text.
 *
 * Plugin exceptions are HTML-escaped when thrown (as WordPress.org requires), so they are safe in HTML. REST
 * responses (rendered as text by the admin app), WP-CLI output and hook arguments need the plain text.
 */
class PlainText {

	/**
	 * Returns an exception's message as plain text.
	 *
	 * @param Throwable $e Exception.
	 * @return string
	 */
	public static function from_exception( Throwable $e ): string {
		return wp_specialchars_decode( $e->getMessage(), ENT_QUOTES );
	}
}
