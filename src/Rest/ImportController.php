<?php
/**
 * Import REST controller.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Rest;

use SelectiveEntitySync\Exception\SyncException;
use SelectiveEntitySync\Import\Importer;
use SelectiveEntitySync\Import\PackageStore;
use SelectiveEntitySync\Package\PackageReader;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Import routes:
 *
 * - POST   /import/packages: upload a package (multipart field `package`); returns a token and the import plan.
 * - GET    /import/packages/{token}: the import plan for an uploaded package.
 * - POST   /import/packages/{token}/import: run the import (`skip`: UUIDs not to write); returns the report.
 * - DELETE /import/packages/{token}: discard an uploaded package.
 */
class ImportController extends Controller {

	private const TOKEN_PATTERN = '(?P<token>[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})';

	/**
	 * Uploaded package store.
	 *
	 * @var PackageStore
	 */
	private $store;

	/**
	 * Package reader.
	 *
	 * @var PackageReader
	 */
	private $reader;

	/**
	 * Importer.
	 *
	 * @var Importer
	 */
	private $importer;

	/**
	 * Temporary storage.
	 *
	 * @var TempStorage
	 */
	private $storage;

	/**
	 * Constructor.
	 *
	 * @param Capabilities  $capabilities Capability checker.
	 * @param PackageStore  $store        Uploaded package store.
	 * @param PackageReader $reader       Package reader.
	 * @param Importer      $importer     Importer.
	 * @param TempStorage   $storage      Temporary storage.
	 */
	public function __construct( Capabilities $capabilities, PackageStore $store, PackageReader $reader, Importer $importer, TempStorage $storage ) {
		parent::__construct( $capabilities );
		$this->store    = $store;
		$this->reader   = $reader;
		$this->importer = $importer;
		$this->storage  = $storage;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/import/packages',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/packages/' . self::TOKEN_PATTERN,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_plan' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'discard' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/import/packages/' . self::TOKEN_PATTERN . '/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'skip' => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array(
							'type'   => 'string',
							'format' => 'uuid',
						),
					),
				),
			)
		);
	}

	/**
	 * Uploads a package and returns its import plan.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( WP_REST_Request $request ) {
		$files = $request->get_file_params();
		if ( empty( $files['package'] ) || ! is_array( $files['package'] ) ) {
			return new WP_Error( 'selective_entity_sync_no_package', __( 'Please choose a package file to upload.', 'selective-entity-sync' ), array( 'status' => 400 ) );
		}

		try {
			$token = $this->store->store_upload( $files['package'] );
		} catch ( SyncException $e ) {
			return $this->error( $e );
		}

		$response = $this->plan_response( $token );
		if ( $response instanceof WP_Error ) {
			$this->store->delete( $token );
		}

		return $response;
	}

	/**
	 * Returns the import plan of an uploaded package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_plan( WP_REST_Request $request ) {
		return $this->plan_response( (string) $request['token'] );
	}

	/**
	 * Imports an uploaded package and deletes it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		$path  = $this->store->get_path( $token );
		if ( null === $path ) {
			return $this->not_found();
		}

		$directory = null;
		try {
			$directory = $this->storage->create_directory();
			$package   = $this->reader->read( $path, $directory );
			$report    = $this->importer->import( $package, array_map( 'strval', (array) $request['skip'] ) );
		} catch ( SyncException $e ) {
			return $this->error( $e );
		} finally {
			if ( null !== $directory ) {
				$this->storage->delete( $directory );
			}
		}

		$this->store->delete( $token );

		return new WP_REST_Response( $report->to_array() );
	}

	/**
	 * Discards an uploaded package.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function discard( WP_REST_Request $request ) {
		$token = (string) $request['token'];
		if ( null === $this->store->get_path( $token ) ) {
			return $this->not_found();
		}

		$this->store->delete( $token );

		return new WP_REST_Response( array( 'deleted' => true ) );
	}

	/**
	 * Builds the plan response for a stored package.
	 *
	 * @param string $token Package token.
	 * @return WP_REST_Response|WP_Error
	 */
	private function plan_response( string $token ) {
		$path = $this->store->get_path( $token );
		if ( null === $path ) {
			return $this->not_found();
		}

		try {
			$plan = $this->importer->plan( $this->reader->read_manifest( $path ) );
		} catch ( SyncException $e ) {
			return $this->error( $e );
		}

		$info = $this->store->get_info( $token );

		return new WP_REST_Response(
			array_merge(
				array(
					'token'    => $token,
					'filename' => null !== $info ? $info['filename'] : '',
				),
				$plan->to_array()
			)
		);
	}

	/**
	 * Returns a "package not found" error.
	 *
	 * @return WP_Error
	 */
	private function not_found(): WP_Error {
		return new WP_Error( 'selective_entity_sync_package_not_found', __( 'This package was not found. It may have expired: please upload it again.', 'selective-entity-sync' ), array( 'status' => 404 ) );
	}

	/**
	 * Converts an exception to a REST error.
	 *
	 * @param SyncException $e Exception.
	 * @return WP_Error
	 */
	private function error( SyncException $e ): WP_Error {
		return new WP_Error( 'selective_entity_sync_import_failed', $e->getMessage(), array( 'status' => 400 ) );
	}
}
