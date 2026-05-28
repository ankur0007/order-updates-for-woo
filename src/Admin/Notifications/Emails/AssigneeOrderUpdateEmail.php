<?php
/**
 * Assignee order update email.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications\Emails;

use OrderUpdatesForWoo\Helpers\AssigneeHelper;
use OrderUpdatesForWoo\Helpers\UpdateAuthorHelper;
use OrderUpdatesForWoo\Helpers\UpdateStatusHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Helpers\UpdateState;

/**
 * Email notification sent to the user assigned (or unassigned) from an order update.
 */
final class AssigneeOrderUpdateEmail extends OrderUpdateEmailBase {
	/**
	 * Constructor.
	 *
	 * @param OrderUpdatesDb $order_updates_db Database service.
	 * @param AttachmentsDb  $attachments_db   Used to fetch the file list for the triggering note so the email body shows the same attachments the recipient would see in the UI.
	 */
	public function __construct( OrderUpdatesDb $order_updates_db, AttachmentsDb $attachments_db ) {
		$this->id             = Constants::EMAIL_ID_ASSIGNEE_UPDATE;
		$this->title          = __('Order update notification for assignee', 'order-updates-for-woo');
		$this->description    = __('Send an email to the assigned user when a new order update is created.', 'order-updates-for-woo');
		$this->customer_email = false;

		parent::__construct( $order_updates_db );
		$this->attachments_db = $attachments_db;
		$this->template_html  = 'src/Admin/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * Trigger the email.
	 *
	 * @param int    $update_id         Update ID.
	 * @param int    $recipient_user_id User to notify.
	 * @param string $context           'assigned', 'reassigned', or 'unassigned'.
	 * @param int    $trigger_note_id   Optional — pin the email body to a specific note.
	 * @param string $trigger_note_type 'customer' or 'internal'.
	 * @param int    $actor_user_id     Staff member who performed the assignment change. Used in the intro line so the recipient sees who reassigned them.
	 */
	public function trigger( int $update_id, int $recipient_user_id, string $context = 'assigned', int $trigger_note_id = 0, string $trigger_note_type = '', int $actor_user_id = 0 ): bool {
		// Reset state up front — same instance is reused across calls in a
		// request, so unset branches would otherwise leak prior values.
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) || ! $recipient_user_id ) {
			return false;
		}

		$recipient_user = get_user_by( 'id', $recipient_user_id );

		if ( ! $recipient_user || empty( $recipient_user->user_email ) ) {
			return false;
		}

		$order_number        = $this->order ? $this->order->get_order_number() : '';
		$this->recipient     = sanitize_email( (string) $recipient_user->user_email );
		$this->greeting_name = (string) $recipient_user->first_name;
		$is_unassigned       = 'unassigned' === $context;
		$is_new_note         = 'new_internal_note' === $context;
		$is_customer_msg     = in_array( $context, array( 'customer_submitted', 'customer_reply' ), true );
		$is_system_event     = in_array( $context, array( 'status_change', 'rated', 'resolved' ), true );

		// Resolve the actor (the staff member who actioned the assignment
		// change) so the intro can name them. Falls back to "A team member"
		// when the actor isn't available, e.g. for older queue payloads or
		// when the change was triggered by a system process.
		$actor_user = $actor_user_id ? get_user_by( 'id', $actor_user_id ) : null;
		$actor_name = $actor_user ? trim( (string) $actor_user->display_name ) : '';
		if ( '' === $actor_name ) {
			$actor_name = __( 'A team member', 'order-updates-for-woo' );
		}

