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
 * Messages are translated and safe to show to users (after escaping).
 */
class SyncException extends RuntimeException {
}
