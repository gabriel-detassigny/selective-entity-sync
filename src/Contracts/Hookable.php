<?php
/**
 * Hookable contract.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Contracts;

/**
 * A service that registers WordPress actions and/or filters.
 *
 * Services only add hooks from register_hooks(), never from their constructor,
 * so they can be built and unit tested without side effects.
 */
interface Hookable {

	/**
	 * Registers the service's actions and filters.
	 *
	 * @return void
	 */
	public function register_hooks(): void;
}
