<?php
/**
 * Importer.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\Handlers\ImportHandler;
use SelectiveEntitySync\Import\Handlers\LinksExistingEntities;
use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Package\Package;
use Throwable;

/**
 * Imports an extracted package.
 *
 * 1. Plan: match every entity to a local object (see ImportPlanner).
 * 2. Record the local IDs of all matched entities, so references to them
 *    resolve immediately.
 * 3. Write entities in dependency order (terms and media before the posts
 *    using them, parents before children), recording each new local ID.
 * 4. Write again the few entities that referenced something written after
 *    them (dependency cycles), now that every ID is known.
 *
 * A failure on one entity is reported and does not stop the others.
 */
class Importer {

	/**
	 * Planner.
	 *
	 * @var ImportPlanner
	 */
	private $planner;

	/**
	 * UUID service.
	 *
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Constructor.
	 *
	 * @param ImportPlanner $planner Planner.
	 * @param EntityUuid    $uuids   UUID service.
	 */
	public function __construct( ImportPlanner $planner, EntityUuid $uuids ) {
		$this->planner = $planner;
		$this->uuids   = $uuids;
	}

	/**
	 * Plans an import without writing anything.
	 *
	 * @param Manifest $manifest Manifest.
	 * @return ImportPlan
	 */
	public function plan( Manifest $manifest ): ImportPlan {
		return $this->planner->plan( $manifest );
	}

	/**
	 * Imports a package.
	 *
	 * @param Package  $package    Extracted, validated package.
	 * @param string[] $skip_uuids UUIDs of entities not to write (deselected by the user).
	 * @return ImportReport
	 */
	public function import( Package $package, array $skip_uuids = array() ): ImportReport {
		$manifest = $package->get_manifest();

		/**
		 * Fires before a package is imported.
		 *
		 * @since 0.1.0
		 *
		 * @param Manifest $manifest The manifest being imported.
		 */
		do_action( 'selective_entity_sync_before_import', $manifest );

		$plan     = $this->planner->plan( $manifest );
		$handlers = $this->planner->get_handlers();
		$context  = new ImportContext( $manifest, $package );
		$report   = new ImportReport();
		$skip     = array_fill_keys( array_map( 'strtolower', $skip_uuids ), true );
		$to_write = array();

		foreach ( $plan->get_items() as $item ) {
			if ( null !== $item['local_id'] ) {
				$context->set_local_id( $item['uuid'], $item['local_id'] );
			}

			$writable = ImportPlan::CREATE === $item['action'] || ImportPlan::UPDATE === $item['action'];
			if ( $writable && ! isset( $skip[ $item['uuid'] ] ) ) {
				$to_write[ $item['uuid'] ] = $item;
				continue;
			}

			$this->record_unwritten( $item, $writable, $manifest, $handlers, $context, $report );
		}

		$context->set_pending( array_keys( $to_write ) );

		$written = array();
		foreach ( $this->sort( $to_write, $manifest, $handlers, $context ) as $uuid ) {
			if ( $this->write( $to_write[ $uuid ], $manifest, $handlers, $context, $report ) ) {
				$written[] = $uuid;
			}
		}

		foreach ( $written as $uuid ) {
			if ( $context->needs_fixup( $uuid ) ) {
				$context->clear_fixup( $uuid );
				$this->write( $to_write[ $uuid ], $manifest, $handlers, $context, $report, true );
			}
		}

		$report->add_warnings( $context->get_warnings() );

		/**
		 * Fires after a package has been imported.
		 *
		 * @since 0.1.0
		 *
		 * @param ImportReport $report What happened to each entity.
		 * @param Manifest     $manifest The imported manifest.
		 */
		do_action( 'selective_entity_sync_after_import', $report, $manifest );

		return $report;
	}

	/**
	 * Records entities that are not written: skipped, linked or missing.
	 *
	 * @param array<string, mixed> $item     Plan item.
	 * @param bool                 $writable Whether it would have been written but was deselected.
	 * @param Manifest             $manifest Manifest.
	 * @param ImportHandler[]      $handlers Handlers.
	 * @param ImportContext        $context  Import context.
	 * @param ImportReport         $report   Report.
	 * @return void
	 */
	private function record_unwritten( array $item, bool $writable, Manifest $manifest, array $handlers, ImportContext $context, ImportReport $report ): void {
		$entity  = $manifest->get_entity( $item['uuid'] );
		$handler = null !== $entity ? $this->planner->find_handler( $entity, $handlers ) : null;

		if ( null !== $entity && null !== $item['local_id'] && $handler instanceof LinksExistingEntities ) {
			$handler->link( $entity, $item['local_id'], $context );
		}

		switch ( $item['action'] ) {
			case ImportPlan::LINK:
				$report->record( $item, ImportReport::LINKED, $item['local_id'] );
				break;
			case ImportPlan::MISSING:
				$report->record( $item, ImportReport::MISSING, null, __( 'Not found on this site: references to it will not be remapped.', 'selective-entity-sync' ) );
				break;
			default:
				$report->record( $item, ImportReport::SKIPPED, $item['local_id'], $writable ? __( 'Deselected.', 'selective-entity-sync' ) : $item['reason'] );
		}
	}

