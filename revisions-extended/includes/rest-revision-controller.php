<?php

namespace RevisionsExtended;

use WP_Error, WP_Post, WP_Post_Type;
use WP_REST_Posts_Controller, WP_REST_Revisions_Controller, WP_REST_Request, WP_REST_Response, WP_REST_Server;
use function RevisionsExtended\Post_Status\get_revision_status_slugs;
use function RevisionsExtended\Post_Status\validate_revision_status;
use function RevisionsExtended\Revision\update_post_from_revision;

defined( 'WPINC' ) || die();

/**
 * Manage revision posts.
 *
 * @see WP_REST_Posts_Controller
 */
class REST_Revision_Controller extends WP_REST_Posts_Controller {
	/**
	 * Registers the routes for the objects of the controller.
	 *
	 * @see register_rest_route()
	 */
	public function register_routes() {
		$get_item_args = array(
			'context' => $this->get_context_param( array( 'default' => 'view' ) ),
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.', 'revisions-extended' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => $get_item_args,
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'update_item_permissions_check' ),
					'args'                => $this->get_valid_endpoint_args( WP_REST_Server::EDITABLE ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'delete_item_permissions_check' ),
					'args'                => array(
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'Whether to bypass Trash and force deletion.', 'revisions-extended' ),
						),
					),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/publish',
			array(
				'args'   => array(
					'id' => array(
						'description' => __( 'Unique identifier for the object.', 'revisions-extended' ),
						'type'        => 'integer',
					),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'publish_item' ),
					'permission_callback' => array( $this, 'publish_item_permissions_check' ),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);
	}

	/**
	 * Get the subset of the parent post type's endpoint args that are valid for our purposes.
	 *
	 * @param string $method
	 *
	 * @return array
	 */
	protected function get_valid_endpoint_args( $method ) {
		$revision_fields   = array_map(
			function( $item ) {
				return str_replace( 'post_', '', $item );
			},
			array_keys( _wp_post_revision_fields() )
		);
		$revision_fields[] = 'status';
		$revision_fields[] = 'date';
		$revision_fields[] = 'date_gmt';

		$parent_endpoint_args = parent::get_endpoint_args_for_item_schema( $method );

		$parent_endpoint_args['status']['enum'] = get_revision_status_slugs();

		return array_intersect_key( $parent_endpoint_args, array_fill_keys( $revision_fields, '' ) );
	}

	/**
	 * Checks if a given request has access to read a post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return true|WP_Error True if the request has read access for the item, WP_Error object otherwise.
	 */
	public function get_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$request['parent'] = $post->post_parent;
		$controller        = $this->get_revisions_controller( get_post_type( $post->post_parent ) );

		return $controller->get_item_permissions_check( $request );
	}

	/**
	 * Checks if a given request has access to update a revision.
	 *
	 * Revisions inherit permissions from the parent post,
	 * check if the current user has permission to edit the post.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return true|WP_Error
	 */
	public function update_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		if ( 'inherit' === get_post_status( $post ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit this revision.', 'revisions-extended' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$get_item = $this->get_item_permissions_check( $request );
		if ( is_wp_error( $get_item ) ) {
			return $get_item;
		}

		// This actually checks if the user can edit the parent post.
		if ( ! $this->check_update_permission( $post ) ) {
			return new WP_Error(
				'rest_cannot_edit',
				__( 'Sorry, you are not allowed to edit revisions of this post.', 'revisions-extended' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		$post_type_object = get_post_type_object( 'revision' );

		// Separate from the above check, we want to make sure the user can edit the revision post itself as well.
		if ( get_current_user_id() !== $post->post_author && ! current_user_can( $post_type_object->cap->edit_others_posts ) ) {
			return new WP_Error(
				'rest_cannot_edit_others',
				__( 'Sorry, you are not allowed to edit revisions created by other users.', 'revisions-extended' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	/**
	 * Checks if a given request has access to delete a post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return true|WP_Error True if the request has access to delete the item, WP_Error object otherwise.
	 */
	public function delete_item_permissions_check( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$request['parent']    = $post->post_parent;
		$revisions_controller = $this->get_revisions_controller( get_post_type( $post->post_parent ) );

		return $revisions_controller->delete_item_permissions_check( $request );
	}

	/**
	 * Deletes a single post.
	 *
	 * @param WP_REST_Request $request Full details about the request.
	 *
	 * @return WP_REST_Response|WP_Error Response object on success, or WP_Error object on failure.
	 */
	public function delete_item( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$revisions_controller = $this->get_revisions_controller( get_post_type( $post->post_parent ) );
		$request['parent']    = $post->post_parent;

		return $revisions_controller->delete_item( $request );
	}

	/**
	 * Checks if a given request has access to update a post from a revision.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return true|WP_Error
	 */
	public function publish_item_permissions_check( $request ) {
		return $this->update_item_permissions_check( $request );
	}

	/**
	 * "Publish" a revision as the new version of a post.
	 *
	 * @param WP_REST_Request $request
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function publish_item( $request ) {
		$post = $this->get_post( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$result = update_post_from_revision( $post->ID );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$post = get_post( $result );
		if ( ! $post ) {
			$post = new WP_Error(
				'rest_post_invalid_id',
				__( 'Invalid post ID.', 'revisions-extended' ),
				array( 'status' => 404 )
			);
		}

		$request->set_param( 'context', 'edit' );

		$response = $this->prepare_item_for_response( $post, $request );

		return rest_ensure_response( $response );
	}

	/**
	 * Determines validity and normalizes the given status parameter.
	 *
	 * @param string       $post_status Post status.
	 * @param WP_Post_Type $post_type   Post type.
	 *
	 * @return string|WP_Error Post status or WP_Error if lacking the proper permission.
	 */
	protected function handle_status_param( $post_status, $post_type ) {
		if ( ! validate_revision_status( $post_status ) ) {
			return new WP_Error(
				'rest_cannot_update_status',
				__( 'Sorry, this post status is not allowed for revisions.', 'revisions-extended' ),
				array( 'status' => 403 )
			);
		}

		return $post_status;
	}

	/**
	 * Prepares links for the request.
	 *
	 * @param WP_Post $post Post object.
	 *
	 * @return array Links for the given post.
	 */
	protected function prepare_links( $post ) {
		$links = parent::prepare_links( $post );

		if ( ! empty( $post->post_parent ) ) {
			$parent    = get_post( $post->post_parent );
			$post_type = get_post_type( $parent );

			if ( $parent && $post_type ) {
				$links['parent'] = array(
					'href'       => rest_url(
						sprintf(
							'wp/v2/%s/%d',
							get_post_type_object( $post_type )->get_rest_controller()->rest_base,
							$parent->ID
						)
					),
					'embeddable' => true,
				);
			}
		}

		return $links;
	}

	/**
	 * Retrieves the revision's schema, conforming to JSON Schema.
	 *
	 * @return array Item schema data.
	 */
	public function get_item_schema() {
		$schema = parent::get_item_schema();

		$schema['properties']['status']['enum'] = get_revision_status_slugs();
		$schema['properties']['parent']         = array(
			'description' => __( 'The ID for the parent of the object.', 'revisions-extended' ),
			'type'        => 'integer',
			'context'     => array( 'view', 'edit' ),
		);

		return $schema;
	}

	/**
	 * Get a revisions controller for a particular parent post type.
	 *
	 * @param string $parent_post_type
	 *
	 * @return WP_REST_Revisions_Controller
	 */
	protected function get_revisions_controller( $parent_post_type ) {
		static $controllers = array();

		if ( ! isset( $controllers[ $parent_post_type ] ) ) {
			$controllers[ $parent_post_type ] = new WP_REST_Revisions_Controller( $parent_post_type );
		}

		return $controllers[ $parent_post_type ];
	}
}
