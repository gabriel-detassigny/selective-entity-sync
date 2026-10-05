<?php
/**
 * Import batch limits.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Import;

/**
 * How much work one import request may do before handing back to the browser.
 */
class BatchLimits {

	/**
	 * Defaults: whichever comes first.
	 *
	 * @var array{max_entities: int, max_seconds: int}
	 */
	public const DEFAULTS = array(
		'max_entities' => 25,
		'max_seconds'  => 15,
	);

	/**
	 * Returns the effective limits.
	 *
	 * @return array{max_entities: int, max_seconds: int}
	 */
	public function get(): array {
		/**
		 * Filters how much one import request processes before continuing in the next one.
		 *
		 * A batch stops after `max_entities` entities or `max_seconds` seconds,
		 * whichever comes first (at least one entity is always processed).
		 * Lower them for hosts with short time limits; WP-CLI imports are not batched.
		 *
		 * @since 0.1.0
		 *
		 * @param array $limits {
		 *     @type int $max_entities Entities per request. Default 25.
		 *     @type int $max_seconds  Seconds per request. Default 15.
		 * }
		 */
		$filtered = apply_filters( 'selective_entity_sync_import_batch_limits', self::DEFAULTS );

		$limits = self::DEFAULTS;
		if ( is_array( $filtered ) ) {
			foreach ( array_keys( self::DEFAULTS ) as $key ) {
				if ( isset( $filtered[ $key ] ) && is_int( $filtered[ $key ] ) && $filtered[ $key ] > 0 ) {
					$limits[ $key ] = $filtered[ $key ];
				}
			}
		}

		return $limits;
	}
}
