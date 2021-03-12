<?php

namespace RevisionsExtended\Admin;

use WP_Post;
use RevisionsExtended\Admin\Revision_List_Table;
use function RevisionsExtended\get_assets_path;
use function RevisionsExtended\get_build_asset_info;
use function RevisionsExtended\get_includes_path;
use function RevisionsExtended\get_views_path;
use function RevisionsExtended\Post_Status\get_revision_status_slugs;
use function RevisionsExtended\Revision\post_type_supports_updates;
use function RevisionsExtended\Revision\get_post_revisions;
use function RevisionsExtended\Revision\update_post_from_revision;

defined( 'WPINC' ) || die();

/**
 * Actions and filters.
 */
add_action( 'admin_enqueue_scripts', __NAMESPACE__ . '\enqueue_admin_assets', 1 );
add_action( 'enqueue_block_editor_assets', __NAMESPACE__ . '\enqueue_block_editor_assets' );
add_action( 'admin_menu', __NAMESPACE__ . '\add_updates_subpages' );
add_action( 'admin_menu', __NAMESPACE__ . '\register_revision_compare_screen' );
add_action( 'removable_query_args', __NAMESPACE__ . '\filter_add_removable_query_args' );
add_filter( 'parent_file', __NAMESPACE__ . '\filter_menu_file_for_edit_screen' );
add_filter( 'submenu_file', __NAMESPACE__ . '\filter_menu_file_for_edit_screen' );
add_filter( 'display_post_states', __NAMESPACE__ . '\filter_display_post_states', 10, 2 );

/**
 * Enqueue assets for admin screens, except the block editor.
 *
 * @param string $hook_suffix
 *
 * @return void
 */
function enqueue_admin_assets( $hook_suffix ) {
	global $pagenow, $typenow;

	$parent_screen = $pagenow;
	if ( ! empty( $typenow ) ) {
		$parent_screen = add_query_arg( 'post_type', $typenow, $parent_screen );
	}

	$post_type = $typenow ?: 'post';

	switch ( $hook_suffix ) {
		case 'admin_page_compare-updates':
			$revision_id = filter_input( INPUT_GET, 'revision_id', FILTER_VALIDATE_INT );
			$revision    = wp_get_post_revision( $revision_id );

			if ( $revision ) {
				wp_enqueue_script( 'revisions' );
				wp_localize_script( 'revisions', '_wpRevisionsSettings', prepare_compare_data( $revision_id ) );

				// This is an ugly hack to get the revisions.js script to work.
				wp_add_inline_script( 'revisions', "window.adminpage = 'revision-php';", 'before' );
			}

			wp_enqueue_style(
				'revisions-extended-compare-updates',
				plugins_url( 'assets/compare-updates.css', dirname( __FILE__, 1 ) ),
				array(),
				filemtime( get_assets_path() . 'compare-updates.css' )
			);
			break;

		case get_plugin_page_hookname( "$post_type-updates", $parent_screen ):
			wp_enqueue_style(
				'revisions-extended-edit-revisions',
				plugins_url( 'assets/edit-revisions.css', dirname( __FILE__, 1 ) ),
				array(),
				filemtime( get_assets_path() . 'edit-revisions.css' )
			);
			break;
	}
}

/**
 * Enqueue assets for the block editor.
 *
 * @return void
 */
function enqueue_block_editor_assets() {
	global $typenow;

	$post_type = $typenow ?: 'post';

	$handles = array();
	if ( 'revision' === $post_type ) {
		$handles[] = 'revision-editor';
	} elseif ( post_type_supports_updates( $post_type ) ) {
		$handles[] = 'editor-modifications';
	}

	foreach ( $handles as $handle ) {
		$asset = get_build_asset_info( $handle );

		if ( ! empty( $asset ) ) {
			wp_enqueue_script(
				"revisions-extended-$handle-script",
				plugins_url( "build/$handle.js", dirname( __FILE__, 1 ) ),
				$asset['dependencies'],
				$asset['version'],
				false
			);

			wp_enqueue_style(
				"revisions-extended-$handle-style",
				plugins_url( "build/$handle.css", dirname( __FILE__, 1 ) ),
				array(),
				$asset['version']
			);
		}
	}
}

/**
 * Register a subpage for each post type that supports revisions.
 *
 * @return void
 */
