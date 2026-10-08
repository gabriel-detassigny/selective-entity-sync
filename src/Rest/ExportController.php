<?php
/**
 * Export REST controller.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Rest;

use SelectiveEntitySync\Export\Exporter;
use SelectiveEntitySync\Export\ExportResult;
use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Exception\SyncException;
use SelectiveEntitySync\Storage\TempStorage;
use SelectiveEntitySync\Support\Capabilities;
use SelectiveEntitySync\Support\PlainText;
use WP_Error;
use WP_HTTP_Response;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Export routes:
 *
 * - GET  /export/options: exportable post types and statuses, for filters.
 * - POST /export/preview: what an export of the given posts would contain.
 * - POST /export: builds the package and responds with the zip file itself,
 *   so nothing needs to be stored between requests.
 */
class ExportController extends Controller {

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * Exporter.
	 *
	 * @var Exporter
	 */
	private $exporter;

	/**
	 * Temporary storage.
	 *
	 * @var TempStorage
	 */
	private $storage;

	/**
	 * Package built during the current request, waiting to be streamed.
	 *
	 * @var ExportResult|null
	 */
	private $pending_download;

	/**
	 * Constructor.
	 *
	 * @param Capabilities   $capabilities Capability checker.
	 * @param ExportSettings $settings     Export settings.
	 * @param Exporter       $exporter     Exporter.
	 * @param TempStorage    $storage      Temporary storage.
	 */
	public function __construct( Capabilities $capabilities, ExportSettings $settings, Exporter $exporter, TempStorage $storage ) {
		parent::__construct( $capabilities );
		$this->settings = $settings;
		$this->exporter = $exporter;
		$this->storage  = $storage;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_hooks(): void {
		parent::register_hooks();
		add_filter( 'rest_pre_serve_request', array( $this, 'maybe_serve_package' ), 10, 3 );
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		$selection = array(
			'post_ids' => array(
				'type'     => 'array',
				'required' => true,
				'minItems' => 1,
				'maxItems' => Exporter::MAX_SELECTION,
				'items'    => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
			),
		);

		register_rest_route(
			self::NAMESPACE,
			'/export/options',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_options' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/export/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'preview' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $selection,
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/export',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => $selection,
			)
		);
	}

	/**
	 * Returns the exportable post types and statuses.
	 *
	 * @return WP_REST_Response
	 */
	public function get_options(): WP_REST_Response {
		$post_types = array();
		foreach ( $this->settings->get_exportable_post_types() as $name ) {
			$object       = get_post_type_object( $name );
			$post_types[] = array(
				'name'  => $name,
				'label' => null !== $object ? $object->labels->singular_name : $name,
			);
		}

		$statuses = array();
		foreach ( $this->settings->get_exportable_statuses() as $name ) {
			$object     = get_post_status_object( $name );
			$statuses[] = array(
				'name'  => $name,
				'label' => null !== $object ? (string) $object->label : $name,
			);
		}

		return new WP_REST_Response(
			array(
				'post_types' => $post_types,
				'statuses'   => $statuses,
			)
		);
	}

	/**
	 * Previews an export.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function preview( WP_REST_Request $request ) {
		try {
			$plan = $this->exporter->plan( $this->get_post_ids( $request ) );
		} catch ( SyncException $e ) {
			return $this->export_error( $e );
		}

		return new WP_REST_Response( $plan->get_summary() );
	}

	/**
	 * Builds the package. The zip is streamed by maybe_serve_package().
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function export( WP_REST_Request $request ) {
		try {
			$this->pending_download = $this->exporter->export( $this->get_post_ids( $request ) );
		} catch ( SyncException $e ) {
			return $this->export_error( $e );
		}

		$response = new WP_REST_Response( array( 'filename' => $this->get_download_filename() ) );
		$response->header( 'Content-Type', 'application/zip' );

		return $response;
	}

	/**
	 * Streams the zip built by export() instead of a JSON body.
	 *
	 * @param bool             $served  Whether the request has already been served.
	 * @param WP_HTTP_Response $result  Result to send.
	 * @param WP_REST_Request  $request Request.
	 * @return bool
	 */
	public function maybe_serve_package( $served, $result, $request ): bool {
		if (
			$served
			|| null === $this->pending_download
			|| ! $request instanceof WP_REST_Request
			|| '/' . self::NAMESPACE . '/export' !== $request->get_route()
			|| ! $result instanceof WP_HTTP_Response
			|| 200 !== $result->get_status()
		) {
			return (bool) $served;
		}

		$this->serve_package( $this->pending_download );
		$this->pending_download = null;

		return true;
	}

	/**
	 * Sends a package file to the client and deletes it.
	 *
	 * @param ExportResult $result Export result.
	 * @return void
	 */
	public function serve_package( ExportResult $result ): void {
		$path = $result->get_package_path();

		if ( ! headers_sent() ) {
			header( 'Content-Type: application/zip' );
			header( 'Content-Disposition: attachment; filename="' . $this->get_download_filename() . '"' );
			header( 'Content-Length: ' . (int) filesize( $path ) );
			header( 'Cache-Control: no-store' );
			header( 'X-Content-Type-Options: nosniff' );
		}

		readfile( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- Streaming a package we just built in our own temp directory.
		$this->storage->delete( $result->get_directory() );
	}

	/**
	 * Returns the suggested download file name, e.g. "selective-entity-sync-staging-example-com-20261005-120000.zip".
	 *
	 * @return string
	 */
	public function get_download_filename(): string {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

		return sprintf( 'selective-entity-sync-%s-%s.zip', sanitize_title( $host ), gmdate( 'Ymd-His' ) );
	}

	/**
	 * Returns the selected post IDs from a request.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return int[]
	 */
	private function get_post_ids( WP_REST_Request $request ): array {
		return array_map( 'intval', (array) $request['post_ids'] );
	}

	/**
	 * Converts an export exception to a REST error.
	 *
	 * @param SyncException $e Exception.
	 * @return WP_Error
	 */
	private function export_error( SyncException $e ): WP_Error {
		return new WP_Error( 'selective_entity_sync_export_failed', PlainText::from_exception( $e ), array( 'status' => 400 ) );
	}
}
