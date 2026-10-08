<?php
/**
 * Base exception.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Exception;

use RuntimeException;

/**
 * Base class for all exceptions thrown by the plugin.
 *
 * Messages are translated and HTML-escaped when thrown (WordPress.org requires escaping exception messages).
 * Use Support\PlainText::from_exception() where they leave PHP as text: REST responses, WP-CLI and hooks.
 */
class SyncException extends RuntimeException {
}