		if ( $is_unassigned ) {
			/* translators: %s: order number. */
			$this->subject    = sprintf( __('[{site_title}] You have been unassigned from order update #%s', 'order-updates-for-woo'), $order_number );
			/* translators: 1: actor name, 2: order number. */
			$this->intro_text = sprintf( __('%1$s unassigned you from an update for order #%2$s. No further action is needed.', 'order-updates-for-woo'), $actor_name, $order_number );
		} elseif ( $is_system_event ) {
			if ( 'rated' === $context ) {
				/* translators: %s: order number. */
				$this->subject    = sprintf( __( '[{site_title}] Customer rated your update on order #%s', 'order-updates-for-woo' ), $order_number );
				/* translators: %s: order number. */
				$this->intro_text = sprintf( __( 'The customer rated an update you are assigned to on order #%s. Details below.', 'order-updates-for-woo' ), $order_number );
			} elseif ( 'resolved' === $context ) {
				/* translators: %s: order number. */
				$this->subject    = sprintf( __( '[{site_title}] Your assigned update on order #%s was marked solved', 'order-updates-for-woo' ), $order_number );
				/* translators: 1: actor name, 2: order number. */
				$this->intro_text = sprintf( __( '%1$s marked an update you are assigned to as solved on order #%2$s.', 'order-updates-for-woo' ), $actor_name, $order_number );
			} else {
				/* translators: %s: order number. */
				$this->subject    = sprintf( __( '[{site_title}] Status changed on your assigned update for #%s', 'order-updates-for-woo' ), $order_number );
				/* translators: 1: actor name, 2: order number. */
				$this->intro_text = sprintf( __( '%1$s changed the status of an update you are assigned to on order #%2$s. Details below.', 'order-updates-for-woo' ), $actor_name, $order_number );
			}

			$this->note_label             = '';
			$this->note_content           = '';
			$this->secondary_note_label   = '';
			$this->secondary_note_content = '';

			// 'rated' context — show the actual stars + comment in the body
			// so the assignee can act on the feedback without clicking through.
			if ( 'rated' === $context ) {
				$rating       = $this->order_updates_db->get_rating_for_update( $update_id );
				$rating_stars = max( 0, min( 5, (int) ( $rating['stars'] ?? 0 ) ) );
				$comment      = trim( (string) ( $rating['comment'] ?? '' ) );

				if ( $rating_stars > 0 ) {
					$star_visual         = str_repeat( '★', $rating_stars ) . str_repeat( '☆', 5 - $rating_stars );
					$this->note_label    = __( 'Customer rating', 'order-updates-for-woo' );
					$this->note_content  = $star_visual . ' (' . $rating_stars . '/5)';

					if ( '' !== $comment ) {
						$this->secondary_note_label   = __( 'Customer comment', 'order-updates-for-woo' );
						$this->secondary_note_content = $comment;
					}
				}
			}

			// 'status_change' context — name the new status in the body so
			// the assignee knows what changed.
			if ( 'status_change' === $context ) {
				$status_label = UpdateStatusHelper::get_status_label_for_update( (array) $this->order_update );

				if ( '' !== $status_label ) {
					$this->note_label   = __( 'New status', 'order-updates-for-woo' );
					$this->note_content = $status_label;
				}
			}
		} elseif ( $is_new_note ) {
			/* translators: %s: order number. */
			$this->subject    = sprintf( __( '[{site_title}] New note on your assigned update for #%s', 'order-updates-for-woo' ), $order_number );
			/* translators: %s: order number. */
			$this->intro_text = sprintf( __( 'A new note was added to an update you are assigned to for order #%s.', 'order-updates-for-woo' ), $order_number );

			// Render the exact note that triggered this email (`note_id` +
			// `note_type` in the queue payload). Fall back to "latest of
			// either type" if the caller didn't pass them.
			if ( $trigger_note_id > 0 && 'customer' === $trigger_note_type ) {
				$row = $this->order_updates_db->get_customer_note_by_id( $trigger_note_id );
				$this->note_label = __( 'Customer note', 'order-updates-for-woo' );
				$this->set_note_from_row( is_array( $row ) ? $row : array() );
				$this->populate_note_attachments( $trigger_note_id, Constants::NOTE_TYPE_CUSTOMER );
			} elseif ( $trigger_note_id > 0 && 'internal' === $trigger_note_type ) {
				$row = $this->order_updates_db->get_update_note_by_id( $trigger_note_id );
				$this->note_label = __( 'Internal note', 'order-updates-for-woo' );
				$this->set_note_from_row( is_array( $row ) ? $row : array() );
				$this->populate_note_attachments( $trigger_note_id, Constants::NOTE_TYPE_INTERNAL );
			} else {
				$internal_row = $this->get_latest_internal_note_row();
				$customer_row = $this->get_latest_customer_note_row();

				if ( ! empty( $internal_row['note'] ) ) {
					$this->note_label = __( 'Internal note', 'order-updates-for-woo' );
					$this->set_note_from_row( $internal_row );
					$this->populate_note_attachments( (int) ( $internal_row['id'] ?? 0 ), Constants::NOTE_TYPE_INTERNAL );
				} elseif ( ! empty( $customer_row['note'] ) ) {
					$this->note_label = __( 'Customer note', 'order-updates-for-woo' );
					$this->set_note_from_row( $customer_row );
					$this->populate_note_attachments( (int) ( $customer_row['id'] ?? 0 ), Constants::NOTE_TYPE_CUSTOMER );
				} else {
					$this->note_label = __( 'Note', 'order-updates-for-woo' );
				}
			}

			$this->secondary_note_label   = '';
			$this->secondary_note_content = '';
		} elseif ( $is_customer_msg ) {
			$customer_name    = $this->order ? trim( $this->order->get_billing_first_name() . ' ' . $this->order->get_billing_last_name() ) : '';
			$customer_name    = '' !== $customer_name ? $customer_name : __( 'Customer', 'order-updates-for-woo' );

			if ( 'customer_submitted' === $context ) {
				/* translators: %s: order number. */
				$this->subject    = sprintf( __( '[{site_title}] New customer update on order #%s', 'order-updates-for-woo' ), $order_number );
				/* translators: 1: customer name, 2: order number. */
				$this->intro_text = sprintf( __( '%1$s opened a new update on order #%2$s and it was assigned to you. Their message is shown below.', 'order-updates-for-woo' ), $customer_name, $order_number );
			} else {
				/* translators: %s: order number. */
				$this->subject    = sprintf( __( '[{site_title}] New customer message for #%s', 'order-updates-for-woo' ), $order_number );
				/* translators: 1: customer name, 2: order number. */
				$this->intro_text = sprintf( __( '%1$s has sent a new message on order #%2$s. Their message is shown below.', 'order-updates-for-woo' ), $customer_name, $order_number );
			}

			$this->note_label = __( 'Customer note', 'order-updates-for-woo' );

			// Pin to the trigger note (avoids the race where a later
			// customer submission would replace this email's body).
			if ( $trigger_note_id > 0 && 'customer' === $trigger_note_type ) {
				$row = $this->order_updates_db->get_customer_note_by_id( $trigger_note_id );
				$this->set_note_from_row( is_array( $row ) ? $row : array() );
				$this->populate_note_attachments( $trigger_note_id, Constants::NOTE_TYPE_CUSTOMER );
			} else {
				$row = $this->get_latest_customer_note_row();
				$this->set_note_from_row( $row );
				$this->populate_note_attachments( (int) ( $row['id'] ?? 0 ), Constants::NOTE_TYPE_CUSTOMER );
			}

			$this->secondary_note_label   = '';
			$this->secondary_note_content = '';
		} else {
			$intro = 'reassigned' === $context
				/* translators: 1: actor name, 2: order number. */
				? __('%1$s reassigned an update to you for order #%2$s. Details below.', 'order-updates-for-woo')
				/* translators: 1: actor name, 2: order number. */
				: __('%1$s assigned you to an update for order #%2$s. Details below.', 'order-updates-for-woo');

			$this->intro_text = sprintf( $intro, $actor_name, $order_number );

			// Prefer the customer note for customer-initiated updates;
			// internal note for staff-initiated. Fall back to the other if
			// the preferred source is empty so the message always renders.
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

			$this->secondary_note_label   = '';
			$this->secondary_note_content = '';
		}

