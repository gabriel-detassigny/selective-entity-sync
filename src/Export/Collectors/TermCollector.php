<?php
/**
 * Term collector.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export\Collectors;

use SelectiveEntitySync\Export\EntityReference;
use SelectiveEntitySync\Export\ExportContext;
use WP_Term;

/**
 * Exports taxonomy terms. Dependencies: parent term and entities referenced by term meta.
 */
class TermCollector implements EntityCollector {

	public const ENTITY_TYPE = 'term';

	/**
	 * Meta collector.
	 *
	 * @var MetaCollector
	 */
	private $meta;

	/**
	 * Constructor.
	 *
	 * @param MetaCollector $meta Meta collector.
	 */
	public function __construct( MetaCollector $meta ) {
		$this->meta = $meta;
	}

	/**
	 * {@inheritDoc}
	 */
	public function supports( EntityReference $reference ): bool {
		return EntityReference::TERM === $reference->get_object_type() && get_term( $reference->get_id() ) instanceof WP_Term;
	}

	/**
	 * {@inheritDoc}
	 */
	public function collect( EntityReference $reference, ExportContext $context ): array {
		$term = $this->get_term( $reference );

		return array(
			'uuid'      => $context->uuid( $reference ),
			'type'      => self::ENTITY_TYPE,
			'source_id' => $term->term_id,
			'data'      => array(
				'taxonomy'    => $term->taxonomy,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'description' => $term->description,
			),
			'relations' => array(
				'parent' => $term->parent > 0 ? $context->reference( EntityReference::term( $term->parent ), $reference ) : null,
			),
			'meta'      => $this->meta->collect( $reference, $context ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function collect_reference( EntityReference $reference, ExportContext $context ): array {
		$term = $this->get_term( $reference );

		return array(
			'uuid'           => $context->uuid( $reference ),
			'type'           => self::ENTITY_TYPE,
			'source_id'      => $term->term_id,
			'reference_only' => true,
			'data'           => array(
				'taxonomy' => $term->taxonomy,
				'slug'     => $term->slug,
				'name'     => $term->name,
			),
		);
	}

	/**
	 * Loads the term.
	 *
	 * @param EntityReference $reference Term reference.
	 * @return WP_Term
	 * @throws \InvalidArgumentException When the object does not exist.
	 */
	private function get_term( EntityReference $reference ): WP_Term {
		$term = get_term( $reference->get_id() );
		if ( ! $term instanceof WP_Term ) {
			throw new \InvalidArgumentException( sprintf( 'Term %d does not exist.', (int) $reference->get_id() ) );
		}

		return $term;
	}
}
