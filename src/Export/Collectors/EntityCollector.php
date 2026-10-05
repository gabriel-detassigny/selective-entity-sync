<?php
/**
 * Entity collector contract.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;

/**
 * Turns a local WordPress object into a manifest entity.
 *
 * Register custom collectors with the `selective_entity_sync_collectors` filter.
 * Entities must reference other entities by UUID (via ExportContext::reference()),
 * never by database ID, except for IDs embedded in content or meta, which are
 * remapped on import using each entity's `source_id`.
 */
interface EntityCollector {

	/**
	 * Whether this collector handles the referenced object.
	 *
	 * @param EntityReference $reference Local object.
	 * @return bool
	 */
	public function supports( EntityReference $reference ): bool;

	/**
	 * Builds the full entity, declaring its dependencies and files on the context.
	 *
	 * @param EntityReference $reference Local object.
	 * @param ExportContext   $context   Export context.
	 * @return array<string, mixed> Entity with at least `uuid`, `type`, `source_id` and `data`.
	 */
	public function collect( EntityReference $reference, ExportContext $context ): array;

	/**
	 * Builds a reference-only entity: just enough to match it on the target site.
	 *
	 * @param EntityReference $reference Local object.
	 * @param ExportContext   $context   Export context.
	 * @return array<string, mixed> Entity with `reference_only` set to true.
	 */
	public function collect_reference( EntityReference $reference, ExportContext $context ): array;
}
