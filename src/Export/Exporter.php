<?php
/**
 * Exporter.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

use SelectiveEntitySync\Export\Collectors\EntityCollector;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Manifest\Manifest;
use SelectiveEntitySync\Package\PackageWriter;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Support\ObjectPermissions;
use Throwable;
use WP_Post;

/**
 * Builds export packages from a selection of posts.
 *
 * Starting from the selected posts, every dependency (parents, terms,
 * featured images, media and synced patterns used in content, …) is walked
 * breadth-first and exported too. A dependency can be downgraded to a
 * reference-only entity with the `selective_entity_sync_include_dependency`
 * filter, in which case it is matched on the target but never created there.
 */
class Exporter {

	/**
	 * Maximum number of posts that can be selected in one export.
	 */
	public const MAX_SELECTION = 1000;

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * UUID service.
	 *
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Default collectors.
	 *
	 * @var EntityCollector[]
	 */
	private $collectors;

	/**
	 * Package writer.
	 *
	 * @var PackageWriter
	 */
	private $writer;

	/**
	 * Temporary storage.
	 *
	 * @var TempStorage
	 */
	private $storage;

	/**
	 * Plugin version, recorded in the manifest.
	 *
	 * @var string
	 */
	private $plugin_version;

	/**
	 * Per-object permissions.
	 *
	 * @var ObjectPermissions
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @param ExportSettings    $settings       Export settings.
	 * @param EntityUuid        $uuids          UUID service.
	 * @param EntityCollector[] $collectors     Default collectors.
	 * @param PackageWriter     $writer         Package writer.
	 * @param TempStorage       $storage        Temporary storage.
	 * @param string            $plugin_version Plugin version.
	 * @param ObjectPermissions $permissions    Per-object permissions.
	 */
	public function __construct( ExportSettings $settings, EntityUuid $uuids, array $collectors, PackageWriter $writer, TempStorage $storage, string $plugin_version, ObjectPermissions $permissions ) {
		$this->settings       = $settings;
		$this->uuids          = $uuids;
		$this->collectors     = $collectors;
		$this->writer         = $writer;
		$this->storage        = $storage;
		$this->plugin_version = $plugin_version;
		$this->permissions    = $permissions;
	}

	/**
	 * Works out everything an export of the given posts would contain, without writing a package.
	 *
	 * Note that this assigns UUIDs to the entities involved, if they had none.
	 *
	 * @param int[] $post_ids Selected post IDs.
	 * @return ExportPlan
	 * @throws ExportException When the selection is invalid.
	 */
	public function plan( array $post_ids ): ExportPlan {
		$post_ids   = $this->validate_selection( $post_ids );
		$collectors = $this->get_collectors();
		$context    = new ExportContext( $this->uuids );
		$manifest   = new Manifest(
			array(
				'plugin'  => 'selective-entity-sync',
				'version' => $this->plugin_version,
			),
			array(
				'site_url'    => home_url(),
				'wp_version'  => (string) get_bloginfo( 'version' ),
				'exported_at' => gmdate( 'c' ),
			)
		);

		$selected_uuids = array();
		foreach ( $post_ids as $post_id ) {
			$reference = EntityReference::post( $post_id );
			$context->select( $reference );
			$selected_uuids[] = $context->uuid( $reference );
		}

		$reference = $context->next();
		while ( null !== $reference ) {
			$entity = $this->collect( $reference, $collectors, $context );
			if ( null !== $entity ) {
				$manifest->add_entity( $entity );
			}
			$reference = $context->next();
		}

		return new ExportPlan( $manifest, $context->get_files(), $context->get_warnings(), $selected_uuids );
	}

