<?php

namespace RevisionsExtended\Admin;

use WP_List_Table, WP_Post, WP_Query;
use function RevisionsExtended\Admin\get_updates_subpage_url;
use function RevisionsExtended\Admin\get_compare_url;
use function RevisionsExtended\Post_Status\get_revision_statuses;
use function RevisionsExtended\Post_Status\get_revision_status_slugs;
use function RevisionsExtended\Post_Status\validate_revision_status;
use function RevisionsExtended\Revision\get_edit_revision_link;
use function RevisionsExtended\Revision\get_revisions_by_parent_type;

defined( 'WPINC' ) || die();

/**
 * Class Revision_List_Table
 *
 * A list table for Scheduled Updates revisions.
 */
class Revision_List_Table extends WP_List_Table {
	/**
	 * @var string
	 */
	public $parent_post_type;

	/**
	 * Revision_List_Table constructor.
	 *
	 * @param array $args
	 */
	public function __construct( $args = array() ) {
		global $typenow;
		$this->parent_post_type = $typenow ?: 'post';

		parent::__construct( $args );
	}

	/**
	 * Checks the current user's permissions
	 *
	 * @return bool
	 */
	public function ajax_user_can() {
		return current_user_can( get_post_type_object( $this->parent_post_type )->cap->edit_posts );
	}