function add_updates_subpages() {
	$supported_post_types = get_post_types_by_support( 'revisions' );

	foreach ( $supported_post_types as $post_type ) {
		$parent_slug = 'edit.php';
		if ( 'post' !== $post_type ) {
			$parent_slug = add_query_arg( 'post_type', $post_type, $parent_slug );
		}

		$post_type_object = get_post_type_object( $post_type );

		add_submenu_page(
			$parent_slug,
			__( 'Updates', 'revisions-extended' ),
			__( 'Updates', 'revisions-extended' ),
			$post_type_object->cap->edit_posts,
			$post_type . '-updates',
			__NAMESPACE__ . '\render_updates_subpage'
		);

		$page_hook = get_plugin_page_hook( $post_type . '-updates', $parent_slug );

		add_action( "load-$page_hook", __NAMESPACE__ . '\pre_render_updates_subpage' );
	}
}

/**
 * Things that need to happen before a subpage's render function is called.
 *
 * Plugin pages already have headers sent and admin header templates loaded by the time that
 * the render callback is called.
 *
 * @return void
 */
function pre_render_updates_subpage() {
	// This is a hack to ensure that the list table columns are registered properly.
	get_list_table();

	add_screen_option( 'per_page' );

	add_updates_help_tabs();

	$action = wp_unslash( filter_input( INPUT_GET, 'action' ) );
	if ( ! $action || '-1' === $action ) {
		$action = wp_unslash( filter_input( INPUT_GET, 'action2' ) );
	}

	if ( $action && '-1' !== $action ) {
		$redirect = handle_bulk_edit_actions( $action );

		wp_safe_redirect( $redirect );
	} else {
		$remove_args = array_filter(
			(array) filter_input_array(
				INPUT_GET,
				array(
					'action'           => FILTER_DEFAULT,
					'action2'          => FILTER_DEFAULT,
					'_wp_http_referer' => FILTER_DEFAULT,
					'_wpnonce'         => FILTER_DEFAULT,
				)
			)
		);
		$remove_args = array_keys( $remove_args );

		if ( '' === filter_input( INPUT_GET, 's' ) ) {
			$remove_args[] = 's';
		}

		if ( 1 === filter_input( INPUT_GET, 'paged', FILTER_VALIDATE_INT ) ) {
			$remove_args[] = 'paged';
		}

		if ( ! empty( $remove_args ) ) {
			wp_safe_redirect( remove_query_arg( $remove_args, $_SERVER['REQUEST_URI'] ) );
		}
	}
}

/**
 * Render a subpage for updates.
 *
 * @return void
 */
function render_updates_subpage() {
	$post_id    = filter_input( INPUT_GET, 'p', FILTER_VALIDATE_INT );
	$list_table = get_list_table();
	$notices    = array(
		'success' => array(),
		'error'   => array(),
	);

	$action_results = array_filter(
		filter_input_array(
			INPUT_GET,
			array(
				// Be sure to include each of these in `filter_add_removable_query_args` as well.
				'invalid'       => FILTER_VALIDATE_BOOLEAN,
				'no_items'      => FILTER_VALIDATE_BOOLEAN,
				'deleted'       => FILTER_VALIDATE_INT,
				'not_deleted'   => FILTER_VALIDATE_INT,
				'published'     => FILTER_VALIDATE_INT,
				'not_published' => FILTER_VALIDATE_INT,
			)
		)
	);

	if ( ! empty( $action_results ) ) {
		foreach ( $action_results as $result => $count ) {
			switch ( $result ) {
				case 'invalid':
					$notices['error'][] = __( 'Invalid form submission.', 'revisions-extended' );
					break;
				case 'no_items':
					$notices['error'][] = __( 'No updates were selected for bulk editing.', 'revisions-extended' );
					break;
				case 'deleted':
					$notices['success'][] = sprintf(
						_n(
							'%d update permanently deleted.',
							'%d updates permanently deleted.',
							$count,
							'revisions-extended'
						),
						number_format_i18n( $count )
					);
					break;
				case 'not_deleted':
					$notices['error'][] = sprintf(
						_n(
							'%d update could not be deleted.',
							'%d updates could not be deleted.',
							$count,
							'revisions-extended'
						),
						number_format_i18n( $count )
					);
					break;
				case 'published':
					$notices['success'][] = sprintf(
						_n(
							'%d update published.',
							'%d updates published.',
							$count,
							'revisions-extended'
						),
						number_format_i18n( $count )
					);
					break;
				case 'not_published':
					$notices['error'][] = sprintf(
						_n(
							'%d update could not be published.',
							'%d updates could not be published.',
							$count,
							'revisions-extended'
						),
						number_format_i18n( $count )
					);
					break;
			}
		}
	}

	require get_views_path() . 'edit-revisions.php';
}

