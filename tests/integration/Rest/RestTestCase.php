<?php
/**
 * Base REST test case.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Tests\Integration\Rest;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * Boots a fresh REST server so the plugin's routes are registered.
 */
abstract class RestTestCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();

		global $wp_rest_server;
		$wp_rest_server = new WP_REST_Server();
		do_action( 'rest_api_init', $wp_rest_server );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;

		parent::tear_down();
	}

	protected function login_as( string $role ): int {
		$user_id = self::factory()->user->create( array( 'role' => $role ) );
		wp_set_current_user( $user_id );

		return $user_id;
	}

	/**
	 * @param array<string, mixed> $params Query (GET) or body (POST) parameters.
	 */
	protected function request( string $method, string $route, array $params = array() ): WP_REST_Response {
		$request = new WP_REST_Request( $method, '/selective-entity-sync/v1' . $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $params );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $params ) );
		}

		return rest_do_request( $request );
	}
}
