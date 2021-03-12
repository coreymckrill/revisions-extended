<?php

namespace RevisionsExtended\Revision;

use WP_Error, WP_Post, WP_Post_Type, WP_Query;
use function RevisionsExtended\Post_Status\get_revision_status_slugs;
use function RevisionsExtended\Post_Status\validate_revision_status;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_action( 'registered_post_type', __NAMESPACE__ . '\modify_revision_post_type', 10, 2 );
add_filter( 'pre_wp_unique_post_slug', __NAMESPACE__ . '\filter_pre_wp_unique_post_slug', 10, 5 );
add_filter( 'wp_insert_post_data', __NAMESPACE__ . '\filter_wp_insert_post_data' );
add_action( 'load-edit.php', __NAMESPACE__ . '\short_circuit_default_revisions_list_table' );
add_action( 'publish_future_revision', __NAMESPACE__ . '\action_check_and_publish_future_revision' );

/**
 * Change the properties of the built-in revision post type so it's editable in the block editor.
 *
 * @param string       $post_type
 * @param WP_Post_Type $post_type_object
 *
 * @return void
 */
function modify_revision_post_type( $post_type, $post_type_object ) {
	if ( 'revision' === $post_type ) {
		$post_type_object->show_ui  = true;
		$post_type_object->supports = array( 'title', 'editor', 'author', 'excerpt' );
		$post_type_object->add_supports();

		$post_type_object->show_in_rest          = true;
		$post_type_object->rest_base             = 'revision';
		$post_type_object->rest_controller_class = '\\RevisionsExtended\\REST_Revision_Controller';
	}
}

/**
 * Check if a given post type should support updates.
 *
 * @param string $post_type
 *
 * @return bool True if the post type supports updates.
 */
function post_type_supports_updates( $post_type ) {
	$exceptions = array( 'wp_template' );

	return post_type_supports( $post_type, 'revisions' ) && ! in_array( $post_type, $exceptions, true );
}

/**
 * Returns revisions of specified post.
 *
 * Specify a value for $args['post_status'] to get other types of revisions.
 *
 * Modified from wp_get_post_revisions in wp-includes/revision.php in Core.
 *
 * @see get_children()
 *
 * @param int|WP_Post $post_id Optional. Post ID or WP_Post object. Default is global `$post`.
 * @param array|null  $args    Optional. Arguments for retrieving post revisions. Default null.
 *
 * @return array An array of revisions, or an empty array if none.
 */
function get_post_revisions( $post_id = 0, $args = null ) {
	$post = get_post( $post_id );
	if ( ! $post || empty( $post->ID ) ) {
		return array();
	}

	$defaults = array(
		'order'         => 'DESC',
		'orderby'       => 'date ID',
		'check_enabled' => true,
		'post_status'   => 'inherit', // Changed from Core.
	);
	$args     = wp_parse_args( $args, $defaults );

	if ( $args['check_enabled'] && ! wp_revisions_enabled( $post ) ) {
		return array();
	}

	$args = array_merge(
		$args,
		array(
			'post_parent' => $post->ID,
			'post_type'   => 'revision',
			// Changed from Core.
		)
	);

	$revisions = get_children( $args );
	if ( ! $revisions ) {
		return array();
	}

	return $revisions;
}

/**
 * Get revision posts for a particular parent post type.
 *
 * @param string $parent_post_type The parent post type to get revisions for.
 * @param array  $args             Optional. Additional query args.
 * @param bool   $wp_query         Optional. True to return a WP_Query object instead of an array of post objects.
 *
 * @return WP_Post[]|WP_Query
 */
