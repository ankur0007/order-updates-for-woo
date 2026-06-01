<?php
/**
 * Customer-facing list of updates for a single order. Splits the list
 * into Active (open) and Archived (resolved) sections so a customer
 * with many old threads can find the live conversation at a glance.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data           = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$updates             = isset( $view_data['updates'] ) && is_array( $view_data['updates'] ) ? $view_data['updates'] : array();
$rating_config       = isset( $view_data['rating'] ) && is_array( $view_data['rating'] ) ? $view_data['rating'] : array( 'enabled' => false, 'comment_enabled' => false );
$show_assignee       = ! empty( $view_data['show_assignee'] );
$email_notifications = isset( $view_data['email_notifications'] ) ? (bool) $view_data['email_notifications'] : true;

if ( empty( $updates ) ) {
	return;
}

$rating_enabled         = ! empty( $rating_config['enabled'] );
$rating_comment_enabled = ! empty( $rating_config['comment_enabled'] );

// Pre-split. Order preserved within each section so newest-first stays.
$active_updates   = array();
$archived_updates = array();
foreach ( $updates as $u ) {
	if ( UpdateState::is_resolved( $u ) ) {
		$archived_updates[] = $u;
	} else {
		$active_updates[] = $u;
	}
}

// One bundle of strings + flags passed to every item render.
$item_labels = array(
	'created'                => __( 'Opened', 'order-updates-for-woo' ),
	'open'                   => __( 'Open', 'order-updates-for-woo' ),
	'resolved'               => __( 'Resolved', 'order-updates-for-woo' ),
	'no_notes'               => __( 'No messages yet.', 'order-updates-for-woo' ),
	'reply'                  => __( 'Write a reply', 'order-updates-for-woo' ),
	'reply_placeholder'      => __( 'Type your reply…', 'order-updates-for-woo' ),
	'reply_submit'           => __( 'Send reply', 'order-updates-for-woo' ),
	'reply_attach'           => __( 'Attach files (optional)', 'order-updates-for-woo' ),
	'reopen'                 => __( 'Still has issue?', 'order-updates-for-woo' ),
	'rating_heading'         => __( 'How did we do?', 'order-updates-for-woo' ),
	'rating_intro'           => __( 'Your feedback helps us improve. Rate this update and leave a comment if you like.', 'order-updates-for-woo' ),
	'rating_comment_label'   => __( 'Comment (optional)', 'order-updates-for-woo' ),
	'rating_comment_ph'      => __( 'Tell us what worked or what could be better…', 'order-updates-for-woo' ),
	'rating_submit'          => __( 'Submit rating', 'order-updates-for-woo' ),
	/* translators: %s: rating value out of 5. */
	'rating_thanks_template' => __( 'You rated this %s/5. Thanks for your feedback!', 'order-updates-for-woo' ),
	/* translators: %d: number of stars. */
	'rating_star_template'   => __( '%d stars', 'order-updates-for-woo' ),
	'rating_star1'           => __( '1 star', 'order-updates-for-woo' ),
);

$item_context = array(
	'show_assignee'          => $show_assignee,
	'rating_enabled'         => $rating_enabled,
	'rating_comment_enabled' => $rating_comment_enabled,
	'labels'                 => $item_labels,
);

$heading_label = __( 'Order updates', 'order-updates-for-woo' );
?>
<section class="awts_cou" aria-label="<?php echo esc_attr( $heading_label ); ?>">
	<div class="awts_cou_email_pref">
		<label class="awts_cou_email_pref__label">
			<input type="checkbox" class="awts_cou_email_pref__checkbox" data-awts-cou-email-pref<?php echo $email_notifications ? ' checked' : ''; ?>>
			<?php esc_html_e( 'Notify me via email for new updates', 'order-updates-for-woo' ); ?>
		</label>
	</div>

	<?php if ( ! empty( $active_updates ) ) : ?>
		<details class="awts_cou_section awts_cou_section--active" open>
			<summary class="awts_cou_section__summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of active updates */
						_n( 'Active (%d)', 'Active (%d)', count( $active_updates ), 'order-updates-for-woo' ),
						count( $active_updates )
					)
				);
				?>
			</summary>
			<ul class="awts_cou__list awts_cou__list--active">
				<?php foreach ( $active_updates as $index => $update ) :
					View::render(
						'src/Frontend/OrderUpdates/Templates/customer/item',
						array_merge(
							$item_context,
							array(
								'update'              => $update,
								'is_first_in_section' => 0 === $index,
							)
						)
					);
				endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

	<?php if ( ! empty( $archived_updates ) ) : ?>
		<details class="awts_cou_section awts_cou_section--archived">
			<summary class="awts_cou_section__summary">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d: number of archived (resolved) updates */
						_n( 'Archived (%d)', 'Archived (%d)', count( $archived_updates ), 'order-updates-for-woo' ),
						count( $archived_updates )
					)
				);
				?>
			</summary>
			<ul class="awts_cou__list awts_cou__list--archived">
				<?php foreach ( $archived_updates as $update ) :
					// Archived items stay collapsed; never first-in-section auto-open.
					View::render(
						'src/Frontend/OrderUpdates/Templates/customer/item',
						array_merge(
							$item_context,
							array(
								'update'              => $update,
								'is_first_in_section' => false,
							)
						)
					);
				endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>
</section>
