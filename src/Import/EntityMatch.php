<?php
/**
 * Entity match.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

/**
 * An existing local object matched to an imported entity.
 */
final class EntityMatch {

	public const BY_UUID = 'uuid';

	public const BY_SLUG = 'slug';

	public const BY_FILE = 'file';

	public const BY_FILTER = 'filter';

	/**
	 * Local object ID.
	 *
	 * @var int
	 */
	private $local_id;

	/**
	 * How the match was made: one of the BY_* constants.
	 *
	 * @var string
	 */
	private $method;

	/**
	 * Constructor.
	 *
	 * @param int    $local_id Local object ID.
	 * @param string $method   How the match was made.
	 */
	public function __construct( int $local_id, string $method ) {
		$this->local_id = $local_id;
		$this->method   = $method;
	}

	/**
	 * Returns the local object ID.
	 *
	 * @return int
	 */
	public function get_local_id(): int {
		return $this->local_id;
	}

	/**
	 * Returns how the match was made.
	 *
	 * @return string
	 */
	public function get_method(): string {
		return $this->method;
	}
}
