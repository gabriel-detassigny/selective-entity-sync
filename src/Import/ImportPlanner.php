<?php
/**
 * Import planner.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Import\Handlers\ImportHandler;
use SelectiveEntitySync\Manifest\Manifest;
use WP_Post;

/**
 * Matches manifest entities to local objects and decides what to do with each.
 *
 * Matching order: UUID first; then a natural key (slug, or file checksum for
 * media), accepted only if the local object has no UUID yet or the same one,
 * so content synced from elsewhere is never hijacked.
 */
class ImportPlanner {

	/**
	 * UUID service.
	 *
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Default handlers.
	 *
	 * @var ImportHandler[]
	 */
	private $handlers;

	/**
	 * Constructor.
	 *
	 * @param EntityUuid      $uuids    UUID service.
	 * @param ImportHandler[] $handlers Default handlers.
	 */
	public function __construct( EntityUuid $uuids, array $handlers ) {
		$this->uuids    = $uuids;
		$this->handlers = $handlers;
	}

	/**
	 * Builds the plan for a manifest.
	 *
	 * @param Manifest $manifest Manifest.
	 * @return ImportPlan
	 */
	public function plan( Manifest $manifest ): ImportPlan {
		$plan     = new ImportPlan( $manifest );
		$context  = new ImportContext( $manifest );
		$handlers = $this->get_handlers();

		foreach ( $manifest->get_entities() as $entity ) {
			$plan->add_item( $this->plan_entity( $entity, $handlers, $context ) );
		}

		return $plan;
	}

	/**
	 * Returns the handlers, including any registered by add-ons.
	 *
	 * @return ImportHandler[]
	 */
	public function get_handlers(): array {
		/**
		 * Filters the handlers used to import entities.
		 *
		 * The first handler whose supports() returns true imports an entity, so
		 * prepend custom handlers to override the defaults.
		 *
		 * @since 0.1.0
		 *
		 * @param ImportHandler[] $handlers Handlers. Default: post, attachment and term handlers.
		 */
		$handlers = apply_filters( 'selective_entity_sync_import_handlers', $this->handlers );

		if ( ! is_array( $handlers ) ) {
			return $this->handlers;
		}

		return array_values(
			array_filter(
				$handlers,
				static function ( $handler ) {
					return $handler instanceof ImportHandler;
				}
			)
		);
	}

	/**
	 * Returns the first handler supporting an entity.
	 *
	 * @param array<string, mixed> $entity   Manifest entity.
	 * @param ImportHandler[]      $handlers Handlers.
	 * @return ImportHandler|null
	 */
	public function find_handler( array $entity, array $handlers ): ?ImportHandler {
		foreach ( $handlers as $handler ) {
			if ( $handler->supports( $entity ) ) {
				return $handler;
			}
		}

		return null;
	}

	/**
	 * Plans one entity.
	 *
	 * @param array<string, mixed> $entity   Manifest entity.
	 * @param ImportHandler[]      $handlers Handlers.
	 * @param ImportContext        $context  Planning context.
	 * @return array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, action: string, match: string|null, local_id: int|null, reference_only: bool, reason: string|null}
	 */
	private function plan_entity( array $entity, array $handlers, ImportContext $context ): array {
		$reference_only = ! empty( $entity['reference_only'] );
		$data           = $entity['data'];
		$subtype        = (string) ( $data['post_type'] ?? $data['taxonomy'] ?? $data['post_mime_type'] ?? '' );
		$item           = array(
			'uuid'           => (string) $entity['uuid'],
			'type'           => (string) $entity['type'],
			'subtype'        => $subtype,
			'subtype_label'  => $this->get_subtype_label( (string) $entity['type'], $subtype ),
			'title'          => (string) ( $data['post_title'] ?? $data['name'] ?? '' ),
			'action'         => ImportPlan::SKIP,
			'match'          => null,
			'local_id'       => null,
			'reference_only' => $reference_only,
			'reason'         => null,
		);

		$handler = $this->find_handler( $entity, $handlers );
		if ( null === $handler ) {
			$item['reason'] = sprintf(
				/* translators: %s: Entity type. */
				__( 'Entities of type "%s" cannot be imported on this site.', 'selective-entity-sync' ),
				$item['type']
			);
			return $item;
		}

		$error = $handler->get_validation_error( $entity );
		if ( null !== $error ) {
			$item['reason'] = $error;
			return $item;
		}

		$match = $this->match( $entity, $handler, $context );
		if ( null !== $match ) {
			$item['match']    = $match->get_method();
			$item['local_id'] = $match->get_local_id();
		}

		if ( $reference_only ) {
			$item['action'] = null !== $match ? ImportPlan::LINK : ImportPlan::MISSING;
			return $item;
		}

		$action = null !== $match ? ImportPlan::UPDATE : ImportPlan::CREATE;

		/**
		 * Filters what happens to an entity on import.
		 *
		 * @since 0.1.0
		 *
		 * @param string               $action   'create', 'update' or 'skip'. Default: 'update' when a local match exists, 'create' otherwise.
		 * @param array                $entity   Manifest entity.
		 * @param int|null             $local_id ID of the matched local object, if any.
		 */
		$filtered = apply_filters( 'selective_entity_sync_import_action', $action, $entity, $item['local_id'] );

		if ( ImportPlan::SKIP === $filtered || ( ImportPlan::CREATE === $filtered && null === $match ) || ( ImportPlan::UPDATE === $filtered && null !== $match ) ) {
			$action = $filtered;
		}

		$item['action'] = $action;
		if ( ImportPlan::SKIP === $action ) {
			$item['reason'] = __( 'Skipped by a site customization.', 'selective-entity-sync' );
		}

		return $item;
	}

