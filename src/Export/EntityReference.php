<?php
/**
 * Entity reference.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Export;

use InvalidArgumentException;

/**
 * Immutable pointer to a local WordPress object: a post (any type, including
 * attachments) or a term.
 */
final class EntityReference {

	public const POST = 'post';

	public const TERM = 'term';

	/**
	 * Object type: 'post' or 'term'.
	 *
	 * @var string
	 */
	private $object_type;

	/**
	 * Object ID.
	 *
	 * @var int
	 */
	private $id;

	/**
	 * Constructor.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $id          Object ID.
	 * @throws InvalidArgumentException When the type or ID is invalid.
	 */
	public function __construct( string $object_type, int $id ) {
		if ( self::POST !== $object_type && self::TERM !== $object_type ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported object type "%s".', esc_html( $object_type ) ) );
		}
		if ( $id < 1 ) {
			throw new InvalidArgumentException( 'Object IDs must be positive.' );
		}

		$this->object_type = $object_type;
		$this->id          = $id;
	}

	/**
	 * Creates a post reference.
	 *
	 * @param int $id Post ID.
	 * @return self
	 */
	public static function post( int $id ): self {
		return new self( self::POST, $id );
	}

	/**
	 * Creates a term reference.
	 *
	 * @param int $id Term ID.
	 * @return self
	 */
	public static function term( int $id ): self {
		return new self( self::TERM, $id );
	}

	/**
	 * Returns the object type.
	 *
	 * @return string
	 */
	public function get_object_type(): string {
		return $this->object_type;
	}

	/**
	 * Returns the object ID.
	 *
	 * @return int
	 */
	public function get_id(): int {
		return $this->id;
	}

	/**
	 * Returns a unique key, e.g. "post:12".
	 *
	 * @return string
	 */
	public function get_key(): string {
		return $this->object_type . ':' . $this->id;
	}
}
