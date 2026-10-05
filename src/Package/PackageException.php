<?php
/**
 * Package exception.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Package;

use SelectiveEntitySync\Exception\SyncException;

/**
 * Thrown when a package cannot be written, or is invalid or unsafe to read.
 */
class PackageException extends SyncException {
}