	/**
	 * Prepares the list of items for displaying.
	 *
	 * @uses WP_List_Table::set_pagination_args()
	 *
	 * @return void
	 */
	public function prepare_items() {
		$post_type = 'revision';
		$per_page  = $this->get_items_per_page( 'edit_' . $post_type . '_per_page' );

		/** This filter is documented in wp-admin/includes/post.php */
		$per_page = apply_filters( 'edit_posts_per_page', $per_page, $post_type );

		$orderby   = wp_unslash( filter_input( INPUT_GET, 'orderby' ) );
		$order     = wp_unslash( filter_input( INPUT_GET, 'order' ) );
		$status    = wp_unslash( filter_input( INPUT_GET, 'post_status' ) );
		$search    = wp_unslash( filter_input( INPUT_GET, 's' ) );
		$parent_id = filter_input( INPUT_GET, 'p', FILTER_VALIDATE_INT );

		$query_args = array(
			'post_status'    => get_revision_status_slugs(),
			'posts_per_page' => $per_page,
			'orderby'        => $orderby ?: 'date ID',
			'order'          => $order ?: 'asc',
		);

		if ( $status && validate_revision_status( $status ) ) {
			$query_args['post_status'] = $status;
		}

		if ( $search ) {
			$query_args['s'] = $search;
		}

		if ( $parent_id ) {
			$query_args['post_parent'] = $parent_id;
		}

		$query = get_revisions_by_parent_type( $this->parent_post_type, $query_args, true );

		$this->items = $query->get_posts();

		$this->set_pagination_args(
			array(
				'total_items' => $query->found_posts,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Gets the list of views available on this table.
	 *
	 * The format is an associative array:
	 * - `'id' => 'link'`
	 *
	 * @return array
	 */
	protected function get_views() {
		$view_links = array();

		$current_status    = wp_unslash( filter_input( INPUT_GET, 'post_status' ) );
		$revision_statuses = get_revision_statuses();
		$posts_by_status   = (array) wp_count_posts( 'revision' );
		$total_posts       = array_sum( array_intersect_key( $posts_by_status, $revision_statuses ) );

		$all_inner_html = sprintf(
			/* translators: %s: Number of posts. */
			_nx(
				'All <span class="count">(%s)</span>',
				'All <span class="count">(%s)</span>',
				$total_posts,
				'posts',
				'revisions-extended'
			),
			number_format_i18n( $total_posts )
		);

		$view_links['all'] = sprintf(
			'<a href="%1$s"%2$s%3$s>%4$s</a>',
			esc_url( get_updates_subpage_url( $this->parent_post_type ) ),
			$current_status ? '' : ' class="current"',
			$current_status ? '' : ' aria-current="page"',
			$all_inner_html
		);

		foreach ( $revision_statuses as $slug => $obj ) {
			if ( empty( $posts_by_status[ $slug ] ) ) {
				continue;
			}

			$inner_html = sprintf(
				/* translators: 1: Post status. 2: Number of posts. */
				_nx(
					'%1$s <span class="count">(%2$s)</span>',
					'%1$s <span class="count">(%2$s)</span>',
					$posts_by_status[ $slug ],
					'posts',
					'revisions-extended'
				),
				esc_html( $obj->label ),
				number_format_i18n( $posts_by_status[ $slug ] )
			);

			$view_url = add_query_arg(
				'post_status',
				$slug,
				get_updates_subpage_url( $this->parent_post_type )
			);

			$view_links[ $slug ] = sprintf(
				'<a href="%1$s"%2$s%3$s>%4$s</a>',
				esc_url( $view_url ),
				$current_status === $slug ? ' class="current"' : '',
				$current_status === $slug ? ' aria-current="page"' : '',
				$inner_html
			);
		}

		if ( count( $view_links ) > 1 ) {
			return $view_links;
		}

		return array();
	}

	/**
	 * Retrieves the list of bulk actions available for this table.
	 *
	 * The format is an associative array where each element represents either a top level option value and label, or
	 * an array representing an optgroup and its options.
	 *
	 * For a standard option, the array element key is the field value and the array element value is the field label.
	 *
	 * For an optgroup, the array element key is the label and the array element value is an associative array of
	 * options as above.
	 *
	 * Example:
	 *
	 *     [
	 *         'edit'         => 'Edit',
	 *         'delete'       => 'Delete',
	 *         'Change State' => [
	 *             'feature' => 'Featured',
	 *             'sale'    => 'On Sale',
	 *         ]
	 *     ]
	 *
	 * @return array
	 */
	protected function get_bulk_actions() {
		$actions = array(
			'publish' => __( 'Publish Immediately', 'revisions-extended' ),
			'delete'  => __( 'Delete Permanently', 'revisions-extended' ),
		);

		return $actions;
	}

	/**
	 * Gets a list of columns.
	 *
	 * The format is:
	 * - `'internal-name' => 'Title'`
	 *
	 * @return array
	 */
	public function get_columns() {
		$columns = array(
			'cb'       => '<input type="checkbox" />',
			'title'    => _x( 'Title', 'column name', 'revisions-extended' ),
			'status'   => _x( 'Status', 'column name', 'revisions-extended' ),
			'parent'   => _x( 'An update to', 'column name', 'revisions-extended' ),
			'modified' => _x( 'Last modified', 'column name', 'revisions-extended' ),
			'author'   => _x( 'Author', 'column name', 'revisions-extended' ),
		);

		$parent_id = filter_input( INPUT_GET, 'p', FILTER_VALIDATE_INT );
		if ( $parent_id ) {
			unset( $columns['parent'] );
		}

		return $columns;
	}

	/**
	 * Gets a list of sortable columns.
	 *
	 * The format is:
	 * - `'internal-name' => 'orderby'`
	 * - `'internal-name' => array( 'orderby', 'asc' )` - The second element sets the initial sorting order.
	 * - `'internal-name' => array( 'orderby', true )`  - The second element makes the initial order descending.
	 *
	 * @return array
	 */
	protected function get_sortable_columns() {
		return array(
			'title'    => 'title',
			'status'   => array( 'date title', 'asc' ),
			'modified' => array( 'modified', true ),
		);
	}

	/**
	 * Render the checkbox ("cb") column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_cb( $post ) {
		$show = current_user_can( 'edit_post', $post->ID );

		if ( $show ) :
			?>
			<label class="screen-reader-text" for="cb-select-<?php echo esc_attr( $post->ID ); ?>">
				<?php
				/* translators: %s: Post title. */
				printf( __( 'Select %s', 'revisions-extended' ), _draft_or_post_title() );
				?>
			</label>
			<input
				id="cb-select-<?php echo esc_attr( $post->ID ); ?>"
				type="checkbox"
				name="bulk_edit[]"
				value="<?php echo esc_attr( $post->ID ); ?>"
			/>
			<div class="locked-indicator">
				<span class="locked-indicator-icon" aria-hidden="true"></span>
				<span class="screen-reader-text">
				<?php
				printf(
					/* translators: %s: Post title. */
					__( '&#8220;%s&#8221; is locked', 'revisions-extended' ),
					_draft_or_post_title( $post )
				);
				?>
				</span>
			</div>
			<?php
		endif;
	}

	/**
	 * Render the Title column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_title( $post ) {
		// Actually checks the parent post.
		$can_edit_post = current_user_can( 'edit_post', $post->ID );

		if ( $can_edit_post ) {
			$lock_holder = wp_check_post_lock( $post->ID );

			if ( $lock_holder ) {
				$lock_holder   = get_userdata( $lock_holder );
				$locked_avatar = get_avatar( $lock_holder->ID, 18 );
				/* translators: %s: User's display name. */
				$locked_text = esc_html( sprintf( __( '%s is currently editing', 'revisions-extended' ), $lock_holder->display_name ) );
			} else {
				$locked_avatar = '';
				$locked_text   = '';
			}

			echo '<div class="locked-info"><span class="locked-avatar">' . $locked_avatar . '</span> <span class="locked-text">' . $locked_text . "</span></div>\n";
		}

