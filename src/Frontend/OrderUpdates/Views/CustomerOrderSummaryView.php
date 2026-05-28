<?php
/**
 * Collapsible order summary shown above the customer-facing updates list.
 * Saves the customer a tab-switch back to the order email when writing a
 * note. Native <details> for open/close — no JS required.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Local file-scope template variables, not globals.
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound

$view_data = isset( $view_data ) && is_array( $view_data ) ? $view_data : array();
$summary   = isset( $view_data['summary'] ) && is_array( $view_data['summary'] ) ? $view_data['summary'] : array();

if ( empty( $summary ) ) {
	return;
}

$items          = isset( $summary['items'] ) && is_array( $summary['items'] ) ? $summary['items'] : array();
$order_number   = (string) ( $summary['order_number'] ?? '' );
$status_label   = (string) ( $summary['status_label'] ?? '' );
$status_slug    = (string) ( $summary['status_slug'] ?? '' );
$placed_at      = (string) ( $summary['placed_at'] ?? '' );
$subtotal       = (string) ( $summary['subtotal'] ?? '' );
$shipping_total = (string) ( $summary['shipping_total'] ?? '' );
$tax_total      = (string) ( $summary['tax_total'] ?? '' );
$total          = (string) ( $summary['total'] ?? '' );

/* translators: %s: order number */
$summary_heading = sprintf( __( 'Order #%s', 'order-updates-for-woo' ), $order_number );
?>
<details class="awts_cou_order_summary">
	<summary class="awts_cou_order_summary__summary">
		<span class="awts_cou_order_summary__title"><?php echo esc_html( $summary_heading ); ?></span>
		<?php if ( '' !== $status_label ) : ?>
			<span class="awts_cou_order_summary__status awts_cou_order_summary__status--<?php echo esc_attr( $status_slug ); ?>">
				<?php echo esc_html( $status_label ); ?>
			</span>
		<?php endif; ?>
		<?php if ( '' !== $placed_at ) : ?>
			<span class="awts_cou_order_summary__date">
				<?php
				/* translators: %s: human-readable date */
				printf( esc_html__( 'Placed on %s', 'order-updates-for-woo' ), esc_html( $placed_at ) );
				?>
			</span>
		<?php endif; ?>
		<span class="awts_cou_order_summary__chevron" aria-hidden="true">▾</span>
	</summary>

	<div class="awts_cou_order_summary__body">
		<?php if ( ! empty( $items ) ) : ?>
			<ul class="awts_cou_order_summary__items">
				<?php foreach ( $items as $item ) : ?>
					<li class="awts_cou_order_summary__item">
						<span class="awts_cou_order_summary__item_name">
							<?php echo esc_html( (string) ( $item['name'] ?? '' ) ); ?>
						</span>
						<span class="awts_cou_order_summary__item_qty">
							<?php
							/* translators: %d: quantity */
							printf( esc_html__( '× %d', 'order-updates-for-woo' ), (int) ( $item['qty'] ?? 0 ) );
							?>
						</span>
						<span class="awts_cou_order_summary__item_total">
							<?php echo wp_kses_post( (string) ( $item['line_total'] ?? '' ) ); ?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<dl class="awts_cou_order_summary__totals">
			<div class="awts_cou_order_summary__totals_row">
				<dt><?php esc_html_e( 'Subtotal', 'order-updates-for-woo' ); ?></dt>
				<dd><?php echo wp_kses_post( $subtotal ); ?></dd>
			</div>
			<?php if ( '' !== $shipping_total ) : ?>
				<div class="awts_cou_order_summary__totals_row">
					<dt><?php esc_html_e( 'Shipping', 'order-updates-for-woo' ); ?></dt>
					<dd><?php echo wp_kses_post( $shipping_total ); ?></dd>
				</div>
			<?php endif; ?>
			<?php if ( '' !== $tax_total ) : ?>
				<div class="awts_cou_order_summary__totals_row">
					<dt><?php esc_html_e( 'Tax', 'order-updates-for-woo' ); ?></dt>
					<dd><?php echo wp_kses_post( $tax_total ); ?></dd>
				</div>
			<?php endif; ?>
			<div class="awts_cou_order_summary__totals_row awts_cou_order_summary__totals_row--total">
				<dt><?php esc_html_e( 'Total', 'order-updates-for-woo' ); ?></dt>
				<dd><?php echo wp_kses_post( $total ); ?></dd>
			</div>
		</dl>

		<?php
		/**
		 * Fires inside the open order summary, after the totals block.
		 * Addons can append rows (tracking number, custom meta) here.
		 *
		 * @param array $summary The summary payload.
		 */
		do_action( 'order_updates_for_woo_customer_order_summary_after', $summary );
		?>
	</div>
</details>
