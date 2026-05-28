<?php
/**
 * Notice shown above the customer-notes thread.
 *
 * Two variants:
 *   - Guest order  → "every note auto-emailed to billing address"
 *   - Logged-in    → "visible on My Account"
 *
 * Override: copy to your-theme/order-updates-for-woo/admin/card/customer-visibility-notice.php
 *
 * @var array $view_data {
 *     @type bool $is_guest_order  True when the order's customer_id is 0.
 * }
 */

declare(strict_types=1);

use OrderUpdatesForWoo\Helpers\Icons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$is_guest_order      = ! empty( $view_data['is_guest_order'] );
$is_customer_visible = ! empty( $view_data['is_customer_visible'] );
?>
<?php if ( ! $is_customer_visible ) : ?>
	<div class="awts_customer_notes_warning awts_customer_notes_warning--hidden">
		<?php echo Icons::dashicon( 'hidden' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<strong><?php esc_html_e( 'Hidden from customer:', 'order-updates-for-woo' ); ?></strong>
			<?php esc_html_e( 'this update is not yet visible to the customer. Write a customer note below to make it visible and notify them.', 'order-updates-for-woo' ); ?>
		</span>
	</div>
<?php elseif ( $is_guest_order ) : ?>
	<div class="awts_guest_customer_notice">
		<?php echo Icons::dashicon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<span>
			<strong><?php esc_html_e( 'Guest customer:', 'order-updates-for-woo' ); ?></strong>
			<?php esc_html_e( 'no My Account access — every note here is auto-emailed to the billing address.', 'order-updates-for-woo' ); ?>
		</span>
	</div>
<?php else : ?>
	<div class="awts_customer_notes_warning">
		<?php echo Icons::dashicon( 'warning' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php esc_html_e( 'Visible to the customer on their My Account page.', 'order-updates-for-woo' ); ?>
	</div>
<?php endif; ?>