		$detail_rows = array();

		if ( ! $is_unassigned ) {
			$detail_rows[] = array(
				'label' => __( 'Assigned to', 'order-updates-for-woo' ),
				'value' => AssigneeHelper::get_formatted_assigned_to( $this->order_update ),
			);
		}

		$detail_rows[] = array(
			'label' => __( 'Created by', 'order-updates-for-woo' ),
			'value' => UpdateAuthorHelper::get_formatted_created_by( $this->order_update ),
		);

		// Customer-visible status is rendered as the dedicated pill below the
		// meta rows, so no detail-row entry needed here — would duplicate.

		$this->detail_rows = apply_filters(
			'order_updates_for_woo_assignee_email_detail_rows',
			$detail_rows,
			$this->order_update,
			$this->order,
			$this
		);

		$this->action_url   = $this->order ? ( (string) $this->order->get_edit_order_url() . '#awts-update-' . absint( $update_id ) ) : '';
		$this->action_label = __( 'View and reply', 'order-updates-for-woo' );

		// Status pill — short label that summarises why this email was sent.
		if ( $is_unassigned ) {
			$this->status_label = __( 'Unassigned', 'order-updates-for-woo' );
		} elseif ( $is_customer_msg ) {
			if ( 'customer_reply' === $context ) {
				$this->status_label = __( 'Customer replied', 'order-updates-for-woo' );
			} elseif ( 'customer_submitted' === $context ) {
				$this->status_label = __( 'New customer update', 'order-updates-for-woo' );
			} else {
				$this->status_label = __( 'New customer message', 'order-updates-for-woo' );
			}
		} elseif ( $is_system_event ) {
			if ( 'rated' === $context ) {
				$this->status_label = __( 'Customer rated', 'order-updates-for-woo' );
			} elseif ( 'resolved' === $context ) {
				$this->status_label = __( 'Marked solved', 'order-updates-for-woo' );
			} else {
				$this->status_label = __( 'Status changed', 'order-updates-for-woo' );
			}
		} else {
			$this->status_label = 'reassigned' === $context
				? __( 'Reassigned to you', 'order-updates-for-woo' )
				: __( 'Assigned to you', 'order-updates-for-woo' );
		}

		$this->customer_visible_pill = ! $is_unassigned && UpdateState::is_customer_visible( (array) $this->order_update );
		$this->object       = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		$this->setup_locale();

		$sent = $this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );

		$this->restore_locale();

		return (bool) $sent;
	}

	/**
	 * Default subject line.
	 */
	public function get_default_subject(): string {
		return __('[{site_title}] Order update assigned for #{order_number}', 'order-updates-for-woo');
	}

	/**
	 * Default email heading.
	 */
	public function get_default_heading(): string {
		return __('Order update assigned', 'order-updates-for-woo');
	}
}
