<?php
/**
 * Entity identity.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Identity;

use InvalidArgumentException;

/**
 * Gives posts (including attachments) and terms a stable UUID.
 *
 * The UUID is stored as meta on the entity and travels with it between sites,
 * so the same entity can be recognised on the target even though its database
 * ID differs. Sites cloned from one another share UUIDs, which is intended:
 * they hold the same entities.
 */
class EntityUuid {

	/**
	 * Meta key holding the UUID.
	 */
	public const META_KEY = '_selective_entity_sync_uuid';

	/**
	 * Supported WordPress object (meta) types.
	 */
	public const OBJECT_TYPES = array( 'post', 'term' );

	/**
	 * Returns an entity's UUID, generating and storing one if it has none yet.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @return string
	 * @throws InvalidArgumentException When the type is unsupported or the object does not exist.
	 */
	public function get( string $object_type, int $object_id ): string {
		$this->assert_object_exists( $object_type, $object_id );

		$uuid = $this->find( $object_type, $object_id );
		if ( null !== $uuid ) {
			return $uuid;
		}

		$uuid = wp_generate_uuid4();
		add_metadata( $object_type, $object_id, self::META_KEY, $uuid, true );

		// Re-read in case a concurrent request stored one first.
		return $this->find( $object_type, $object_id ) ?? $uuid;
	}

	/**
	 * Returns an entity's UUID, or null if it has none.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @return string|null
	 */
	public function find( string $object_type, int $object_id ): ?string {
		$this->assert_supported_type( $object_type );

		$uuid = get_metadata( $object_type, $object_id, self::META_KEY, true );

		return is_string( $uuid ) && Uuid::is_valid( $uuid ) ? strtolower( $uuid ) : null;
	}

	/**
	 * Sets an entity's UUID, e.g. on an entity just created by an import.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param int    $object_id   Post or term ID.
	 * @param string $uuid        UUID to assign.
	 * @return void
	 * @throws InvalidArgumentException When the type, object or UUID is invalid.
	 */
	public function assign( string $object_type, int $object_id, string $uuid ): void {
		$this->assert_object_exists( $object_type, $object_id );

		if ( ! Uuid::is_valid( $uuid ) ) {
			throw new InvalidArgumentException( 'Invalid UUID.' );
		}

		update_metadata( $object_type, $object_id, self::META_KEY, strtolower( $uuid ) );
	}

	/**
	 * Finds the local ID of the entity with a given UUID.
	 *
	 * If several entities share the UUID (e.g. a post duplicated with its meta),
	 * the oldest one (lowest ID) wins.
	 *
	 * @param string $object_type 'post' or 'term'.
	 * @param string $uuid        UUID to look up.
	 * @return int|null
	 */
	public function find_object_id( string $object_type, string $uuid ): ?int {
		$this->assert_supported_type( $object_type );

		if ( ! Uuid::is_valid( $uuid ) ) {
			return null;
		}

		$meta_query = array(
			array(
				'key'   => self::META_KEY,
				'value' => strtolower( $uuid ),
			),
		);

		if ( 'post' === $object_type ) {
			$ids = get_posts(
				array(
					'post_type'              => array_values( get_post_types() ),
					'post_status'            => array_keys( get_post_stati() ),
					'meta_query'             => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- UUID lookup is the identity mechanism; meta_key is indexed and only one row is fetched.
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'posts_per_page'         => 1,
					'fields'                 => 'ids',
					'no_found_rows'          => true,
					'update_post_meta_cache' => false,
					'update_post_term_cache' => false,
				)
			);
		} else {
			$ids = get_terms(
				array(
					'taxonomy'   => array_values( get_taxonomies() ),
					'hide_empty' => false,
					'meta_query' => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- UUID lookup is the identity mechanism; meta_key is indexed and only one row is fetched.
					'orderby'    => 'term_id',
					'order'      => 'ASC',
					'number'     => 1,
					'fields'     => 'ids',
				)
			);
		}

		return is_array( $ids ) && isset( $ids[0] ) ? (int) $ids[0] : null;
	}

	/**
	 * Throws if the object type is not supported.
	 *
	 * @param string $object_type Object type.
	 * @return void
	 * @throws InvalidArgumentException When the type is unsupported.
	 */
	private function assert_supported_type( string $object_type ): void {
		if ( ! in_array( $object_type, self::OBJECT_TYPES, true ) ) {
			throw new InvalidArgumentException( sprintf( 'Unsupported object type "%s".', esc_html( $object_type ) ) );
		}
	}

	/**
	 * Throws if the object does not exist.
	 *
	 * @param string $object_type Object type.
	 * @param int    $object_id   Object ID.
	 * @return void
	 * @throws InvalidArgumentException When the type is unsupported or the object does not exist.
	 */
	private function assert_object_exists( string $object_type, int $object_id ): void {
		$this->assert_supported_type( $object_type );

		$exists = 'post' === $object_type ? null !== get_post( $object_id ) : get_term( $object_id ) instanceof \WP_Term;
		if ( ! $exists ) {
			throw new InvalidArgumentException( sprintf( '%s %d does not exist.', esc_html( ucfirst( $object_type ) ), (int) $object_id ) );
		}
	}
}
