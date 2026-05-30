<?php
/**
 * Customer rating request email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications\Emails;

use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Helpers\UpdateState;

final class CustomerRatingRequestEmail extends OrderUpdateEmailBase {
	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->id             = Constants::EMAIL_ID_CUSTOMER_RATING_REQUEST;
		$this->title          = __( 'Customer rating request', 'order-updates-for-woo' );
		$this->description    = __( 'Send an email to the customer asking them to rate a resolved update.', 'order-updates-for-woo' );
		$this->customer_email = true;

		parent::__construct( $order_updates_db );
		$this->template_html = 'src/Frontend/Notifications/Templates/order-update-notification.php';
	}

	public function trigger( int $update_id ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) || ! UpdateState::is_resolved( (array) $this->order_update ) ) {
			return false;
		}

		$billing_email = $this->order ? $this->order->get_billing_email() : '';

		if ( ! $billing_email ) {
			return false;
		}

		$this->recipient    = sanitize_email( $billing_email );
		$this->greeting_name = $this->order ? (string) $this->order->get_billing_first_name() : '';
		$this->intro_text   = sprintf(
			/* translators: %s: order number. */
			__( 'We\'ve resolved an update on your order #%s. Could you take a moment to rate your experience?', 'order-updates-for-woo' ),
			$this->order ? $this->order->get_order_number() : ''
		);
		$this->note_label   = __( 'Update', 'order-updates-for-woo' );
		$this->note_content = (string) ( $this->order_update['title'] ?? '' );
		$this->detail_rows  = apply_filters(
			'order_updates_for_woo_rating_request_email_detail_rows',
			array(),
			$this->order_update,
			$this->order,
			$this
		);
		$this->action_url   = CustomerOrderUpdatesController::get_signed_email_url(
			(int) $this->order->get_id()
		) . '#awts-update-' . $update_id;
		$this->action_label = __( 'Leave a rating', 'order-updates-for-woo' );
		$this->status_label = __( 'How did we do?', 'order-updates-for-woo' );

		$this->object = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] How did we do on order #{order_number}?', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Rate your experience', 'order-updates-for-woo' );
	}
}
