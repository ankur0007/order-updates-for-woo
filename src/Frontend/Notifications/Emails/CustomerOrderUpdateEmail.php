<?php
/**
 * Customer order update email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Frontend\Notifications\Emails;

use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Helpers\UpdateStatusHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class CustomerOrderUpdateEmail extends OrderUpdateEmailBase {
	public function __construct( OrderUpdatesDb $order_updates_db, AttachmentsDb $attachments_db ) {
		$this->id             = Constants::EMAIL_ID_CUSTOMER_UPDATE;
		$this->title          = __( 'Order update notification for customer', 'order-updates-for-woo' );
		$this->description    = __( 'Send an email to the customer when a visible order update is created.', 'order-updates-for-woo' );
		$this->customer_email = true;

		parent::__construct( $order_updates_db );
		$this->attachments_db = $attachments_db;
		$this->template_html  = 'src/Frontend/Notifications/Templates/order-update-notification.php';
	}

	public function trigger( int $update_id, int $note_id = 0, string $context = '' ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) || ! UpdateState::is_customer_visible( (array) $this->order_update ) ) {
			return false;
		}

		$billing_email = $this->order ? $this->order->get_billing_email() : '';

		if ( ! $billing_email ) {
			return false;
		}

		$customer_note = $note_id ? $this->order_updates_db->get_customer_note_by_id( $note_id ) : array();
		$note_kind     = (string) ( $customer_note['kind'] ?? 'note' );
		$note_content  = (string) ( $customer_note['note'] ?? '' );

		// A "system event" email has no quoted note body — just the action
		// (resolved, status change, etc). Customer-message contexts like
		// 'customer_reply' are not system events; they have a real note.
		$is_system_context = in_array( $context, array( 'resolved', 'rated', 'status_change' ), true );
		$is_system_event   = ( '' !== $note_kind && 'note' !== $note_kind ) || $is_system_context;

		if ( ! $is_system_event && '' === $note_content ) {
			return false;
		}

		$this->recipient     = sanitize_email( $billing_email );
		$this->greeting_name = $this->order ? (string) $this->order->get_billing_first_name() : '';

		// `customer_submitted` is the receipt case: customer just opened a new
		// update from the portal and we're confirming we received it. The
		// generic "we have a new update for you" wording reads as if the store
		// is updating them — confusing when they're the one who initiated.
		$order_number        = $this->order ? $this->order->get_order_number() : '';
		$is_customer_receipt = 'customer_submitted' === $context;

		if ( $is_customer_receipt ) {
			$this->subject = sprintf(
				/* translators: %s: order number. */
				__( '[{site_title}] We have received your update on order #%s', 'order-updates-for-woo' ),
				$order_number
			);
			$this->intro_text = __( 'Thanks for opening this update. A member of our team will respond shortly.', 'order-updates-for-woo' );
		} else {
			$this->intro_text = sprintf(
				/* translators: %s: order number. */
				__( 'We have a new update for this order #%s. Please find details below.', 'order-updates-for-woo' ),
				$order_number
			);
		}

		if ( $is_system_event ) {
			$this->note_label   = '';
			$this->note_content = '';
		} else {
			// Receipt embeds the customer's own message under a "Your message"
			// header so they have a clean record of what they sent; other
			// triggers label it "Customer note" as the staff would see it.
			$this->note_label = $is_customer_receipt
				? __( 'Your message', 'order-updates-for-woo' )
				: __( 'Customer note', 'order-updates-for-woo' );
			$this->set_note_from_row( $customer_note );
		}

		if ( ! $is_system_event ) {
			$this->populate_note_attachments( $note_id, Constants::NOTE_TYPE_CUSTOMER );
		}

		$base_detail_rows = array();

		if ( $is_system_event && '' !== $note_content ) {
			$base_detail_rows[] = array(
				'label' => __( 'What changed', 'order-updates-for-woo' ),
				'value' => $note_content,
			);
		}

		$base_detail_rows[] = array(
			'label' => __( 'Solved', 'order-updates-for-woo' ),
			'value' => UpdateStatusHelper::get_formatted_is_solved( $this->order_update ),
		);
		$base_detail_rows[] = array(
			'label' => __( 'Created by', 'order-updates-for-woo' ),
			'value' => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
		);

		$this->detail_rows           = apply_filters(
			'order_updates_for_woo_customer_email_detail_rows',
			$base_detail_rows,
			$this->order_update,
			$this->order,
			$this
		);
		$this->action_url            = CustomerOrderUpdatesController::get_signed_email_url(
			(int) $this->order->get_id()
		) . '#awts-update-' . absint( $update_id );
		$this->action_label          = __( 'View and reply', 'order-updates-for-woo' );
		$this->status_label          = __( 'Update on your order', 'order-updates-for-woo' );
		$this->customer_visible_pill = true;

		$this->object = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] New update for order #{order_number}', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'Order update', 'order-updates-for-woo' );
	}
}