function get_revisions_by_parent_type( $parent_post_type, $args = array(), $wp_query = false ) {
	global $wpdb;

	$statuses_in = array_map( 'esc_sql', get_revision_status_slugs() );
	$statuses_in = "'" . implode( "','", $statuses_in ) . "'";

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$valid_ids = $wpdb->get_col(
		$wpdb->prepare(
			"
			SELECT revisions.ID
			FROM {$wpdb->posts} revisions
				JOIN {$wpdb->posts} parents ON parents.ID = revisions.post_parent AND parents.post_type = %s
			WHERE revisions.post_type = 'revision' AND revisions.post_status IN ($statuses_in)",
			$parent_post_type
		)
	);

	// If post__in gets an empty array, WP_Query will return all posts.
	if ( empty( $valid_ids ) ) {
		$valid_ids[] = false;
	}

	$args = array_merge(
		$args,
		array(
			'post_type' => 'revision',
			'post__in'  => $valid_ids,
		)
	);

	if ( true === $wp_query ) {
		return new WP_Query( $args );
	}

	return get_posts( $args );
}

/**
 * Inserts post data into the posts table as a post revision.
 *
 * Modified from _wp_put_post_revision in wp-includes/revision.php in Core.
 *
 * @access private
 *
 * @param int|WP_Post|array|null $post     Post ID, post object OR post array.
 * @param bool                   $autosave Optional. Is the revision an autosave?
 *
 * @return int|WP_Error WP_Error if error, new revision ID if success. (Change from Core.)
 */
function put_post_revision( $post = null, $autosave = false ) {
	if ( is_object( $post ) ) {
		$post = get_object_vars( $post );
	} elseif ( ! is_array( $post ) ) {
		$post = get_post( $post, ARRAY_A );
	}

	if ( ! $post || empty( $post['ID'] ) ) {
		return new WP_Error( 'invalid_post', __( 'Invalid post ID.', 'revisions-extended' ) );
	}

	if ( isset( $post['post_type'] ) && 'revision' === $post['post_type'] ) {
		return new WP_Error( 'post_type', __( 'Cannot create a revision of a revision', 'revisions-extended' ) );
	}

	// Begin changes from Core.
	if ( ! validate_revision_status( $post['post_status'] ) ) {
		return new WP_Error(
			'invalid_revision_status',
			__( 'Invalid revision status.', 'revisions-extended' )
		);
	}

	/**
	 * The _wp_post_revision_data function overrides some fields that need to be different
	 * for our extended revisions. We could override that function, but we'd still need
	 * to override this one as well, so we might as well just do it in one place.
	 */
	$keep_props = array( 'post_status', 'post_date', 'post_date_gmt' );
	$keep       = array_intersect_key( $post, array_fill_keys( $keep_props, '' ) );
	// End changes from Core.

	$post = _wp_post_revision_data( $post, $autosave );
	$post = array_merge( $post, $keep ); // Changed from Core.
	$post = wp_slash( $post ); // Since data is from DB.

	$revision_id = wp_insert_post( $post, true ); // Changed from Core.
	if ( is_wp_error( $revision_id ) ) {
		return $revision_id;
	}

	// TODO save a parent revision ID as post meta?

	if ( $revision_id ) {
		/**
		 * Fires once a revision has been saved.
		 *
		 * @since 2.6.0
		 *
		 * @param int $revision_id Post revision ID.
		 */
		do_action( '_wp_put_post_revision', $revision_id );
	}

	return $revision_id;
}

/**
 * Update a post with the contents of a revision.
 *
 * Uses the same mechanism as for restoring a past revision, but if the revision is pending/scheduled,
 * it will be converted to a standard revision first, using the current time for the post date.
 *
 * Note that this can only complete successfully if the parent post is published.
 *
 * @param int $revision_id
 *
 * @return int|WP_Error The ID of the updated post. Otherwise a WP_Error.
 */