	/**
	 * Writes one entity.
	 *
	 * @param array<string, mixed> $item     Plan item.
	 * @param Manifest             $manifest Manifest.
	 * @param ImportHandler[]      $handlers Handlers.
	 * @param ImportContext        $context  Import context.
	 * @param ImportReport         $report   Report.
	 * @param bool                 $is_fixup Whether this is a second write to resolve references.
	 * @return bool Whether it was written.
	 */
	private function write( array $item, Manifest $manifest, array $handlers, ImportContext $context, ImportReport $report, bool $is_fixup = false ): bool {
		$entity  = $manifest->get_entity( $item['uuid'] );
		$handler = null !== $entity ? $this->planner->find_handler( $entity, $handlers ) : null;
		if ( null === $entity || null === $handler ) {
			return false;
		}

		$local_id = $context->peek_local_id( $item['uuid'] );
		$result   = $is_fixup || null !== $item['local_id'] ? ImportReport::UPDATED : ImportReport::CREATED;

		/**
		 * Filters an entity just before it is written.
		 *
		 * @since 0.1.0
		 *
		 * @param array    $entity   Manifest entity.
		 * @param int|null $local_id Local ID being updated, or null when creating.
		 */
		$filtered = apply_filters( 'selective_entity_sync_import_entity_data', $entity, $local_id );
		$entity   = is_array( $filtered ) ? $filtered : $entity;

		$context->set_current( $item['uuid'] );

		try {
			$new_id = $handler->import( $entity, $local_id, $context );
			$this->uuids->assign( $handler->get_object_type(), $new_id, $item['uuid'] );
			$context->set_local_id( $item['uuid'], $new_id );
		} catch ( Throwable $e ) {
			$context->set_current( null );
			$report->record( $item, ImportReport::FAILED, $local_id, $e->getMessage() );

			/**
			 * Fires when an entity fails to import. The import continues with the other entities.
			 *
			 * @since 0.1.0
			 *
			 * @param array  $entity  Manifest entity.
			 * @param string $message Error message.
			 */
			do_action( 'selective_entity_sync_import_failed', $entity, $e->getMessage() );

			return false;
		}

		$context->set_current( null );

		if ( ! $is_fixup ) {
			$report->record( $item, $result, $new_id );

			/**
			 * Fires after an entity has been written.
			 *
			 * @since 0.1.0
			 *
			 * @param array  $entity   Manifest entity.
			 * @param int    $local_id Local ID (post or term ID).
			 * @param string $result   'created' or 'updated'.
			 */
			do_action( 'selective_entity_sync_entity_imported', $entity, $new_id, $result );
		}

		return true;
	}

	/**
	 * Orders entities so dependencies are written first (Kahn's algorithm).
	 *
	 * Entities caught in a dependency cycle are appended in manifest order;
	 * their unresolved references are fixed in a second pass.
	 *
	 * @param array<string, array<string, mixed>> $to_write Plan items to write, keyed by UUID.
	 * @param Manifest                            $manifest Manifest.
	 * @param ImportHandler[]                     $handlers Handlers.
	 * @param ImportContext                       $context  Import context.
	 * @return string[] UUIDs in write order.
	 */
	private function sort( array $to_write, Manifest $manifest, array $handlers, ImportContext $context ): array {
		$dependencies = array();
		$dependents   = array();

		foreach ( array_keys( $to_write ) as $uuid ) {
			$entity  = $manifest->get_entity( $uuid );
			$handler = null !== $entity ? $this->planner->find_handler( $entity, $handlers ) : null;
			$deps    = null !== $handler && null !== $entity ? $handler->get_dependencies( $entity, $context ) : array();

			$dependencies[ $uuid ] = array();
			foreach ( $deps as $dependency ) {
				$dependency = strtolower( $dependency );
				if ( $dependency !== $uuid && isset( $to_write[ $dependency ] ) ) {
					$dependencies[ $uuid ][ $dependency ] = true;
					$dependents[ $dependency ][]          = $uuid;
				}
			}
		}

		$order = array();
		$ready = array_keys(
			array_filter(
				$dependencies,
				static function ( array $deps ) {
					return array() === $deps;
				}
			)
		);

		while ( array() !== $ready ) {
			$uuid    = array_shift( $ready );
			$order[] = $uuid;

			foreach ( $dependents[ $uuid ] ?? array() as $dependent ) {
				unset( $dependencies[ $dependent ][ $uuid ] );
				if ( array() === $dependencies[ $dependent ] && ! in_array( $dependent, $order, true ) && ! in_array( $dependent, $ready, true ) ) {
					$ready[] = $dependent;
				}
			}
		}

		foreach ( array_keys( $to_write ) as $uuid ) {
			if ( ! in_array( $uuid, $order, true ) ) {
				$order[] = $uuid;
			}
		}

		return $order;
	}
}
