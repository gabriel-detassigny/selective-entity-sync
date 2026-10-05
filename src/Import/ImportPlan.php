<?php
/**
 * Import plan.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

use SelectiveEntitySync\Manifest\Manifest;

/**
 * What an import would do with each entity of a manifest, before anything is written.
 *
 * Actions:
 * - `create`: no local match, the entity will be created.
 * - `update`: matched locally, the local object will be overwritten.
 * - `skip`: will not be written (unsupported type, invalid on this site, or skipped by a filter).
 * - `link`: reference-only entity found locally; references to it will point there.
 * - `missing`: reference-only entity not found locally; references to it can't be remapped.
 */
class ImportPlan {

	public const CREATE = 'create';

	public const UPDATE = 'update';

	public const SKIP = 'skip';

	public const LINK = 'link';

	public const MISSING = 'missing';

	/**
	 * Manifest the plan was built from.
	 *
	 * @var Manifest
	 */
	private $manifest;

	/**
	 * Plan items keyed by UUID.
	 *
	 * @var array<string, array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, action: string, match: string|null, local_id: int|null, reference_only: bool, reason: string|null}>
	 */
	private $items = array();

	/**
	 * Constructor.
	 *
	 * @param Manifest $manifest Manifest.
	 */
	public function __construct( Manifest $manifest ) {
		$this->manifest = $manifest;
	}

	/**
	 * Adds an item.
	 *
	 * @param array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, action: string, match: string|null, local_id: int|null, reference_only: bool, reason: string|null} $item Plan item.
	 * @return void
	 */
	public function add_item( array $item ): void {
		$this->items[ $item['uuid'] ] = $item;
	}

	/**
	 * Returns all items, in manifest order.
	 *
	 * @return array<int, array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, action: string, match: string|null, local_id: int|null, reference_only: bool, reason: string|null}>
	 */
	public function get_items(): array {
		return array_values( $this->items );
	}

	/**
	 * Returns one item.
	 *
	 * @param string $uuid Entity UUID.
	 * @return array{uuid: string, type: string, subtype: string, subtype_label: string, title: string, action: string, match: string|null, local_id: int|null, reference_only: bool, reason: string|null}|null
	 */
	public function get_item( string $uuid ): ?array {
		return $this->items[ strtolower( $uuid ) ] ?? null;
	}

	/**
	 * Returns the manifest.
	 *
	 * @return Manifest
	 */
	public function get_manifest(): Manifest {
		return $this->manifest;
	}

	/**
	 * Returns the number of items per action.
	 *
	 * @return array<string, int>
	 */
	public function get_counts(): array {
		$counts = array_fill_keys( array( self::CREATE, self::UPDATE, self::SKIP, self::LINK, self::MISSING ), 0 );
		foreach ( $this->items as $item ) {
			++$counts[ $item['action'] ];
		}

		return $counts;
	}

	/**
	 * Returns the plan as an array for display.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'source' => $this->manifest->get_source(),
			'items'  => $this->get_items(),
			'counts' => $this->get_counts(),
		);
	}
}
