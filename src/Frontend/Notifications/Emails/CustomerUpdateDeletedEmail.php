<?php
/**
 * Customer "update removed" email — sent when staff deletes an update and
 * picks the "Notify customer & delete" option in the inline confirm. The
 * record is about to vanish, so the trigger takes the snapshot data inline
 * instead of looking it up by ID.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications\Emails;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;

final class CustomerUpdateDeletedEmail extends OrderUpdateEmailBase {
	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->id             = Constants::EMAIL_ID_CUSTOMER_UPDATE_DELETED;
		$this->title          = __( 'Customer notice — update removed', 'order-updates-for-woo' );
		$this->description    = __( 'Send a courtesy email to the customer when staff deletes an update they could previously see.', 'order-updates-for-woo' );
		$this->customer_email = true;

		parent::__construct( $order_updates_db );
		$this->template_html = 'src/Frontend/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * Snapshot trigger — the update record is being deleted in the same
	 * request, so we accept order + title directly rather than re-loading
	 * from the DB after the row is gone.
	 */
	public function trigger_for_deletion( WC_Order $order, string $update_title ): bool {
		$this->reset_trigger_state();

		$billing_email = (string) $order->get_billing_email();

		if ( '' === $billing_email ) {
			return false;
		}

		// Snapshot trigger skips load_context (no update row to load) so the
		// base class's placeholder seeding never runs — set them here so
		// {site_title} / {order_number} in the subject template resolve.
		$this->placeholders = array(
			'{site_title}'   => $this->get_blogname(),
			'{order_number}' => (string) $order->get_order_number(),
		);

		$this->recipient     = sanitize_email( $billing_email );
		$this->greeting_name = (string) $order->get_billing_first_name();
		$this->intro_text    = sprintf(
			/* translators: %s: order number. */
			__( 'An update on your order #%s has been removed by our team.', 'order-updates-for-woo' ),
			$order->get_order_number()
		);
		$this->note_label    = __( 'Removed update', 'order-updates-for-woo' );
		$this->note_content  = $update_title;
		$this->detail_rows   = array();
		$this->status_label  = __( 'Update removed', 'order-updates-for-woo' );
		$this->action_url    = (string) $order->get_view_order_url();
		$this->action_label  = __( 'View order', 'order-updates-for-woo' );

		$this->object = $order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] An update on order #{order_number} was removed', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Update removed', 'order-updates-for-woo' );
	}
}
