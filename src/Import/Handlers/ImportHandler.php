<?php
/**
 * Import handler contract.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import\Handlers;

use SelectiveEntitySync\Import\EntityMatch;
use SelectiveEntitySync\Import\ImportContext;

/**
 * Writes one type of manifest entity to the local site.
 *
 * Register custom handlers with the `selective_entity_sync_import_handlers` filter.
 */
interface ImportHandler {

	/**
	 * Whether this handler imports the entity.
	 *
	 * @param array<string, mixed> $entity Manifest entity.
	 * @return bool
	 */
	public function supports( array $entity ): bool;

	/**
	 * Returns the WordPress object type the entity is stored as: 'post' or 'term'.
	 *
	 * @return string
	 */
	public function get_object_type(): string;

	/**
	 * Returns why the entity cannot be imported on this site (e.g. unregistered post type), or null if it can.
	 *
	 * @param array<string, mixed> $entity Manifest entity.
	 * @return string|null Translated reason.
	 */
	public function get_validation_error( array $entity ): ?string;

	/**
	 * Finds an existing local object by natural key (slug, file, …) when no UUID matched.
	 *
	 * @param array<string, mixed> $entity  Manifest entity.
	 * @param ImportContext        $context Import context.
	 * @return EntityMatch|null
	 */
	public function find_by_natural_key( array $entity, ImportContext $context ): ?EntityMatch;

	/**
	 * Returns the UUIDs of entities that should be written before this one.
	 *
	 * @param array<string, mixed> $entity  Manifest entity.
	 * @param ImportContext        $context Import context.
	 * @return string[]
	 */
	public function get_dependencies( array $entity, ImportContext $context ): array;

	/**
	 * Creates or updates the local object.
	 *
	 * @param array<string, mixed> $entity   Manifest entity.
	 * @param int|null             $local_id Existing local ID to update, or null to create.
	 * @param ImportContext        $context  Import context.
	 * @return int Local ID.
	 * @throws \SelectiveEntitySync\Import\ImportException When the object cannot be written.
	 */
	public function import( array $entity, ?int $local_id, ImportContext $context ): int;
}
