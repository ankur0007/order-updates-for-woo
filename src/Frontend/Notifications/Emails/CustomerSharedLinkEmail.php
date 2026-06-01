<?php
/**
 * Email sent when staff regenerates the no-login chat link and opts to
 * notify the customer. Carries the fresh URL.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications\Emails;

use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\SharedLink;
use WC_Order;

final class CustomerSharedLinkEmail extends OrderUpdateEmailBase {
	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->id             = Constants::EMAIL_ID_CUSTOMER_SHARED_LINK;
		$this->title          = __( 'Customer shared link refresh', 'order-updates-for-woo' );
		$this->description    = __( 'Send the customer the fresh no-login chat link after staff regenerates it.', 'order-updates-for-woo' );
		$this->customer_email = true;

		parent::__construct( $order_updates_db );
		$this->template_html = 'src/Frontend/Notifications/Templates/order-update-notification.php';
	}

	/** Trigger by order, not by update — the link is order-level. */
	public function trigger( int $order_id ): bool {
		$this->reset_trigger_state();

		$order = $order_id ? wc_get_order( $order_id ) : null;
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$billing_email = (string) $order->get_billing_email();
		if ( '' === $billing_email ) {
			return false;
		}

		$hash = (string) $order->get_meta( SharedLink::META_HASH, true );
		if ( '' === $hash ) {
			return false;
		}

		$this->order         = $order;
		$this->recipient     = sanitize_email( $billing_email );
		$this->greeting_name = (string) $order->get_billing_first_name();
		$this->intro_text    = sprintf(
			/* translators: %s: order number */
			__( 'We refreshed the chat link for your order #%s. Please use the new link below from now on — the previous one no longer works.', 'order-updates-for-woo' ),
			$order->get_order_number()
		);
		$this->action_url   = CustomerOrderUpdatesController::get_shared_link_url( (int) $order->get_id(), $hash );
		$this->action_label = __( 'Open chat', 'order-updates-for-woo' );
		$this->status_label = __( 'Chat link refreshed', 'order-updates-for-woo' );

		$this->object = $order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] Fresh chat link for order #{order_number}', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Your chat link was refreshed', 'order-updates-for-woo' );
	}
}
