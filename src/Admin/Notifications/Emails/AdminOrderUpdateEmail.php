<?php
/**
 * Admin order update email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications\Emails;

use OrderUpdatesForWoo\Helpers\AssigneeHelper;
use OrderUpdatesForWoo\Helpers\UpdateAuthorHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Helpers\UpdateState;

/**
 * Admin Order Update Email.
 */
final class AdminOrderUpdateEmail extends OrderUpdateEmailBase {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 * @param AttachmentsDb  $attachments_db Injected dependency.
	 */
	public function __construct( OrderUpdatesDb $order_updates_db, AttachmentsDb $attachments_db ) {
		$this->id             = Constants::EMAIL_ID_ADMIN_UPDATE;
		$this->title          = __( 'Order update notification for admin', 'order-updates-for-woo' );
		$this->description    = __( 'Send an email to the store admin when a new order update is created.', 'order-updates-for-woo' );
		$this->customer_email = false;

		parent::__construct( $order_updates_db );
		$this->attachments_db = $attachments_db;
		$this->template_html  = 'src/Admin/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * Trigger the email.
	 *
	 * @param int    $update_id         Update ID.
	 * @param int    $recipient_user_id Optional user to send to instead of the store admin.
	 * @param string $context           What triggered the email (e.g. 'created').
	 * @param int    $trigger_note_id   Note that triggered it, if any.
	 * @param string $trigger_note_type Note type (internal / customer), if any.
	 */
	public function trigger( int $update_id, int $recipient_user_id = 0, string $context = 'created', int $trigger_note_id = 0, string $trigger_note_type = '' ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) ) {
			return false;
		}

		if ( $recipient_user_id ) {
			$recipient_user      = get_user_by( 'id', $recipient_user_id );
			$this->recipient     = $recipient_user ? sanitize_email( (string) $recipient_user->user_email ) : '';
			$this->greeting_name = $recipient_user ? (string) $recipient_user->first_name : '';
		} else {
			// Falls back to the site admin when no specific recipient is set
			// (e.g. a customer opens an update before any staff owns the
			// thread). The greeting should address the admin reading the
			// email, not the creator who triggered it.
			$this->recipient     = (string) get_option( 'admin_email' );
			$admin_user          = get_user_by( 'email', $this->recipient );
			$this->greeting_name = $admin_user ? (string) $admin_user->first_name : '';
		}
		$this->intro_text            = $this->get_intro_text( $context );
		$this->status_label          = $this->get_status_label( $context );
		$this->customer_visible_pill = (bool) ( $this->order_update['customer_visible'] ?? false );

		$is_customer_msg = in_array( $context, array( 'customer_submitted', 'customer_reply' ), true );

		if ( 'rated' === $context ) {
			$this->subject    = __( '[{site_title}] Low rating on order #{order_number}', 'order-updates-for-woo' );
			$this->note_label = '';

			// Body carries the actual stars + comment so the admin can act on
			// the detractor signal directly from the inbox. Falls back to a
			// blank note when no rating row exists (data anomaly) — the intro
			// from get_intro_text still explains the email.
			$rating       = $this->order_updates_db->get_rating_for_update( $update_id );
			$rating_stars = max( 0, min( 5, (int) ( $rating['stars'] ?? 0 ) ) );
			$comment      = trim( (string) ( $rating['comment'] ?? '' ) );

			if ( $rating_stars > 0 ) {
				$star_visual        = str_repeat( '★', $rating_stars ) . str_repeat( '☆', 5 - $rating_stars );
				$this->note_label   = __( 'Customer rating', 'order-updates-for-woo' );
				$this->note_content = $star_visual . ' (' . $rating_stars . '/5)';

				if ( '' !== $comment ) {
					$this->secondary_note_label   = __( 'Customer comment', 'order-updates-for-woo' );
					$this->secondary_note_content = $comment;
				}
			}
		} elseif ( $is_customer_msg ) {
			$this->subject    = __( '[{site_title}] New customer message for #{order_number}', 'order-updates-for-woo' );
			$this->note_label = __( 'Customer note', 'order-updates-for-woo' );

			// Pin to the specific trigger note when the dispatcher passed it.
			// Falls back to "latest" for older / external dispatchers that
			// haven't been updated to send note_id.
			if ( $trigger_note_id > 0 && 'customer' === $trigger_note_type ) {
				$row = $this->order_updates_db->get_customer_note_by_id( $trigger_note_id );
				$this->set_note_from_row( is_array( $row ) ? $row : array() );
				$this->populate_note_attachments( $trigger_note_id, Constants::NOTE_TYPE_CUSTOMER );
			} else {
				$row = $this->get_latest_customer_note_row();
				$this->set_note_from_row( $row );
				$this->populate_note_attachments( (int) ( $row['id'] ?? 0 ), Constants::NOTE_TYPE_CUSTOMER );
			}
		} else {
			// Prefer customer note for customer-initiated updates (where
			// internal notes won't exist), internal otherwise. Fall back
			// to the other if the preferred source is empty.
			$customer_row    = $this->get_latest_customer_note_row();
			$internal_row    = $this->get_latest_internal_note_row();
			$prefer_customer = UpdateAuthorHelper::is_customer_initiated_update( (array) $this->order_update );

			if ( $prefer_customer && ! empty( $customer_row['note'] ) ) {
				$this->note_label = __( 'Customer note', 'order-updates-for-woo' );
				$this->set_note_from_row( $customer_row );
				$this->populate_note_attachments( (int) ( $customer_row['id'] ?? 0 ), Constants::NOTE_TYPE_CUSTOMER );
			} elseif ( ! empty( $internal_row['note'] ) ) {
				$this->note_label = __( 'Internal note', 'order-updates-for-woo' );
				$this->set_note_from_row( $internal_row );
				$this->populate_note_attachments( (int) ( $internal_row['id'] ?? 0 ), Constants::NOTE_TYPE_INTERNAL );
			} elseif ( ! empty( $customer_row['note'] ) ) {
				$this->note_label = __( 'Customer note', 'order-updates-for-woo' );
				$this->set_note_from_row( $customer_row );
				$this->populate_note_attachments( (int) ( $customer_row['id'] ?? 0 ), Constants::NOTE_TYPE_CUSTOMER );
			} else {
				$this->note_label = '';
			}
		}

