<?php
/**
 * Optional handler contract for matched entities that are not written.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import\Handlers;

use SelectiveEntitySync\Import\ImportContext;

/**
 * Implemented by handlers that need to prepare entities which already exist
 * locally but are not written by this import (reference-only or skipped),
 * e.g. to register media URL replacements.
 */
interface LinksExistingEntities {

	/**
	 * Prepares an existing local object that is not being written.
	 *
	 * @param array<string, mixed> $entity   Manifest entity.
	 * @param int                  $local_id Local object ID.
	 * @param ImportContext        $context  Import context.
	 * @return void
	 */
	public function link( array $entity, int $local_id, ImportContext $context ): void;
}
