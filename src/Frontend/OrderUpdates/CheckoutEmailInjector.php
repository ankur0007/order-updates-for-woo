<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\OrderUpdates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WC_Email;
use WC_Order;

/**
 * Inject a "Have a question?" block into customer-facing order emails so the
 * customer can jump straight to the updates page and write a note to the team.
 *
 * The link uses the guest URL with the order_key — CustomerOrderUpdatesController
 * redirects logged-in owners to the MyAccount endpoint automatically, so one
 * URL covers both cases.
 */
final class CheckoutEmailInjector {
	/**
	 * WC customer-facing email IDs we inject into. New-account and refunded
	 * emails are intentionally excluded (no order / not order-conversation).
	 */
	private const TARGET_EMAIL_IDS = array(
		'customer_processing_order',
		'customer_completed_order',
		'customer_on_hold_order',
		'customer_invoice',
	);

	public function init(): void {
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_block' ), 20, 4 );
	}

	/**
	 * @param WC_Order $order
	 * @param bool     $sent_to_admin
	 * @param bool     $plain_text
	 * @param WC_Email $email
	 */
	public function render_block( $order, $sent_to_admin, $plain_text, $email = null ): void {
		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}

		$email_id = $email instanceof WC_Email ? (string) $email->id : '';

		if ( ! in_array( $email_id, self::TARGET_EMAIL_IDS, true ) ) {
			return;
		}

		$url = CustomerOrderUpdatesController::get_page_url( (int) $order->get_id(), (string) $order->get_order_key() );

		if ( '' === $url ) {
			return;
		}

		$heading = __( 'Have a question about this order?', 'order-updates-for-woo' );
		$text    = __( 'Our team is here to help. Send us a note with any question or request about your order.', 'order-updates-for-woo' );

		if ( $plain_text ) {
			echo "\n\n" . esc_html( $heading ) . "\n";
			echo esc_html( $text ) . "\n";
			echo esc_url( $url ) . "\n";

			return;
		}

		?>
		<table cellspacing="0" cellpadding="0" border="0" role="presentation" style="width:100%;margin:24px 0 0;">
			<tr>
				<td style="padding:18px 20px;background:#f6f7fb;border:1px solid #e5e7eb;border-radius:6px;font-family:Helvetica,Arial,sans-serif;color:#1f2937;">
					<p style="margin:0 0 6px;font-size:15px;font-weight:600;color:#111827;">
						<?php echo esc_html( $heading ); ?>
					</p>
					<p style="margin:0 0 14px;font-size:14px;line-height:1.5;color:#374151;">
						<?php echo esc_html( $text ); ?>
					</p>
					<p style="margin:0;">
						<a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:10px 18px;background:#2563eb;color:#ffffff;text-decoration:none;border-radius:6px;font-size:14px;font-weight:600;">
							<?php esc_html_e( 'Write a note to our team', 'order-updates-for-woo' ); ?>
						</a>
					</p>
				</td>
			</tr>
		</table>
		<?php
	}
}
