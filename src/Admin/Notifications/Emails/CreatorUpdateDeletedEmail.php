<?php
/**
 * Email to an update's creator when someone else deletes their update.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Notifications\Emails;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Notifications\OrderUpdateEmailBase;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;
use WP_User;

/**
 * Creator Update Deleted Email.
 */
final class CreatorUpdateDeletedEmail extends OrderUpdateEmailBase {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 */
	public function __construct( OrderUpdatesDb $order_updates_db ) {
		$this->id             = Constants::EMAIL_ID_CREATOR_UPDATE_DELETED;
		$this->title          = __( 'Creator notice — update deleted by another staff member', 'order-updates-for-woo' );
		$this->description    = __( 'Notify the creator of an update when a different staff member deletes it.', 'order-updates-for-woo' );
		$this->customer_email = false;

		parent::__construct( $order_updates_db );
		$this->template_html = 'src/Frontend/Notifications/Templates/order-update-notification.php';
	}

	/**
	 * Snapshot trigger — the update row is being deleted in the same
	 * request, so we take order + title + actor inline rather than
	 * looking them up after the row is gone.
	 *
	 * @param WC_Order $order          Order the update belonged to.
	 * @param WP_User  $recipient      Who to notify.
	 * @param string   $update_title   Title of the deleted update.
	 * @param string   $actor_name     Who deleted it.
	 * @param string   $recipient_role Recipient's role label (e.g. 'creator').
	 */
	public function trigger_for_deletion( WC_Order $order, WP_User $recipient, string $update_title, string $actor_name, string $recipient_role = 'creator' ): bool {
		$this->reset_trigger_state();

		$recipient_email = (string) $recipient->user_email;

		if ( '' === $recipient_email ) {
			return false;
		}

		$order_number = (string) $order->get_order_number();

		// Snapshot trigger skips load_context (no update row to load) so the
		// base class's placeholder seeding never runs — set them here so
		// {site_title} / {order_number} in the subject template resolve.
		$this->placeholders = array(
			'{site_title}'   => $this->get_blogname(),
			'{order_number}' => $order_number,
		);

		$this->recipient     = sanitize_email( $recipient_email );
		$this->greeting_name = (string) $recipient->first_name;
		// Wording branches on role so the recipient sees their relationship
		// to the deleted update reflected accurately. Caller picks the role
		// ('creator' or 'assignee') based on which user this notification is
		// for; the email body shape stays identical otherwise.
		$this->intro_text = 'assignee' === $recipient_role
			? sprintf(
				/* translators: 1: actor name, 2: order number. */
				__( '%1$s deleted an update that was assigned to you on order #%2$s.', 'order-updates-for-woo' ),
				$actor_name,
				$order_number
			)
			: sprintf(
				/* translators: 1: actor name, 2: order number. */
				__( '%1$s deleted an update you created on order #%2$s.', 'order-updates-for-woo' ),
				$actor_name,
				$order_number
			);
		$this->note_label   = __( 'Deleted update', 'order-updates-for-woo' );
		$this->note_content = $update_title;
		$this->detail_rows  = array(
			array(
				'label' => __( 'Deleted by', 'order-updates-for-woo' ),
				'value' => $actor_name,
			),
		);
		$this->status_label = __( 'Update deleted', 'order-updates-for-woo' );
		$this->action_url   = (string) $order->get_edit_order_url();
		$this->action_label = __( 'View order', 'order-updates-for-woo' );

		$this->object = $order;

		if ( ! $this->is_enabled() || ! $this->get_recipient() ) {
			return false;
		}

		return $this->send_with_locale();
	}

	/**
	 * Default email subject.
	 */
	public function get_default_subject(): string {
		return __( '[{site_title}] Your update on order #{order_number} was deleted', 'order-updates-for-woo' );
	}

	/**
	 * Default email heading.
	 */
	public function get_default_heading(): string {
		return __( 'Your update was deleted', 'order-updates-for-woo' );
	}
}
