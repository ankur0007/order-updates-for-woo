<?php
/**
 * Email sent to a staff member when they're @mentioned in an internal note.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications\Emails;

use OrderUpdatesForWoo\Helpers\UpdateAuthorHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Internal Mention Email.
 */
final class InternalMentionEmail extends OrderUpdateEmailBase {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 * @param AttachmentsDb  $attachments_db Injected dependency.
	 */
	public function __construct( OrderUpdatesDb $order_updates_db, AttachmentsDb $attachments_db ) {
		$this->id             = Constants::EMAIL_ID_INTERNAL_MENTION;
		$this->title          = __( 'Order updates: Internal mention', 'order-updates-for-woo' );
		$this->description    = __( 'Sent to a team member when they are tagged in an internal note on an order update.', 'order-updates-for-woo' );
		$this->customer_email = false;

		parent::__construct( $order_updates_db );
		$this->attachments_db = $attachments_db;
		$this->template_html  = 'src/Admin/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * Send the mention notification to a tagged staff member.
	 *
	 * @param int    $update_id         Update ID.
	 * @param int    $note_id           Note containing the mention.
	 * @param int    $recipient_user_id Tagged user to notify.
	 * @param string $mentioned_by_name Display name of who tagged them.
	 */
	public function trigger( int $update_id, int $note_id, int $recipient_user_id, string $mentioned_by_name ): bool {
		$this->reset_trigger_state();

		if ( ! $this->load_context( $update_id ) || ! $recipient_user_id || ! $note_id ) {
			return false;
		}

		$recipient = get_user_by( 'id', $recipient_user_id );

		if ( ! $recipient || empty( $recipient->user_email ) ) {
			return false;
		}

		$note_row = $this->find_note_row( $update_id, $note_id );

		if ( empty( $note_row ) ) {
			return false;
		}

		$order_number        = $this->order ? $this->order->get_order_number() : '';
		$this->recipient     = sanitize_email( (string) $recipient->user_email );
		$this->greeting_name = (string) $recipient->first_name;
		$author              = '' !== $mentioned_by_name
			? $mentioned_by_name
			: (string) ( $note_row['created_by_name'] ?? '' );

		$this->intro_text = sprintf(
			/* translators: 1: author name, 2: order number. */
			__( '%1$s tagged you in an internal note on order #%2$s.', 'order-updates-for-woo' ),
			$author,
			$order_number
		);
		$this->note_label = __( 'Internal note', 'order-updates-for-woo' );
		$this->set_note_from_row( $note_row );
		$this->populate_note_attachments( $note_id, Constants::NOTE_TYPE_INTERNAL );

		$this->detail_rows = apply_filters(
			'order_updates_for_woo_mention_email_detail_rows',
			array(
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
		$this->status_label          = __( 'You were mentioned', 'order-updates-for-woo' );
		$this->customer_visible_pill = (bool) ( $this->order_update['customer_visible'] ?? false );
		$this->object                = $this->order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	/**
	 * Find one internal-note row on an update by note id.
	 *
	 * @param int $update_id Update ID.
	 * @param int $note_id   Note ID to find.
	 */
	private function find_note_row( int $update_id, int $note_id ): array {
		foreach ( $this->order_updates_db->get_update_notes( $update_id ) as $row ) {
			if ( absint( $row['id'] ?? 0 ) === $note_id ) {
				return $row;
			}
		}

		return array();
	}

	/**
	 * Default email subject.
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] You were tagged on order #{order_number}', 'order-updates-for-woo' );
	}

	/**
	 * Default email heading.
	 */
	public function get_default_heading(): string {
		return __( 'You were tagged on an order update', 'order-updates-for-woo' );
	}
}
