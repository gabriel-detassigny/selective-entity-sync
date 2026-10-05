<?php
/**
 * Entities REST controller.
 *
 * @package SelectiveEntitySync
 */

namespace SelectiveEntitySync\Rest;

use SelectiveEntitySync\Export\ExportSettings;
use SelectiveEntitySync\Identity\EntityUuid;
use SelectiveEntitySync\Support\Capabilities;
use WP_Error;
use WP_Post;
use WP_Query;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * GET /selective-entity-sync/v1/entities: lists content that can be selected for export.
 */
class EntitiesController extends Controller {

	/**
	 * Export settings.
	 *
	 * @var ExportSettings
	 */
	private $settings;

	/**
	 * UUID service.
	 *
	 * @var EntityUuid
	 */
	private $uuids;

	/**
	 * Constructor.
	 *
	 * @param Capabilities   $capabilities Capability checker.
	 * @param ExportSettings $settings     Export settings.
	 * @param EntityUuid     $uuids        UUID service.
	 */
	public function __construct( Capabilities $capabilities, ExportSettings $settings, EntityUuid $uuids ) {
		parent::__construct( $capabilities );
		$this->settings = $settings;
		$this->uuids    = $uuids;
	}

	/**
	 * {@inheritDoc}
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/entities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_items' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'search'    => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'status'    => array(
						'type'    => 'string',
						'default' => '',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'orderby'   => array(
						'type'    => 'string',
						'default' => 'modified',
						'enum'    => array( 'modified', 'date', 'title' ),
					),
					'order'     => array(
						'type'    => 'string',
						'default' => 'desc',
						'enum'    => array( 'asc', 'desc' ),
					),
				),
			)
		);
	}

	/**
	 * Lists exportable content.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( WP_REST_Request $request ) {
		$post_types = $this->settings->get_exportable_post_types();
		$statuses   = $this->settings->get_exportable_statuses();

		$post_type = (string) $request['post_type'];
		if ( '' !== $post_type ) {
			if ( ! in_array( $post_type, $post_types, true ) ) {
				return $this->invalid_param( 'post_type' );
			}
			$post_types = array( $post_type );
		}

		$status = (string) $request['status'];
		if ( '' !== $status ) {
			if ( ! in_array( $status, $statuses, true ) ) {
				return $this->invalid_param( 'status' );
			}
			$statuses = array( $status );
		}

		$query = new WP_Query(
			array(
				'post_type'           => $post_types,
				'post_status'         => $statuses,
				's'                   => (string) $request['search'],
				'paged'               => (int) $request['page'],
				'posts_per_page'      => (int) $request['per_page'],
				'orderby'             => (string) $request['orderby'],
				'order'               => strtoupper( (string) $request['order'] ),
				'ignore_sticky_posts' => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			if ( $post instanceof WP_Post ) {
				$items[] = $this->prepare_item( $post );
			}
		}

		$response = new WP_REST_Response( $items );
		$response->header( 'X-WP-Total', (string) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (string) $query->max_num_pages );

		return $response;
	}

	/**
	 * Prepares one item.
	 *
	 * @param WP_Post $post Post.
	 * @return array<string, mixed>
	 */
	private function prepare_item( WP_Post $post ): array {
		$type_object   = get_post_type_object( $post->post_type );
		$status_object = get_post_status_object( $post->post_status );
		$author        = get_userdata( (int) $post->post_author );

		return array(
			'id'              => $post->ID,
			'title'           => html_entity_decode( get_the_title( $post ), ENT_QUOTES, get_bloginfo( 'charset' ) ),
			'post_type'       => $post->post_type,
			'post_type_label' => null !== $type_object ? $type_object->labels->singular_name : $post->post_type,
			'status'          => $post->post_status,
			'status_label'    => null !== $status_object ? (string) $status_object->label : $post->post_status,
			'modified_gmt'    => mysql_to_rfc3339( $post->post_modified_gmt ),
			'author'          => false !== $author ? $author->display_name : '',
			'edit_link'       => (string) get_edit_post_link( $post->ID, 'raw' ),
			'uuid'            => $this->uuids->find( 'post', $post->ID ),
		);
	}

	/**
	 * Returns an invalid-parameter error.
	 *
	 * @param string $param Parameter name.
	 * @return WP_Error
	 */
	private function invalid_param( string $param ): WP_Error {
		return new WP_Error(
			'rest_invalid_param',
			sprintf(
				/* translators: %s: Parameter name. */
				__( 'Invalid parameter: %s', 'selective-entity-sync' ),
				$param
			),
			array( 'status' => 400 )
		);
	}
}
