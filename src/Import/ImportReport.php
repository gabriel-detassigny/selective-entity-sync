<?php
/**
 * Import report.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

/**
 * What an import did with each entity.
 *
 * Results: `created`, `updated`, `skipped`, `linked`, `missing`, `failed`.
 */
class ImportReport {

	public const CREATED = 'created';

	public const UPDATED = 'updated';

	public const SKIPPED = 'skipped';

	public const LINKED = 'linked';

	public const MISSING = 'missing';

	public const FAILED = 'failed';

	/**
	 * Results keyed by UUID.
	 *
	 * @var array<string, array{uuid: string, type: string, subtype_label: string, title: string, result: string, local_id: int|null, edit_link: string, message: string|null}>
	 */
	private $items = array();

	/**
	 * Warnings for the user.
	 *
	 * @var string[]
	 */
	private $warnings = array();

	/**
	 * Records the result for an entity.
	 *
	 * @param array<string, mixed> $plan_item Plan item (see ImportPlan).
	 * @param string               $result    One of the result constants.
	 * @param int|null             $local_id  Local ID, if any.
	 * @param string|null          $message   Reason or error message.
	 * @return void
	 */
	public function record( array $plan_item, string $result, ?int $local_id, ?string $message = null ): void {
		$this->items[ (string) $plan_item['uuid'] ] = array(
			'uuid'          => (string) $plan_item['uuid'],
			'type'          => (string) $plan_item['type'],
			'subtype_label' => (string) $plan_item['subtype_label'],
			'title'         => (string) $plan_item['title'],
			'result'        => $result,
			'local_id'      => $local_id,
			'edit_link'     => null !== $local_id ? $this->get_edit_link( (string) $plan_item['type'], (string) $plan_item['subtype'], $local_id ) : '',
			'message'       => $message,
		);
	}

	/**
	 * Adds warnings.
	 *
	 * @param string[] $warnings Translated messages.
	 * @return void
	 */
	public function add_warnings( array $warnings ): void {
		$this->warnings = array_merge( $this->warnings, $warnings );
	}

	/**
	 * Returns the result for one entity.
	 *
	 * @param string $uuid Entity UUID.
	 * @return array{uuid: string, type: string, subtype_label: string, title: string, result: string, local_id: int|null, edit_link: string, message: string|null}|null
	 */
	public function get_item( string $uuid ): ?array {
		return $this->items[ strtolower( $uuid ) ] ?? null;
	}

	/**
	 * Returns all results.
	 *
	 * @return array<int, array{uuid: string, type: string, subtype_label: string, title: string, result: string, local_id: int|null, edit_link: string, message: string|null}>
	 */
	public function get_items(): array {
		return array_values( $this->items );
	}

	/**
	 * Returns the warnings.
	 *
	 * @return string[]
	 */
	public function get_warnings(): array {
		return $this->warnings;
	}

	/**
	 * Returns the number of entities per result.
	 *
	 * @return array<string, int>
	 */
	public function get_counts(): array {
		$counts = array_fill_keys( array( self::CREATED, self::UPDATED, self::SKIPPED, self::LINKED, self::MISSING, self::FAILED ), 0 );
		foreach ( $this->items as $item ) {
			++$counts[ $item['result'] ];
		}

		return $counts;
	}

	/**
	 * Whether any entity failed.
	 *
	 * @return bool
	 */
	public function has_failures(): bool {
		return $this->get_counts()[ self::FAILED ] > 0;
	}

	/**
	 * Returns the report as an array for display.
	 *
	 * @return array{items: array<int, array<string, mixed>>, counts: array<string, int>, warnings: string[]}
	 */
	public function to_array(): array {
		return array(
			'items'    => $this->get_items(),
			'counts'   => $this->get_counts(),
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Returns the state needed to resume the report in another request.
	 *
	 * @return array{items: array<string, array<string, mixed>>, warnings: string[]}
	 */
	public function get_state(): array {
		return array(
			'items'    => $this->items,
			'warnings' => $this->warnings,
		);
	}

	/**
	 * Restores state saved by get_state().
	 *
	 * @param array<string, mixed> $state Saved state.
	 * @return void
	 */
	public function restore_state( array $state ): void {
		$this->items    = (array) ( $state['items'] ?? array() );
		$this->warnings = array_map( 'strval', (array) ( $state['warnings'] ?? array() ) );
	}

	/**
	 * Returns the admin edit link of a local object.
	 *
	 * @param string $type     Entity type.
	 * @param string $subtype  Taxonomy for terms.
	 * @param int    $local_id Local ID.
	 * @return string
	 */
	private function get_edit_link( string $type, string $subtype, int $local_id ): string {
		if ( 'term' === $type ) {
			$link = get_edit_term_link( $local_id, $subtype );
			return is_string( $link ) ? $link : '';
		}

		return (string) get_edit_post_link( $local_id, 'raw' );
	}
}