function update_post_from_revision( $revision_id ) {
	$revision = wp_get_post_revision( $revision_id );
	if ( is_null( $revision ) ) {
		return new WP_Error(
			'invalid_revision_id',
			__( 'Invalid revision ID.', 'revisions-extended' )
		);
	}

	$parent = get_post( $revision->post_parent );
	// TODO Replace with `is_post_publicly_viewable()` when WP 5.7 lands.
	if ( 'publish' !== get_post_status( $parent ) ) {
		return new WP_Error(
			'invalid_parent_post',
			__( 'Parent post is not published.', 'revisions-extended' )
		);
	}

	$status = get_post_status( $revision );
	if ( 'inherit' !== $status ) {
		$postarr = array(
			'ID'            => $revision->ID,
			'post_status'   => 'inherit',
			// Set the revision's post date to the current time.
			'post_date'     => '0000-00-00 00:00:00',
			'post_date_gmt' => '0000-00-00 00:00:00',
		);

		$update = wp_update_post( wp_slash( $postarr ), true );

		if ( is_wp_error( $update ) ) {
			return $update;
		}
	}

	$result = wp_restore_post_revision( $revision_id );

	// Restore failed.
	if ( is_null( $result ) ) {
		return new WP_Error(
			'revision_error',
			__( 'The revision could not be restored.', 'revisions-extended' )
		);
	}

	// Restore succeeded, but there were no fields to update.
	if ( ! $result ) {
		$result = $revision->post_parent;
	}

	// Ensure there are no more scheduled publish events for this revision.
	wp_clear_scheduled_hook( 'publish_future_revision', array( $revision->ID ) );

	return $result;
}

/**
 * Generate a link for editing a revision.
 *
 * Note that this does not do a permission check. That should happen before calling this function.
 *
 * @param int $revision_id
 *
 * @return string
 */
function get_edit_revision_link( $revision_id ) {
	$revision = wp_get_post_revision( $revision_id );
	if ( ! $revision ) {
		return '';
	}

	return add_query_arg(
		array(
			'post'   => $revision_id,
			'action' => 'edit',
		),
		admin_url( 'post.php' )
	);
}

/**
 * Ensure extended revision posts use the same slug naming convention as normal revisions.
 *
 * @param string|null $override
 * @param string      $slug
 * @param int         $post_ID
 * @param string      $post_status
 * @param string      $post_type
 *
 * @return string|null
 */
function filter_pre_wp_unique_post_slug( $override, $slug, $post_ID, $post_status, $post_type ) {
	$statuses = get_revision_status_slugs();

	if ( 'revision' === $post_type && in_array( $post_status, $statuses, true ) ) {
		$override = $slug;
	}

	return $override;
}

/**
 * Ensure the modified date for a scheduled revision is the current date, not the future publish date.
 *
 * @param array $data
 *
 * @return array
 */
function filter_wp_insert_post_data( $data ) {
	if (
		'revision' === $data['post_type']
		&& 'future' === $data['post_status']
		&& $data['post_date_gmt'] === $data['post_modified_gmt']
		&& strtotime( $data['post_date_gmt'] ) > time()
	) {
		$data['post_modified']     = current_time( 'mysql' );
		$data['post_modified_gmt'] = current_time( 'mysql', 1 );
	}

	return $data;
}

/**
 * Bail from the Core-generated list table screen for revisions.
 *
 * Since we're modifying the revision post type and setting `show_ui` to true, Core generates this list table of
 * revisions at wp-admin/edit.php?post_type=revision. We want to use the updates subpages instead.
 *
 * @return void
 */
function short_circuit_default_revisions_list_table() {
	global $typenow;

	if ( 'revision' === $typenow ) {
		wp_die( __( 'Sorry, you are not allowed to list revisions this way.', 'revisions-extended' ) );
	}
}

/**
 * Action to update a post from a future revision when the time comes.
 *
 * Hooked to a scheduled event. See RevisionsExtended\Cron.
 *
 * Modeled on check_and_publish_future_post().
 *
 * @param int $revision_id
 *
 * @return void
 */
function action_check_and_publish_future_revision( $revision_id ) {
	$revision = wp_get_post_revision( $revision_id );
	if ( ! $revision ) {
		return;
	}

	if ( 'future' !== get_post_status( $revision ) ) {
		return;
	}

	$time = strtotime( $revision->post_date_gmt . ' GMT' );

	// Uh oh, someone jumped the gun!
	if ( $time > time() ) {
		wp_clear_scheduled_hook( 'publish_future_revision', array( $revision_id ) ); // Clear anything else in the system.
		wp_schedule_single_event( $time, 'publish_future_revision', array( $revision_id ) );

		return;
	}

	update_post_from_revision( $revision_id );
}
