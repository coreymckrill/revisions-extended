<?php

namespace RevisionsExtended\Capabilities;

use function RevisionsExtended\Post_Status\validate_revision_status;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_filter( 'map_meta_cap', __NAMESPACE__ . '\map_meta_caps', 10, 4 );

/**
 * Modify capabilities.
 *
 * @param array  $caps
 * @param string $cap
 * @param int    $user_id
 * @param array  $args
 *
 * @return array
 */
function map_meta_caps( $caps, $cap, $user_id, $args ) {
	if ( in_array( $cap, array( 'delete_post', 'edit_post', 'read_post' ), true ) ) {
		$post   = get_post( $args[0] );
		$status = get_post_status( $post );

		if ( $post && 'revision' === get_post_type( $post ) && validate_revision_status( $status ) ) {
			$revision_object = get_post_type_object( 'revision' );
			$parent          = get_post( $post->post_parent );
			$parent_object   = get_post_type_object( get_post_type( $parent ) );

			$caps = array();

			switch ( $cap ) {
				case 'delete_post':
					if ( $post->post_author && $user_id === $post->post_author ) {
						$caps[] = $revision_object->cap->delete_posts;
					} else {
						$caps[] = $revision_object->cap->delete_others_posts;
					}
					break;
				case 'edit_post':
					if ( $post->post_author && $user_id === $post->post_author ) {
						$caps[] = $revision_object->cap->edit_posts;
					} else {
						$caps[] = $revision_object->cap->edit_others_posts;
					}
					break;
				case 'read_post':
					if ( $post->post_author && $user_id === $post->post_author ) {
						$caps[] = $parent_object->cap->edit_posts;
					} else {
						$caps[] = $parent_object->cap->edit_others_posts;
					}
					break;
			}
		}
	}

	return $caps;
}
