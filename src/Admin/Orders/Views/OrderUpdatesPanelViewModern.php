<?php
/**
 * Admin order updates panel view — modern layout.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\Icons;
use OrderUpdatesForWoo\Helpers\View;

if (! defined('ABSPATH')) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data           = isset($view_data) && is_array($view_data) ? $view_data : [];
$settings            = isset($view_data['settings']) && is_array($view_data['settings']) ? $view_data['settings'] : [];
$order_id            = isset($view_data['order_id']) ? absint($view_data['order_id']) : 0;
$order_updates       = isset($view_data['order_updates']) && is_array($view_data['order_updates']) ? $view_data['order_updates'] : [];
$order_updates_total = isset($view_data['order_updates_total']) ? absint($view_data['order_updates_total']) : count($order_updates);

$show_onboarding = isset($view_data['show_onboarding']) ? (bool) $view_data['show_onboarding'] : false;
$statuses        = isset($view_data['statuses']) && is_array($view_data['statuses']) ? $view_data['statuses'] : array();

// Build a color → status lookup once per render so every card view can
// resolve its label by a single array access. Lowercased to match the
// strtolower() compare in the card view; an addon that injects custom
// statuses with uppercase hex still maps correctly.
$status_lookup_by_color = array();
foreach ($statuses as $status) {
	$color = isset($status['color']) ? strtolower((string) $status['color']) : '';
	if ('' !== $color) {
		$status_lookup_by_color[$color] = $status;
	}
}

$settings = wp_parse_args(
	$settings,
	[
		'enable_assignee'            => true,
		'enable_color'               => true,
		'enable_internal_note'       => true,
		'enable_customer_note'       => true,
		'enable_solved_state'        => true,
		'allow_deletion'             => false,
	]
);
?>
<div class="awts_panel awts_container">

	<?php if ( $show_onboarding ) : ?>
		<?php View::render( 'src/Welcome/Views/OnboardingBannerView' ); ?>
	<?php endif; ?>

	<!-- Update cards -->
	<div class="awts_update_list">
		<?php if (! empty($order_updates)) : ?>
			<?php foreach ($order_updates as $card_variables) : ?>
				<?php View::render('src/Admin/Orders/Views/OrderUpdateCardViewModern', [
					'settings'               => $settings,
					'card_variables'         => $card_variables,
					'order_id'               => $order_id,
					'statuses'               => $statuses,
					'status_lookup_by_color' => $status_lookup_by_color,
				]); ?>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="awts_empty"><?php esc_html_e( 'No updates have been saved for this order yet.', 'order-updates-for-woo' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Load more -->
	<?php if ($order_updates_total > count($order_updates)) : ?>
		<div style="margin-bottom:12px;">
			<button
				type="button"
				class="awts_btn awts_btn_light awts_load_more_updates"
				data-awts-order-id="<?php echo esc_attr((string) $order_id); ?>"
				data-awts-offset="<?php echo esc_attr((string) count($order_updates)); ?>"
			><?php esc_html_e( 'Load more', 'order-updates-for-woo' ); ?></button>
		</div>
	<?php endif; ?>

	<!-- Add Update -->
	<div class="awts_form_trigger">
		<button type="button" class="awts_btn awts_btn_primary awts_toggle_form" data-awts-mode="add">
			<?php esc_html_e( 'Add new update', 'order-updates-for-woo' ); ?>
		</button>
		<button type="button" class="awts_refresh_updates" data-awts-order-id="<?php echo esc_attr((string) $order_id); ?>" title="<?php echo esc_attr__( 'Refresh updates', 'order-updates-for-woo' ); ?>">
			<?php echo Icons::dashicon( 'update-alt', __( 'Refresh updates', 'order-updates-for-woo' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</button>
	</div>

</div>

<div class="awts_popover" hidden>
	<?php View::render('src/Admin/Orders/Views/OrderUpdateFormView', [
		'settings' => $settings,
		'order_id' => $order_id,
		'statuses' => isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array(),
	] ); ?>
</div>
