<?php
/**
 * Admin order update form view.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
	exit;
}

$view_data = isset($view_data) && is_array($view_data) ? $view_data : [];
$settings = isset($view_data['settings']) && is_array($view_data['settings']) ? $view_data['settings'] : [];
$order_id = isset($view_data['order_id']) ? absint($view_data['order_id']) : 0;
$statuses = isset($view_data['statuses']) && is_array($view_data['statuses']) ? $view_data['statuses'] : [];

$settings = wp_parse_args(
	$settings,
	[
		'enable_assignee' => true,
		'enable_color' => true,
		'enable_customer_note' => true,
		'enable_solved_state' => true,
		'allow_deletion' => false,
	]
);
?>
<div class="awts_form" role="dialog" aria-modal="false" aria-labelledby="awts_form_heading">
	<p id="awts_form_heading" class="awts_form_heading"><?php esc_html_e( 'Add New Update', 'order-updates-for-woo' ); ?></p>
	<p class="awts_form_mode_hint" hidden><?php esc_html_e( 'Edit updates here. Internal notes and customer notes can be managed from the card panels.', 'order-updates-for-woo' ); ?></p>
	<div class="awts_form_notice" hidden></div>
	<input id="awts_order_id" type="hidden" value="<?php echo esc_attr((string) $order_id); ?>">
	<input id="awts_update_id" type="hidden" value="">

	<div class="awts_field">
		<label class="awts_field_label" for="awts_update_name"><?php esc_html_e( 'Update', 'order-updates-for-woo' ); ?></label>
		<input id="awts_update_name" class="awts_input" type="text" value="">
	</div>

	<?php do_action('order_updates_for_woo_before_default_form_fields', $settings); ?>

	<?php if ($settings['enable_assignee']) : ?>
		<div class="awts_field">
			<label class="awts_field_label" for="awts_update_assignee"><?php esc_html_e( 'Assignee', 'order-updates-for-woo' ); ?></label>
			<div class="awts_autocomplete_wrapper">
				<input id="awts_update_assignee" class="awts_input" type="text" value="" autocomplete="off">
				<input id="awts_update_assignee_id" type="hidden" value="">
			</div>
			<p class="awts_field_hint"><?php esc_html_e( 'Searches admins, shop managers and editors.', 'order-updates-for-woo' ); ?></p>
		</div>
	<?php endif; ?>

	<div class="awts_field awts_add_only_field awts_internal_note_field">
		<label class="awts_field_label" for="awts_update_internal_note"><?php esc_html_e( 'Internal note', 'order-updates-for-woo' ); ?></label>
		<textarea id="awts_update_internal_note" class="awts_textarea" placeholder="<?php echo esc_attr__( 'Plain text only. Add internal context for your team. Maximum 500 characters.', 'order-updates-for-woo' ); ?>" data-awts-character-limit="500" maxlength="500"></textarea>
		<p class="awts_field_hint awts_note_counter" data-awts-counter-for="awts_update_internal_note"><?php esc_html_e( '0/500', 'order-updates-for-woo' ); ?></p>
	</div>

	<?php if ($settings['enable_customer_note']) : ?>
		<div class="awts_field awts_add_only_field awts_customer_note_field" hidden>
			<label class="awts_field_label" for="awts_update_customer_note"><?php esc_html_e( 'Customer note', 'order-updates-for-woo' ); ?></label>
			<textarea id="awts_update_customer_note" class="awts_textarea" placeholder="<?php echo esc_attr__( 'Plain text only. Add the message customers should see. Maximum 500 characters.', 'order-updates-for-woo' ); ?>" data-awts-character-limit="500" maxlength="500"></textarea>
			<p class="awts_field_hint awts_customer_note_visibility_hint">
				<?php esc_html_e( 'Writing a message here makes this update visible to the customer. They will be emailed a link to view and reply — guest customers receive the same email with a guest chat link.', 'order-updates-for-woo' ); ?>
			</p>
			<p class="awts_field_hint awts_note_counter" data-awts-counter-for="awts_update_customer_note"><?php esc_html_e( '0/500', 'order-updates-for-woo' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ($settings['enable_color'] && ! empty($statuses)) : ?>
		<div class="awts_field">
			<label class="awts_field_label" for="awts_update_status"><?php esc_html_e( 'Status', 'order-updates-for-woo' ); ?></label>
			<select id="awts_update_status" class="awts_input awts_status_select">
				<?php foreach ($statuses as $status) :
					$status_key   = (string) ($status['key'] ?? '');
					$status_label = (string) ($status['label'] ?? '');
					if ('' === $status_key || '' === $status_label) {
						continue;
					}
					?>
					<option value="<?php echo esc_attr($status_key); ?>"><?php echo esc_html($status_label); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
	<?php endif; ?>

	<?php do_action('order_updates_for_woo_after_default_form_fields', $settings); ?>

	<div class="awts_meta_block" hidden>
		<p class="awts_timestamp"><span class="awts_meta_created_label"><?php esc_html_e( 'Created:', 'order-updates-for-woo' ); ?></span> <span class="awts_meta_created_value"></span></p>
		<p class="awts_timestamp"><span class="awts_meta_notified_label"><?php esc_html_e( 'Notified at:', 'order-updates-for-woo' ); ?></span> <span class="awts_meta_notified_value"></span></p>
	</div>

	<?php do_action('order_updates_for_woo_form_meta', $settings); ?>

	<div class="awts_button_row">
		<button type="button" class="awts_primary_button awts_save_form"><?php esc_html_e( 'Publish Update', 'order-updates-for-woo' ); ?></button>
		<button type="button" class="awts_secondary_button awts_cancel_form"><?php esc_html_e( 'Cancel', 'order-updates-for-woo' ); ?></button>
	</div>
</div>
