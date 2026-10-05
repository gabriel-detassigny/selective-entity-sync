<?php
/**
 * Capability checks.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Support;

/**
 * Central place for deciding who can use the plugin.
 */
class Capabilities {

	/**
	 * Default capability required to export and import content.
	 */
	public const DEFAULT_CAPABILITY = 'manage_options';

	/**
	 * Returns the capability required to use the plugin.
	 *
	 * @return string
	 */
	public function get_capability(): string {
		/**
		 * Filters the capability required to export and import content.
		 *
		 * @since 0.1.0
		 *
		 * @param string $capability Capability name. Default 'manage_options'.
		 */
		$capability = apply_filters( 'selective_entity_sync_capability', self::DEFAULT_CAPABILITY );

		return is_string( $capability ) && '' !== $capability ? $capability : self::DEFAULT_CAPABILITY;
	}

	/**
	 * Whether the current user can use the plugin.
	 *
	 * @return bool
	 */
	public function current_user_can_manage(): bool {
		return current_user_can( $this->get_capability() );
	}
}