	/**
	 * Exports the given posts and their dependencies to a package in a new temporary directory.
	 *
	 * The caller must delete the result's directory once the package has been delivered.
	 *
	 * @param int[] $post_ids Selected post IDs.
	 * @return ExportResult
	 * @throws ExportException When the selection is invalid or the package cannot be written.
	 */
	public function export( array $post_ids ): ExportResult {
		/**
		 * Fires before an export package is built.
		 *
		 * @since 0.1.0
		 *
		 * @param int[] $post_ids Selected post IDs.
		 */
		do_action( 'selective_entity_sync_before_export', $post_ids );

		$plan      = $this->plan( $post_ids );
		$directory = $this->storage->create_directory();
		$path      = $directory . '/package.zip';

		try {
			$manifest = $this->writer->write( $plan->get_manifest(), $plan->get_files(), $path );
		} catch ( Throwable $e ) {
			$this->storage->delete( $directory );
			throw new ExportException( esc_html( $e->getMessage() ), 0, $e ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- $e is the previous exception, not output.
		}

		$result = new ExportResult( $plan, $manifest, $path, $directory );

		/**
		 * Fires after an export package has been written.
		 *
		 * @since 0.1.0
		 *
		 * @param ExportResult $result The export result: manifest, package path and plan.
		 */
		do_action( 'selective_entity_sync_after_export', $result );

		return $result;
	}

	/**
	 * Builds one entity: full or reference-only.
	 *
	 * @param EntityReference   $reference  Entity to collect.
	 * @param EntityCollector[] $collectors Available collectors.
	 * @param ExportContext     $context    Export context.
	 * @return array<string, mixed>|null
	 */
	private function collect( EntityReference $reference, array $collectors, ExportContext $context ): ?array {
		$collector = $this->find_collector( $reference, $collectors );
		if ( null === $collector ) {
			$context->add_warning(
				sprintf(
					/* translators: %s: Entity type and ID, e.g. "post #12". */
					__( '%s cannot be exported: no exporter supports it.', 'selective-entity-sync' ),
					$context->describe( $reference )
				)
			);
			return null;
		}

		$include = $context->is_selected( $reference ) || $this->should_include( $reference, $context );
		$entity  = $include ? $collector->collect( $reference, $context ) : $collector->collect_reference( $reference, $context );

		/**
		 * Filters an entity before it is added to the export manifest.
		 *
		 * The entity must keep its `uuid`, `type`, `source_id` and `data` keys.
		 *
		 * @since 0.1.0
		 *
		 * @param array           $entity    Entity data.
		 * @param EntityReference $reference Local object the entity was built from.
		 */
		$filtered = apply_filters( 'selective_entity_sync_export_entity_data', $entity, $reference );

		return is_array( $filtered ) ? $filtered : $entity;
	}

	/**
	 * Whether a dependency is bundled in full (true) or only referenced (false).
	 *
	 * @param EntityReference $dependency Dependency.
	 * @param ExportContext   $context    Export context.
	 * @return bool
	 */
	private function should_include( EntityReference $dependency, ExportContext $context ): bool {
		// Content the user can't read is only referenced, never bundled.
		if ( EntityReference::POST === $dependency->get_object_type() && ! $this->permissions->can_read_post( $dependency->get_id() ) ) {
			return false;
		}

		/**
		 * Filters whether a dependency is bundled in the package or only referenced.
		 *
		 * Referenced-only entities are matched on the target site (by UUID, then
		 * by slug) but never created there.
		 *
		 * @since 0.1.0
		 *
		 * @param bool                 $include     Whether to bundle the dependency. Default true.
		 * @param EntityReference      $dependency  The dependency.
		 * @param EntityReference|null $required_by The entity that first required it.
		 */
		return (bool) apply_filters( 'selective_entity_sync_include_dependency', true, $dependency, $context->get_required_by( $dependency ) );
	}

	/**
	 * Returns the collectors, including any registered by add-ons.
	 *
	 * @return EntityCollector[]
	 */
	private function get_collectors(): array {
		/**
		 * Filters the entity collectors used to export content.
		 *
		 * The first collector whose supports() returns true handles an entity, so
		 * prepend custom collectors to override the defaults.
		 *
		 * @since 0.1.0
		 *
		 * @param EntityCollector[] $collectors Collectors. Default: post, attachment and term collectors.
		 */
		$collectors = apply_filters( 'selective_entity_sync_collectors', $this->collectors );

		if ( ! is_array( $collectors ) ) {
			return $this->collectors;
		}

		return array_values(
			array_filter(
				$collectors,
				static function ( $collector ) {
					return $collector instanceof EntityCollector;
				}
			)
		);
	}

	/**
	 * Returns the first collector supporting a reference.
	 *
	 * @param EntityReference   $reference  Entity.
	 * @param EntityCollector[] $collectors Collectors.
	 * @return EntityCollector|null
	 */
	private function find_collector( EntityReference $reference, array $collectors ): ?EntityCollector {
		foreach ( $collectors as $collector ) {
			if ( $collector->supports( $reference ) ) {
				return $collector;
			}
		}

		return null;
	}

	/**
	 * Validates the selection.
	 *
	 * @param int[] $post_ids Selected post IDs.
	 * @return int[] Unique, valid post IDs.
	 * @throws ExportException When the selection is empty, too large, or contains non-exportable posts.
	 */
	private function validate_selection( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_map( 'intval', $post_ids ) ) );

		if ( array() === $post_ids ) {
			throw new ExportException( esc_html__( 'Select at least one item to export.', 'selective-entity-sync' ) );
		}

		if ( count( $post_ids ) > self::MAX_SELECTION ) {
			throw new ExportException(
				sprintf(
					/* translators: %d: Maximum number of items. */
					esc_html__( 'You can export at most %d items at once.', 'selective-entity-sync' ),
					(int) self::MAX_SELECTION
				)
			);
		}

		$post_types = $this->settings->get_exportable_post_types();
		$statuses   = $this->settings->get_exportable_statuses();

		foreach ( $post_ids as $post_id ) {
			$post = $post_id > 0 ? get_post( $post_id ) : null;

			if ( ! $post instanceof WP_Post || ! in_array( $post->post_type, $post_types, true ) || ! in_array( $post->post_status, $statuses, true ) || ! $this->permissions->can_read_post( $post_id ) ) {
				throw new ExportException(
					sprintf(
						/* translators: %d: Post ID. */
						esc_html__( 'Item #%d cannot be exported: it does not exist, its type or status is not exportable, or you are not allowed to export it.', 'selective-entity-sync' ),
						(int) $post_id
					)
				);
			}
		}

		return $post_ids;
	}
}