		$this->detail_rows  = apply_filters(
			'order_updates_for_woo_admin_email_detail_rows',
			array(
				array(
					'label' => __( 'Assigned to', 'order-updates-for-woo' ),
					'value' => AssigneeHelper::get_formatted_assigned_to( $this->order_update ),
				),
				array(
					'label' => __( 'Created by', 'order-updates-for-woo' ),
					'value' => UpdateAuthorHelper::get_formatted_created_by( $this->order_update ),
				),
			),
			$this->order_update,
			$this->order,
			$this
		);
		$this->action_url   = $this->order ? ( (string) $this->order->get_edit_order_url() . '#awts-update-' . absint( $update_id ) ) : '';
		$this->action_label = __( 'View and reply', 'order-updates-for-woo' );
		$this->object       = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	/**
	 * Default email subject.
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] New update for #{order_number}', 'order-updates-for-woo' );
	}

	/**
	 * Default email heading.
	 */
	public function get_default_heading(): string {
		return __( 'Order update', 'order-updates-for-woo' );
	}


	/**
	 * Short status pill text shown above the email heading. Mirrors the
	 * intro_text contexts but in 1–3 words so it fits the badge.
	 *
	 * @param string $context What triggered the email.
	 */
	private function get_status_label( string $context ): string {
		switch ( $context ) {
			case 'customer_submitted':
				return __( 'New customer message', 'order-updates-for-woo' );
			case 'customer_reply':
				return __( 'Customer replied', 'order-updates-for-woo' );
			case 'assignee_changed':
				return __( 'Assignee changed', 'order-updates-for-woo' );
			case 'updated':
				return __( 'Update edited', 'order-updates-for-woo' );
			case 'rated':
				return __( 'Low rating', 'order-updates-for-woo' );
			default:
				return __( 'New update', 'order-updates-for-woo' );
		}
	}

	/**
	 * Lead paragraph text, varied by what triggered the email.
	 *
	 * @param string $context What triggered the email.
	 */
	private function get_intro_text( string $context = 'created' ): string {
		if ( 'rated' === $context ) {
			return __( 'A customer left a low rating on a resolved update. The score and their comment are shown below — review and follow up if needed.', 'order-updates-for-woo' );
		}

		if ( 'assignee_changed' === $context ) {
			// Use the bare assignee name, not get_formatted_assigned_to (which
			// returns "Assigned to <name> at <date>"). The wrapper sentence
			// already starts with "The assignee has been changed to" — pulling
			// in the formatted variant would double the "Assigned to" prefix.
			$assignee_name = AssigneeHelper::get_assignee_name( $this->order_update );
			/* translators: %s: assignee name. */
			return sprintf( __( 'The assignee has been changed to %s. Please find update details below.', 'order-updates-for-woo' ), $assignee_name );
		}

		if ( 'updated' === $context ) {
			return __( 'An order update has been modified. Please find update details below.', 'order-updates-for-woo' );
		}

		if ( 'customer_submitted' === $context ) {
			return __( 'A customer has submitted a new note from their order page. Please find details below.', 'order-updates-for-woo' );
		}

		if ( 'customer_reply' === $context ) {
			return __( 'The customer has replied with a new message on this update. Their message is shown below.', 'order-updates-for-woo' );
		}

		$notifications = array();

		if ( UpdateState::has_assignee( (array) $this->order_update ) ) {
			$notifications[] = __( 'assignee', 'order-updates-for-woo' );
		}

		if ( UpdateState::is_customer_visible( (array) $this->order_update ) ) {
			$notifications[] = __( 'customer', 'order-updates-for-woo' );
		}

		if ( empty( $notifications ) ) {
			return __( 'The new update has been created. Please find update details below.', 'order-updates-for-woo' );
		}

		/* translators: %s: list of notified parties (e.g. "assignee and customer"). */
		return sprintf( __( 'The new update has been created and notified to %s. Please find update details below.', 'order-updates-for-woo' ), implode( ' and ', $notifications ) );
	}
}
