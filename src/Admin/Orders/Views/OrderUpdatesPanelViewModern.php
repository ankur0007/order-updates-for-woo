<?php
/**
 * Admin order updates panel view — modern layout.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

// Local template vars only — this file is required inside View::render()'s
// method scope, so these never touch real WordPress globals.
// phpcs:disable WordPress.WP.GlobalVariablesOverride.Prohibited

use OrderUpdatesForWoo\Helpers\Icons;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data           = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$settings            = isset( $view_data['settings'] ) && is_array( $view_data['settings'] ) ? $view_data['settings'] : array();
$order_id            = isset( $view_data['order_id'] ) ? absint( $view_data['order_id'] ) : 0;
$order_updates       = isset( $view_data['order_updates'] ) && is_array( $view_data['order_updates'] ) ? $view_data['order_updates'] : array();
$order_updates_total = isset( $view_data['order_updates_total'] ) ? absint( $view_data['order_updates_total'] ) : count( $order_updates );

$show_onboarding      = isset( $view_data['show_onboarding'] ) ? (bool) $view_data['show_onboarding'] : false;
$statuses             = isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array();
$customer_url         = isset( $view_data['customer_url'] ) ? (string) $view_data['customer_url'] : '';
$shared_link          = isset( $view_data['shared_link'] ) && is_array( $view_data['shared_link'] ) ? $view_data['shared_link'] : array();
$link_days_left       = isset( $shared_link['days_left'] ) ? (int) $shared_link['days_left'] : 0;
$link_default_days    = isset( $shared_link['default_days'] ) ? (int) $shared_link['default_days'] : 30;
$link_expiry_endpoint = isset( $shared_link['expiry_endpoint'] ) ? (string) $shared_link['expiry_endpoint'] : '';
$link_regen_endpoint  = isset( $shared_link['regenerate_endpoint'] ) ? (string) $shared_link['regenerate_endpoint'] : '';

// Build a color → status lookup once per render so every card view can
// resolve its label by a single array access. Lowercased to match the
// strtolower() compare in the card view; an addon that injects custom
// statuses with uppercase hex still maps correctly.
$status_lookup_by_color = array();
foreach ( $statuses as $status ) {
	$color = isset( $status['color'] ) ? strtolower( (string) $status['color'] ) : '';
	if ( '' !== $color ) {
		$status_lookup_by_color[ $color ] = $status;
	}
}

$settings = wp_parse_args(
	$settings,
	array(
		'enable_assignee'      => true,
		'enable_internal_note' => true,
		'enable_customer_note' => true,
		'enable_solved_state'  => true,
		'allow_deletion'       => false,
	)
);
?>
<div class="awts_panel awts_container">

	<?php if ( $show_onboarding ) : ?>
		<?php View::render( 'src/Welcome/Views/OnboardingBannerView' ); ?>
	<?php endif; ?>

	<?php if ( '' !== $customer_url ) : ?>
		<!-- Stateful hash in order meta. Changing the expiry does not change this URL. -->
		<div
			class="awts_panel__customer_link"
			data-awts-link-expiry-endpoint="<?php echo esc_url( $link_expiry_endpoint ); ?>"
			data-awts-link-regenerate-endpoint="<?php echo esc_url( $link_regen_endpoint ); ?>"
		>
			<strong><?php esc_html_e( 'Customers no-login chat link:', 'order-updates-for-woo' ); ?></strong>
			<code class="awts_panel__customer_link_url" data-awts-link-display><?php echo esc_html( $customer_url ); ?></code>
			<button
				type="button"
				class="button"
				data-awts-copy-link="<?php echo esc_attr( $customer_url ); ?>"
			><?php esc_html_e( 'Copy link', 'order-updates-for-woo' ); ?></button>

			<label class="awts_panel__customer_link_expiry">
				<?php esc_html_e( 'Expires in', 'order-updates-for-woo' ); ?>
				<input
					type="number"
					min="1"
					max="365"
					step="1"
					value="<?php echo esc_attr( (string) ( $link_days_left > 0 ? $link_days_left : $link_default_days ) ); ?>"
					data-awts-link-days
					class="awts_panel__customer_link_days"
				/>
				<?php esc_html_e( 'days', 'order-updates-for-woo' ); ?>
			</label>

			<button
				type="button"
				class="button button-link-delete"
				data-awts-link-regenerate
			><?php esc_html_e( 'Regenerate', 'order-updates-for-woo' ); ?></button>

			<span
				data-awts-link-status
				class="awts_panel__customer_link_status"
				role="status"
				aria-live="polite"
			></span>

			<div
				data-awts-link-confirm
				hidden
				class="awts_panel__customer_link_confirm"
			>
				<p class="awts_panel__customer_link_confirm_text"><?php esc_html_e( 'Regenerate the link? The current one will stop working immediately.', 'order-updates-for-woo' ); ?></p>
				<label class="awts_panel__customer_link_notify">
					<input type="checkbox" data-awts-link-notify />
					<?php esc_html_e( 'Email the new link to the customer', 'order-updates-for-woo' ); ?>
				</label>
				<button type="button" class="button button-primary" data-awts-link-confirm-go><?php esc_html_e( 'Regenerate now', 'order-updates-for-woo' ); ?></button>
				<button type="button" class="button" data-awts-link-confirm-cancel><?php esc_html_e( 'Cancel', 'order-updates-for-woo' ); ?></button>
			</div>
		</div>
	<?php endif; ?>

	<!-- Update cards -->
	<div class="awts_update_list">
		<?php if ( ! empty( $order_updates ) ) : ?>
			<?php foreach ( $order_updates as $card_variables ) : ?>
				<?php
				View::render(
					'src/Admin/Orders/Views/OrderUpdateCardViewModern',
					array(
						'settings'               => $settings,
						'card_variables'         => $card_variables,
						'order_id'               => $order_id,
						'statuses'               => $statuses,
						'status_lookup_by_color' => $status_lookup_by_color,
					)
				);
				?>
			<?php endforeach; ?>
		<?php else : ?>
			<p class="awts_empty"><?php esc_html_e( 'No updates have been saved for this order yet.', 'order-updates-for-woo' ); ?></p>
		<?php endif; ?>
	</div>

	<!-- Load more -->
	<?php if ( $order_updates_total > count( $order_updates ) ) : ?>
		<div style="margin-bottom:12px;">
			<button
				type="button"
				class="awts_btn awts_btn_light awts_load_more_updates"
				data-awts-order-id="<?php echo esc_attr( (string) $order_id ); ?>"
				data-awts-offset="<?php echo esc_attr( (string) count( $order_updates ) ); ?>"
			><?php esc_html_e( 'Load more', 'order-updates-for-woo' ); ?></button>
		</div>
	<?php endif; ?>

	<!-- Add Update -->
	<div class="awts_form_trigger">
		<button type="button" class="awts_btn awts_btn_primary awts_toggle_form" data-awts-mode="add">
			<?php esc_html_e( 'Add new update', 'order-updates-for-woo' ); ?>
		</button>
		<button type="button" class="awts_refresh_updates" data-awts-order-id="<?php echo esc_attr( (string) $order_id ); ?>" title="<?php echo esc_attr__( 'Refresh updates', 'order-updates-for-woo' ); ?>">
			<?php echo wp_kses_post( Icons::dashicon( 'update-alt', __( 'Refresh updates', 'order-updates-for-woo' ) ) ); ?>
		</button>
	</div>

</div>

<div class="awts_popover" hidden>
	<?php
	View::render(
		'src/Admin/Orders/Views/OrderUpdateFormView',
		array(
			'settings' => $settings,
			'order_id' => $order_id,
			'statuses' => isset( $view_data['statuses'] ) && is_array( $view_data['statuses'] ) ? $view_data['statuses'] : array(),
		) 
	);
	?>
</div>
