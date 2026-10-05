<?php
/**
 * Base REST controller.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Rest;

use SelectiveEntitySync\Contracts\Hookable;
use SelectiveEntitySync\Support\Capabilities;
use WP_Error;

/**
 * Shared REST namespace and permission check.
 */
abstract class Controller implements Hookable {

	public const NAMESPACE = 'selective-entity-sync/v1';

	/**
	 * Capability checker.
	 *
	 * @var Capabilities
	 */
	protected $capabilities;

	/**
	 * Constructor.
	 *
	 * @param Capabilities $capabilities Capability checker.
	 */
	public function __construct( Capabilities $capabilities ) {
		$this->capabilities = $capabilities;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the controller's routes.
	 *
	 * @return void
	 */
	abstract public function register_routes(): void;

	/**
	 * Permission callback shared by all routes.
	 *
	 * @return true|WP_Error
	 */
	public function check_permission() {
		if ( $this->capabilities->current_user_can_manage() ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'Sorry, you are not allowed to sync content.', 'selective-entity-sync' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}
}
