<?php
/**
 * Term import handler.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import\Handlers;

use SelectiveEntitySync\Import\EntityMatch;
use SelectiveEntitySync\Import\ImportContext;
use SelectiveEntitySync\Import\ImportException;
use SelectiveEntitySync\Import\MetaImporter;
use SelectiveEntitySync\Support\ObjectPermissions;
use WP_Error;
use WP_Term;

/**
 * Imports taxonomy terms.
 */
class TermHandler implements ImportHandler {

	/**
	 * Meta importer.
	 *
	 * @var MetaImporter
	 */
	private $meta;

	/**
	 * Per-object permissions.
	 *
	 * @var ObjectPermissions
	 */
	private $permissions;

	/**
	 * Constructor.
	 *
	 * @param MetaImporter      $meta        Meta importer.
	 * @param ObjectPermissions $permissions Per-object permissions.
	 */
	public function __construct( MetaImporter $meta, ObjectPermissions $permissions ) {
		$this->meta        = $meta;
		$this->permissions = $permissions;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( array $entity ): bool {
		return 'term' === $entity['type'];
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_object_type(): string {
		return 'term';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_validation_error( array $entity ): ?string {
		$taxonomy = (string) ( $entity['data']['taxonomy'] ?? '' );

		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return sprintf(
				/* translators: %s: Taxonomy name. */
				__( 'The taxonomy "%s" is not registered on this site.', 'selective-entity-sync' ),
				$taxonomy
			);
		}

		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function find_by_natural_key( array $entity, ImportContext $context ): ?EntityMatch {
		$slug = (string) ( $entity['data']['slug'] ?? '' );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, (string) $entity['data']['taxonomy'] ) : false;

		return $term instanceof WP_Term ? new EntityMatch( $term->term_id, EntityMatch::BY_SLUG ) : null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_dependencies( array $entity, ImportContext $context ): array {
		return ! empty( $entity['relations']['parent'] ) ? array( (string) $entity['relations']['parent'] ) : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @throws ImportException When the object cannot be saved.
	 */
	public function import( array $entity, ?int $local_id, ImportContext $context ): int {
		$data     = $entity['data'];
		$taxonomy = (string) $data['taxonomy'];
		$args     = array(
			'slug'        => (string) ( $data['slug'] ?? '' ),
			'description' => (string) ( $data['description'] ?? '' ),
			'parent'      => $context->get_local_id( $entity['relations']['parent'] ?? null ) ?? 0,
		);

		if ( ! $this->permissions->can_write_term( $taxonomy, $local_id ) ) {
			throw new ImportException(
				sprintf(
					/* translators: %s: Term name. */
					__( 'You are not allowed to create or edit the term "%s".', 'selective-entity-sync' ),
					(string) $data['name']
				)
			);
		}

		if ( null !== $local_id ) {
			$args['name'] = (string) $data['name'];
			$result       = wp_update_term( $local_id, $taxonomy, wp_slash( $args ) );
		} else {
			$result = wp_insert_term( wp_slash( (string) $data['name'] ), $taxonomy, wp_slash( $args ) );
		}

		if ( $result instanceof WP_Error ) {
			throw new ImportException(
				sprintf(
					/* translators: 1: Term name, 2: Error message. */
					__( 'The term "%1$s" could not be saved: %2$s', 'selective-entity-sync' ),
					(string) $data['name'],
					$result->get_error_message()
				)
			);
		}

		$term_id = (int) $result['term_id'];
		$this->meta->import( 'term', $term_id, $entity, $context );

		return $term_id;
	}
}