	/**
	 * Finds the local object matching an entity.
	 *
	 * @param array<string, mixed> $entity  Manifest entity.
	 * @param ImportHandler        $handler Handler.
	 * @param ImportContext        $context Planning context.
	 * @return EntityMatch|null
	 */
	private function match( array $entity, ImportHandler $handler, ImportContext $context ): ?EntityMatch {
		$object_type = $handler->get_object_type();
		$match       = null;

		$local_id = $this->uuids->find_object_id( $object_type, (string) $entity['uuid'] );
		if ( null !== $local_id && $this->is_compatible( $entity, $object_type, $local_id ) ) {
			$match = new EntityMatch( $local_id, EntityMatch::BY_UUID );
		}

		if ( null === $match ) {
			$candidate = $handler->find_by_natural_key( $entity, $context );
			if ( null !== $candidate && $this->is_compatible( $entity, $object_type, $candidate->get_local_id() ) ) {
				$local_uuid = $this->uuids->find( $object_type, $candidate->get_local_id() );
				if ( null === $local_uuid || strtolower( (string) $entity['uuid'] ) === $local_uuid ) {
					$match = $candidate;
				}
			}
		}

		/**
		 * Filters the local object an imported entity is matched to.
		 *
		 * Return a local ID to force a match, or null to import the entity as new.
		 *
		 * @since 0.1.0
		 *
		 * @param int|null    $local_id Matched local ID, or null.
		 * @param array       $entity   Manifest entity.
		 * @param string|null $method   How it was matched: 'uuid', 'slug', 'file', or null.
		 */
		$filtered = apply_filters( 'selective_entity_sync_match_existing_entity', null !== $match ? $match->get_local_id() : null, $entity, null !== $match ? $match->get_method() : null );

		if ( null === $filtered ) {
			return null;
		}

		if ( is_int( $filtered ) && $filtered > 0 ) {
			return null !== $match && $match->get_local_id() === $filtered ? $match : new EntityMatch( $filtered, EntityMatch::BY_FILTER );
		}

		return $match;
	}

	/**
	 * Whether a local object can stand for an entity (e.g. media only matches media).
	 *
	 * @param array<string, mixed> $entity      Manifest entity.
	 * @param string               $object_type 'post' or 'term'.
	 * @param int                  $local_id    Local object ID.
	 * @return bool
	 */
	private function is_compatible( array $entity, string $object_type, int $local_id ): bool {
		if ( 'term' === $object_type ) {
			$term = get_term( $local_id );
			return $term instanceof \WP_Term && ( $entity['data']['taxonomy'] ?? '' ) === $term->taxonomy;
		}

		$post = get_post( $local_id );
		if ( ! $post instanceof WP_Post || 'trash' === $post->post_status ) {
			return false;
		}

		return 'attachment' === $entity['type'] ? 'attachment' === $post->post_type : 'attachment' !== $post->post_type;
	}

	/**
	 * Returns a human-readable label for an entity's subtype.
	 *
	 * @param string $type    Entity type.
	 * @param string $subtype Post type, taxonomy or MIME type.
	 * @return string
	 */
	private function get_subtype_label( string $type, string $subtype ): string {
		if ( 'attachment' === $type ) {
			return __( 'Media', 'selective-entity-sync' );
		}

		if ( 'term' === $type ) {
			$taxonomy = get_taxonomy( $subtype );
			return false !== $taxonomy ? (string) $taxonomy->labels->singular_name : $subtype;
		}

		$post_type = get_post_type_object( $subtype );
		return null !== $post_type ? (string) $post_type->labels->singular_name : $subtype;
	}
}