		echo '<strong>';

		$title = _draft_or_post_title( $post );

		if ( $can_edit_post ) {
			printf(
				'<a class="row-title" href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( get_edit_revision_link( $post->ID ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( '&#8220;%s&#8221; (Edit)', 'revisions-extended' ), $title ) ),
				$title
			);
		} else {
			printf(
				'<span>%s</span>',
				$title
			);
		}

		_post_states( $post );

		echo "</strong>\n";

		get_inline_data( $post );
	}

	/**
	 * Render the Author column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_author( $post ) {
		echo get_the_author_meta( 'nicename', $post->post_author );
	}

	/**
	 * Render the Parent column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_parent( $post ) {
		$parent = get_post( $post->post_parent );

		if ( ! $parent ) {
			printf(
				'<strong class="error-message">%s</strong>',
				esc_html__( 'Error: No parent.', 'revisions-extended' )
			);

			return;
		}

		// Actually checks the parent post.
		$can_edit_post = current_user_can( 'edit_post', $post->ID );
		$title         = _draft_or_post_title( $parent );

		if ( $can_edit_post ) {
			printf(
				'<a class="row-title" href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( get_edit_post_link( $parent ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( '&#8220;%s&#8221; (Edit)', 'revisions-extended' ), $title ) ),
				$title
			);
		} else {
			printf(
				'<span>%s</span>',
				$title
			);
		}

		$parent_status = get_post_status( $parent );

		// TODO Replace with `is_post_publicly_viewable()` when WP 5.7 lands.
		if ( 'publish' !== $parent_status ) {
			$parent_status_object = get_post_status_object( $parent_status );

			printf(
				// translators: %s is the status of a post.
				esc_html__( 'Not published (%s)', 'revisions-extended' ),
				sprintf(
					'<span class="error-message">%s</span>',
					esc_html( $parent_status_object->label )
				)
			);
		} else {
			$parent_post_type_object = get_post_type_object( get_post_type( $parent ) );

			printf(
				'<a href="%1$s" class="view-item-link">%2$s</a>',
				esc_url( get_permalink( $parent->ID ) ),
				esc_html( $parent_post_type_object->labels->view_item )
			);
		}
	}

	/**
	 * Render the Status column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_status( $post ) {
		$status = get_post_status( $post );
		$object = get_post_status_object( $status );

		switch ( $status ) {
			case 'future':
				$scheduled_time = get_post_timestamp( $post );
				$time_diff      = time() - $scheduled_time;

				if ( $time_diff > 0 ) {
					printf(
						'<strong class="error-message">%s</strong>',
						esc_html__( 'Missed schedule', 'revisions-extended' )
					);
				} else {
					printf(
						'<strong>%s</strong>',
						esc_html( $object->label )
					);
				}

				printf(
					/* translators: 1: Post date, 2: Post time. */
					esc_html__( '%1$s at %2$s', 'revisions-extended' ),
					/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
					get_the_time( __( 'Y/m/d', 'revisions-extended' ), $post ),
					/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
					get_the_time( __( 'g:i a', 'revisions-extended' ), $post )
				);
				break;
			case 'draft':
			case 'pending':
				printf(
					'<strong>%s</strong>',
					esc_html( $object->label )
				);
				break;
		}
	}

	/**
	 * Render the Modified column.
	 *
	 * @param WP_Post $post
	 *
	 * @return void
	 */
	public function column_modified( $post ) {
		printf(
			/* translators: 1: Post date, 2: Post time. */
			__( '%1$s at %2$s', 'revisions-extended' ),
			/* translators: Post date format. See https://www.php.net/manual/datetime.format.php */
			get_the_modified_time( __( 'Y/m/d', 'revisions-extended' ), $post ),
			/* translators: Post time format. See https://www.php.net/manual/datetime.format.php */
			get_the_modified_time( __( 'g:i a', 'revisions-extended' ), $post )
		);
	}

	/**
	 * Generates and display row actions links for the list table.
	 *
	 * @param WP_Post $post        The post being acted upon.
	 * @param string  $column_name Current column name.
	 * @param string  $primary     Primary column name.
	 *
	 * @return string The row actions HTML, or an empty string
	 *                if the current column is not the primary column.
	 */
	protected function handle_row_actions( $post, $column_name, $primary ) {
		if ( $primary !== $column_name ) {
			return '';
		}

		$screen        = get_current_screen();
		$can_edit_post = current_user_can( 'edit_post', $post->ID );
		$title         = _draft_or_post_title( $post );
		$parent        = get_post( $post->post_parent );
		$actions       = array();

		// Edit.
		if ( $can_edit_post ) {
			$actions['edit'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( get_edit_revision_link( $post->ID ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Edit &#8220;%s&#8221;', 'revisions-extended' ), $title ) ),
				__( 'Edit', 'revisions-extended' )
			);
		}

		// Compare.
		if ( $can_edit_post ) {
			$actions['compare'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( get_compare_url( $post->ID ) ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Compare &#8220;%s&#8221; to its parent post', 'revisions-extended' ), $title ) ),
				__( 'Compare', 'revisions-extended' )
			);
		}

		// Publish.
		if ( $can_edit_post && 'publish' === get_post_status( $parent ) ) { // TODO Replace with `is_post_publicly_viewable()` when WP 5.7 lands.
			$url = add_query_arg(
				array(
					'action'      => 'publish',
					'bulk_edit[]' => $post->ID,
				),
				wp_nonce_url( get_updates_subpage_url( $this->parent_post_type ), "bulk-{$screen->base}" )
			);

			$actions['publish'] = sprintf(
				'<a href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $url ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Publish &#8220;%s&#8221; immediately', 'revisions-extended' ), $title ) ),
				__( 'Publish', 'revisions-extended' )
			);
		}

		// Delete.
		if ( current_user_can( 'delete_post', $post->ID ) ) {
			$url = add_query_arg(
				array(
					'action'      => 'delete',
					'bulk_edit[]' => $post->ID,
				),
				wp_nonce_url( get_updates_subpage_url( $this->parent_post_type ), "bulk-{$screen->base}" )
			);

			$actions['delete'] = sprintf(
				'<a class="submitdelete" href="%1$s" aria-label="%2$s">%3$s</a>',
				esc_url( $url ),
				/* translators: %s: Post title. */
				esc_attr( sprintf( __( 'Delete &#8220;%s&#8221; permanently', 'revisions-extended' ), $title ) ),
				__( 'Delete Permanently', 'revisions-extended' )
			);
		}

		return $this->row_actions( $actions );
	}
}