/**
 * Add help tabs to the Updates subpage.
 *
 * @return void
 */
function add_updates_help_tabs() {
	$screen = get_current_screen();

	$screen->add_help_tab(
		array(
			'id'      => 'overview',
			'title'   => __( 'Overview', 'revisions-extended' ),
			'content' =>
				'<p>' . __( 'Updates are changes to existing published posts that haven&#8217;t gone live yet.', 'revisions-extended' ) . '</p>' .
				'<p>' . __( 'You can customize the display of this screen to suit your workflow, similar to posts and pages.', 'revisions-extended' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'screen-content',
			'title'   => __( 'Screen Content', 'revisions-extended' ),
			'content' =>
				'<p>' . __( 'You can customize the display of this screen&#8217;s contents:', 'revisions-extended' ) . '</p>' .
				'<ul>' .
					'<li>' . __( 'You can decide how many updates to list per screen using the Screen Options tab.', 'revisions-extended' ) . '</li>' .
					'<li>' . __( 'You can sort the updates list in different ways by clicking any of the column headers that display as links.', 'revisions-extended' ) . '</li>' .
				'</ul>' .
				'<p>' . __( 'If an update has a &#8220;Suspended&#8221; state, it means that it can&#8217;t be published because the parent post isn&#8217;t currently published. It can, however, still be edited.', 'revisions-extended' ) . '</p>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'action-links',
			'title'   => __( 'Available actions', 'revisions-extended' ),
			'content' =>
				'<p>' . __( 'Hovering over a row in the updates list will display action links that allow you to manage your update. You can perform the following actions:', 'revisions-extended' ) . '</p>' .
				'<ul>' .
					'<li>' . __( '<strong>Edit</strong> takes you to the editing screen for that update. You can also reach that screen by clicking on the update title.', 'revisions-extended' ) . '</li>' .
					'<li>' . __( '<strong>Compare</strong> takes you to a screen that shows the differences between the current live content in a post and the content in the update.', 'revisions-extended' ) . '</li>' .
					'<li>' . __( '<strong>Delete Permanently</strong> deletes the update. This action can&#8217;t be undone.', 'revisions-extended' ) . '</li>' .
				'</ul>',
		)
	);

	$screen->add_help_tab(
		array(
			'id'      => 'bulk-actions',
			'title'   => __( 'Bulk actions', 'revisions-extended' ),
			'content' =>
				'<p>' . __( 'You can publish or delete multiple updates at once. Select the posts you want to act on using the checkboxes, then select the action you want to take from the Bulk actions menu and click Apply.' ) . '</p>',
		)
	);
}

/**
 * Get an instance of the revision list table.
 *
 * @return \RevisionsExtended\Admin\Revision_List_Table
 */
function get_list_table() {
	require_once get_includes_path() . 'revision-list-table.php';

	return new Revision_List_Table();
}

/**
 * Process list table form submissions for bulk actions.
 *
 * @param string $action
 *
 * @return string The URL to redirect to once processing is complete.
 */
function handle_bulk_edit_actions( $action ) {
	$screen = get_current_screen();
	check_admin_referer( "bulk-{$screen->base}" );

	$valid_actions = array( 'delete', 'publish' );
	$items         = filter_input( INPUT_GET, 'bulk_edit', FILTER_VALIDATE_INT, FILTER_REQUIRE_ARRAY );
	$query_args    = array();
	$edited        = 0;
	$not_edited    = 0;
	$redirect      = wp_get_referer();

	if ( ! in_array( $action, $valid_actions, true ) ) {
		$query_args['invalid'] = 1;
	}

	if ( empty( $items ) ) {
		$query_args['no_items'] = 1;
	}

	if ( ! empty( $query_args ) ) {
		return add_query_arg( $query_args, $redirect );
	}

	foreach ( $items as $revision_id ) {
		$revision = wp_get_post_revision( $revision_id );

		if ( $revision ) {
			switch ( $action ) {
				case 'delete':
					$result = wp_delete_post_revision( $revision );
					break;
				case 'publish':
					$result = update_post_from_revision( $revision->ID );

					if ( is_wp_error( $result ) ) {
						$result = false;
					}
					break;
			}

			if ( $result ) {
				$edited ++;
			} else {
				$not_edited ++;
			}
		} else {
			$not_edited ++;
		}
	}

	if ( $edited || $not_edited ) {
		switch ( $action ) {
			case 'delete':
				$query_args['deleted']     = $edited;
				$query_args['not_deleted'] = $not_edited;
				break;
			case 'publish':
				$query_args['published']     = $edited;
				$query_args['not_published'] = $not_edited;
				break;
		}
	}

	return add_query_arg( $query_args, $redirect );
}

/**
 * Get the full admin URL for an updates subpage for a given post type.
 *
 * @param string $parent_post_type
 *
 * @return string
 */
function get_updates_subpage_url( $parent_post_type ) {
	$url = add_query_arg(
		array(
			'page' => "$parent_post_type-updates",
		),
		admin_url( 'edit.php' )
	);

	if ( 'post' !== $parent_post_type ) {
		$url = add_query_arg( 'post_type', $parent_post_type, $url );
	}

	return $url;
}

/**
 * Register a screen for showing a diff between a post and a scheduled update.
 *
 * @return void
 */
function register_revision_compare_screen() {
	add_submenu_page(
		'revision.php',
		__( 'Compare Updates', 'revisions-extended' ),
		null,
		'read',
		'compare-updates',
		__NAMESPACE__ . '\render_compare_screen'
	);
}

/**
 * Render the screen for showing a diff.
 *
 * @return void
 */
function render_compare_screen() {
	require_once ABSPATH . 'wp-admin/includes/revision.php';

	$revision_id = filter_input( INPUT_GET, 'revision_id', FILTER_VALIDATE_INT );
	$revision    = wp_get_post_revision( $revision_id );
	$errors      = array();

	if ( ! $revision ) {
		$errors[] = __( 'Invalid revision ID.', 'revisions-extended' );
	}

	if ( $revision && ! current_user_can( 'edit_post', $revision->ID ) ) {
		$errors[] = __( 'Sorry, you are not allowed to view this revision.', 'revisions-extended' );
	}

	require get_views_path() . 'revision-compare.php';
}

/**
 * Generate the data used by revisions.js to render a diff.
 *
 * @see wp_prepare_revisions_for_js()
 *
 * @param int $revision_id
 *
 * @return array
 */
function prepare_compare_data( $revision_id ) {
	require_once ABSPATH . 'wp-admin/includes/revision.php';

	$data         = array();
	$show_avatars = get_option( 'show_avatars' );

	$revision = wp_get_post_revision( $revision_id );
	if ( ! $revision ) {
		return array();
	}

	$parent = get_post( $revision->post_parent );

	$data[ $parent->ID ] = array(
		'id'         => $parent->ID,
		'title'      => get_the_title( $parent->ID ),
		'author'     => array(
			'id'     => (int) $parent->post_author,
			'avatar' => $show_avatars ? get_avatar( $parent->post_author, 32 ) : '',
			'name'   => get_the_author_meta( 'display_name', $parent->post_author ),
		),
		'date'       => date_i18n( __( 'M j, Y @ H:i', 'revisions-extended' ), strtotime( $parent->post_modified ) ),
		'dateShort'  => date_i18n( _x( 'j M @ H:i', 'revision date short format', 'revisions-extended' ), strtotime( $parent->post_modified ) ),
		'timeAgo'    => sprintf(
			/* translators: %s: Human-readable time difference. */
			__( '%s ago', 'revisions-extended' ),
			human_time_diff( strtotime( $parent->post_modified_gmt . ' +0000' ), time() )
		),
		'autosave'   => false,
		'current'    => true,
		'restoreUrl' => false,
	);

	$data[ $revision->ID ] = array(
		'id'         => $revision->ID,
		'title'      => get_the_title( $revision->ID ),
		'author'     => array(
			'id'     => (int) $revision->post_author,
			'avatar' => $show_avatars ? get_avatar( $revision->post_author, 32 ) : '',
			'name'   => get_the_author_meta( 'display_name', $revision->post_author ),
		),
		'date'       => date_i18n( __( 'M j, Y @ H:i', 'revisions-extended' ), strtotime( $revision->post_modified ) ),
		'dateShort'  => date_i18n( _x( 'j M @ H:i', 'revision date short format', 'revisions-extended' ), strtotime( $revision->post_modified ) ),
		'timeAgo'    => sprintf(
			/* translators: %s: Human-readable time difference. */
			__( '%s ago', 'revisions-extended' ),
			human_time_diff( strtotime( $revision->post_modified_gmt . ' +0000' ), time() )
		),
		'autosave'   => false,
		'current'    => false,
		'restoreUrl' => false,
	);

	$diffs = array(
		array(
			'id'     => $parent->ID . ':' . $revision->ID,
			'fields' => wp_get_revision_ui_diff( $parent->ID, $parent->ID, $revision->ID ),
		),
	);

	return array(
		'postId'         => $parent->ID,
		'nonce'          => wp_create_nonce( 'revisions-ajax-nonce' ),
		'revisionData'   => array_values( $data ),
		'to'             => $revision->ID,
		'from'           => $parent->ID,
		'diffData'       => $diffs,
		'baseUrl'        => wp_parse_url( admin_url( 'revision.php' ), PHP_URL_PATH ),
		'compareTwoMode' => 0, // Apparently booleans are not allowed.
		'revisionIds'    => array_keys( $data ),
	);
}

/**
 * Get the full admin URL for compare updates screen for a given revision.
 *
 * @param string $parent_post_type
 *
 * @return string
 */
function get_compare_url( $revision_id ) {
	$url = add_query_arg(
		array(
			'page'        => 'compare-updates',
			'revision_id' => $revision_id,
		),
		admin_url( 'revision.php' )
	);

	return $url;
}

/**
 * Some additional single-use query variable names that can be removed from an admin URL.
 *
 * @param array $removable_query_args
 *
 * @return string[]
 */
function filter_add_removable_query_args( $removable_query_args ) {
	$removable_query_args = array_merge(
		$removable_query_args,
		array(
			'invalid',
			'no_items',
			'deleted',
			'not_deleted',
			'published',
			'not_published',
		)
	);

	return $removable_query_args;
}

/**
 * Highlight the Updates menu item when editing a revision.
 *
 * @param string $file
 *
 * @return mixed|string
 */
function filter_menu_file_for_edit_screen( $file ) {
	if ( 'edit.php?post_type=revision' === $file ) {
		global $post;
		$post_type = get_post_type( $post->post_parent );

		if ( $post_type ) {
			switch ( current_filter() ) {
				case 'parent_file':
					$file = 'edit.php';

					if ( 'post' !== $post_type ) {
						$file = add_query_arg( 'post_type', $post_type, $file );
					}
					break;
				case 'submenu_file':
					$file = "$post_type-updates";
					break;
			}
		}
	}

	return $file;
}

/**
 * Add states for posts with updates.
 *
 * @param array   $post_states
 * @param WP_Post $post
 *
 * @return mixed
 */
function filter_display_post_states( $post_states, $post ) {
	if ( 'revision' === get_post_type( $post ) ) {
		$post_states = array();
		$parent      = get_post( $post->post_parent );

		// TODO Replace with `is_post_publicly_viewable()` when WP 5.7 lands.
		if ( 'publish' !== get_post_status( $parent ) ) {
			$post_states['suspended'] = _x( 'Suspended', 'post status', 'revisions-extended' );
		}
	} elseif ( post_type_supports_updates( get_post_type( $post ) ) ) {
		$args      = array(
			'post_status' => get_revision_status_slugs(),
		);
		$revisions = get_post_revisions( $post, $args );

		if ( ! empty( $revisions ) ) {
			if ( 'publish' !== get_post_status( $post ) ) {
				$post_states['updates'] = _nx(
					'Update Suspended',
					'Updates Suspended',
					count( $revisions ),
					'post status',
					'revisions-extended'
				);
			} else {
				$post_states['updates'] = _nx(
					'Update',
					'Updates',
					count( $revisions ),
					'post status',
					'revisions-extended'
				);
			}
		}
	}

	return $post_states;
}
