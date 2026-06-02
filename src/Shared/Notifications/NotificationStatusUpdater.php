<?php
/**
 * Persist notification status after successful sends.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class NotificationStatusUpdater {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 */
	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	/** Hook the "notification sent" stamps to their dispatch events. */
	public function init(): void {
		add_action( Constants::HOOK_ASSIGNEE_SENT, array( $this, 'handle_assignee_notification_sent' ), 10, 4 );
		add_action( Constants::HOOK_CUSTOMER_SENT, array( $this, 'handle_customer_notification_sent' ), 10, 3 );
		add_action( Constants::HOOK_RATING_REQUEST_SENT, array( $this, 'handle_rating_request_sent' ), 10, 2 );
	}

	/**
	 * Stamp when an assignee email was sent (skips the "unassigned" notice).
	 *
	 * @param int    $update_id        Update id.
	 * @param int    $assignee_user_id Assignee who was emailed.
	 * @param string $context          Notification context.
	 * @param string $notified_at      Send time (GMT mysql).
	 */
	public function handle_assignee_notification_sent( int $update_id, int $assignee_user_id, string $context, string $notified_at ): void {
		if ( ! $update_id || ! $assignee_user_id || 'unassigned' === $context || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_assignee_notified( $update_id, $assignee_user_id, $notified_at );
	}

	/**
	 * Stamp when a customer note's email was sent.
	 *
	 * @param int    $update_id   Update id.
	 * @param int    $note_id     Customer note id.
	 * @param string $notified_at Send time (GMT mysql).
	 */
	public function handle_customer_notification_sent( int $update_id, int $note_id, string $notified_at ): void {
		if ( ! $update_id || ! $note_id || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_customer_note_notified( $note_id, $notified_at );
	}

	/**
	 * Stamp when a rating-request email was sent.
	 *
	 * @param int    $update_id   Update id.
	 * @param string $notified_at Send time (GMT mysql).
	 */
	public function handle_rating_request_sent( int $update_id, string $notified_at ): void {
		if ( ! $update_id || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_rating_request_notified( $update_id, $notified_at );
	}
}
