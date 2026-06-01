<?php
/**
 * Card header — title + inline actions row.
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/header.php
 *
 * Hook surface:
 *   - order_updates_for_woo_update_card_actions  (action) — append your own buttons to the actions row.
 *
 * @var array $view_data {
 *     @type array $raw       Update row from the DB.
 *     @type array $settings  Plugin settings.
 *     @type array $flags     Computed flags from the parent view.
 * }
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$raw      = $view_data['raw'] ?? array();
$settings = $view_data['settings'] ?? array();
$flags    = $view_data['flags'] ?? array();

$update_id         = (int) ( $raw['id'] ?? 0 );
$can_edit          = ! empty( $flags['can_edit'] );
$is_resolved       = ! empty( $flags['is_resolved'] );
$rating_status     = (string) ( $flags['rating_status'] ?? '' );
$staff_email_muted = ! empty( $flags['staff_email_muted'] );
?>
<div class="awts_card_top">
	<div class="awts_title">
		<?php if ( $update_id > 0 ) : ?>
			<span class="awts_update_id_badge" title="<?php echo esc_attr__( 'Update ID — matches the notification reference', 'order-updates-for-woo' ); ?>">#<?php echo esc_html( (string) $update_id ); ?></span>
		<?php endif; ?>
		<span class="awts_title_text"><?php echo esc_html( (string) ( $raw['title'] ?? '' ) ); ?></span>
		<?php if ( $can_edit ) : ?>
			<button type="button" class="awts_inline_edit_btn awts_edit_title" title="<?php echo esc_attr__( 'Edit', 'order-updates-for-woo' ); ?>">
				<?php echo Icons::dashicon( 'edit', __( 'Edit', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</button>
		<?php endif; ?>
	</div>

	<div class="awts_card_actions">
		<?php if ( ! empty( $settings['enable_solved_state'] ) ) : ?>
			<?php if ( ! $is_resolved ) : ?>
				<button type="button" class="awts_text_action awts_text_action_primary awts_mark_solved">
					<?php esc_html_e( 'Mark as solved', 'order-updates-for-woo' ); ?>
				</button>
			<?php elseif ( 'received' !== $rating_status ) : ?>
				<button type="button" class="awts_text_action awts_text_action_reopen awts_reopen_update">
					<?php esc_html_e( 'Re-open', 'order-updates-for-woo' ); ?>
				</button>
			<?php endif; ?>
		<?php endif; ?>

		<?php do_action( 'order_updates_for_woo_update_card_actions', $raw, $settings ); ?>

		<?php if ( ! empty( $settings['allow_deletion'] ) ) : ?>
			<button
				type="button"
				class="awts_text_action awts_text_action_danger awts_delete_update"
				data-awts-update-id="<?php echo esc_attr( (string) $update_id ); ?>"
			>
				<?php esc_html_e( 'Delete Update', 'order-updates-for-woo' ); ?>
			</button>
		<?php endif; ?>

		<label class="awts_get_notifications" title="<?php echo esc_attr__( 'When on, you receive admin-bar and email notifications for activity on this update. Switch off to mute just this thread.', 'order-updates-for-woo' ); ?>">
			<input
				type="checkbox"
				class="awts_get_notifications__input"
				data-awts-staff-email-pref
				data-awts-update-id="<?php echo esc_attr( (string) $update_id ); ?>"
				<?php checked( ! $staff_email_muted ); ?>
			>
			<span class="awts_get_notifications__track" aria-hidden="true">
				<span class="awts_get_notifications__thumb"></span>
			</span>
			<span class="awts_get_notifications__label">
				<?php esc_html_e( 'Get notifications', 'order-updates-for-woo' ); ?>
			</span>
		</label>

		<button type="button" class="awts_inline_edit_btn awts_card_collapse_toggle" title="<?php echo esc_attr__( 'Collapse / expand', 'order-updates-for-woo' ); ?>" aria-label="<?php echo esc_attr__( 'Collapse or expand this update', 'order-updates-for-woo' ); ?>">
			<?php echo Icons::dashicon( 'arrow-up-alt2', __( 'Collapse or expand', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</div>
</div>
