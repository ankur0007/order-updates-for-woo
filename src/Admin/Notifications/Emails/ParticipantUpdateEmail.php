<?php
/**
 * Email sent to a follower of an order update when a new note is added by
 * someone else. Mirrors the assignee email's "note added" shape but uses
 * neutral participant wording, so a creator or repliers who aren't currently
 * assigned still get the right copy.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications\Emails;

use OrderUpdatesForWoo\Helpers\AssigneeHelper;
use OrderUpdatesForWoo\Helpers\UpdateAuthorHelper;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class ParticipantUpdateEmail extends OrderUpdateEmailBase {
	public function __construct( OrderUpdatesDb $order_updates_db, AttachmentsDb $attachments_db ) {
		$this->id             = Constants::EMAIL_ID_PARTICIPANT_UPDATE;
		$this->title          = __( 'Order updates: Participant activity', 'order-updates-for-woo' );
		$this->description    = __( 'Sent to followers of an order update when a new note is added by someone else.', 'order-updates-for-woo' );
		$this->customer_email = false;

		parent::__construct( $order_updates_db );
		$this->attachments_db = $attachments_db;
		$this->template_html  = 'src/Admin/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * @param int    $update_id         Update ID.
	 * @param int    $recipient_user_id Participant to notify.
	 * @param int    $note_id           Note that triggered this email.
	 * @param string $note_type         'internal' or 'customer'.
	 * @param int    $actor_user_id     Staff user who added the note (skipped on send).
	 * @param string $actor_name        Display name of the actor, when known.
	 */
	public function trigger( int $update_id, int $recipient_user_id, int $note_id, string $note_type, int $actor_user_id = 0, string $actor_name = '' ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) || ! $recipient_user_id || ! $note_id ) {
			return false;
		}

		$recipient = get_user_by( 'id', $recipient_user_id );

		if ( ! $recipient || empty( $recipient->user_email ) ) {
			return false;
		}

		$note_row = 'customer' === $note_type
			? (array) $this->order_updates_db->get_customer_note_by_id( $note_id )
			: (array) $this->order_updates_db->get_update_note_by_id( $note_id );

		if ( empty( $note_row['note'] ) ) {
			return false;
		}

		$order_number = $this->order ? $this->order->get_order_number() : '';
		$author       = '' !== $actor_name
			? $actor_name
			: (string) ( $note_row['created_by_name'] ?? __( 'A team member', 'order-updates-for-woo' ) );

		$this->recipient     = sanitize_email( (string) $recipient->user_email );
		$this->greeting_name = (string) $recipient->first_name;

		if ( 'customer' === $note_type ) {
			/* translators: %s: order number. */
			$this->subject    = sprintf( __( '[{site_title}] New customer message for #%s', 'order-updates-for-woo' ), $order_number );
			/* translators: 1: author name, 2: order number. */
			$this->intro_text = sprintf( __( '%1$s sent a new message on order #%2$s.', 'order-updates-for-woo' ), $author, $order_number );
			$this->note_label = __( 'Customer note', 'order-updates-for-woo' );
		} else {
			/* translators: %s: order number. */
			$this->subject    = sprintf( __( '[{site_title}] New internal note on order #%s', 'order-updates-for-woo' ), $order_number );
			/* translators: 1: author name, 2: order number. */
			$this->intro_text = sprintf( __( '%1$s added an internal note on order #%2$s.', 'order-updates-for-woo' ), $author, $order_number );
			$this->note_label = __( 'Internal note', 'order-updates-for-woo' );
		}

		$this->set_note_from_row( $note_row );
		$this->populate_note_attachments( $note_id, $note_type );

		$this->detail_rows = apply_filters(
			'order_updates_for_woo_participant_email_detail_rows',
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
			$note_row,
			$this
		);

		$this->action_url            = $this->order ? ( (string) $this->order->get_edit_order_url() . '#awts-update-' . absint( $update_id ) ) : '';
		$this->action_label          = __( 'View and reply', 'order-updates-for-woo' );
		$this->status_label          = 'customer' === $note_type
			? __( 'New customer message', 'order-updates-for-woo' )
			: __( 'New internal note', 'order-updates-for-woo' );
		$this->customer_visible_pill = UpdateState::is_customer_visible( (array) $this->order_update );
		$this->object                = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	public function get_default_subject(): string {
		return __( '[{site_title}] New activity on order #{order_number}', 'order-updates-for-woo' );
	}

	public function get_default_heading(): string {
		return __( 'New activity on an order update', 'order-updates-for-woo' );
	}
}
