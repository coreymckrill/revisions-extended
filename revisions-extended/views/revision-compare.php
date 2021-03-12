<?php

namespace RevisionsExtended\Admin;

use WP_Post;
use function RevisionsExtended\Revision\get_edit_revision_link;

defined( 'WPINC' ) || die();

/** @var int $revision_id */
/** @var WP_Post $revision */
/** @var array $errors */
?>

<div class="wrap">
	<?php if ( ! empty( $errors ) ) : ?>
		<?php foreach ( $errors as $error ) : ?>
			<div class="notice notice-error">
				<p><?php echo esc_html( $error ); ?></p>
			</div>
		<?php endforeach; ?>
	<?php else : ?>
		<h1 class="wp-heading-inline">
			<?php
			printf(
				__( 'Update to &#8220;%s&#8221;', 'revisions-extended' ),
				get_the_title( $revision->post_parent )
			);
			?>
		</h1>

		<?php
		printf(
			'<a class="page-title-action" href="%1$s">%2$s</a>',
			esc_url( get_edit_revision_link( $revision_id ) ),
			esc_html__( 'Edit Update', 'revisions-extended' )
		);
		?>

		<?php if ( 'future' === get_post_status( $revision ) ) : ?>
			<p>
				<?php
				printf(
					__( 'Scheduled for %s', 'revisions-extended' ),
					date_i18n( __( 'F jS, Y \a\t g:i a', 'revisions-extended' ), strtotime( $revision->post_date ) )
				);
				?>
			</p>
		<?php else : ?>
			<p>
				<?php echo esc_html( get_post_status_object( $revision->post_status )->label ); ?>
			</p>
		<?php endif; ?>
	<?php endif; ?>

	<hr class="wp-header-end">
</div>

<?php wp_print_revision_templates(); ?>
