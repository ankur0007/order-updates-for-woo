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
	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	public function init(): void {
		add_action( Constants::HOOK_ASSIGNEE_SENT, array( $this, 'handle_assignee_notification_sent' ), 10, 4 );
		add_action( Constants::HOOK_CUSTOMER_SENT, array( $this, 'handle_customer_notification_sent' ), 10, 3 );
		add_action( Constants::HOOK_RATING_REQUEST_SENT, array( $this, 'handle_rating_request_sent' ), 10, 2 );
	}

	public function handle_assignee_notification_sent( int $update_id, int $assignee_user_id, string $context, string $notified_at ): void {
		if ( ! $update_id || ! $assignee_user_id || 'unassigned' === $context || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_assignee_notified( $update_id, $assignee_user_id, $notified_at );
	}

	public function handle_customer_notification_sent( int $update_id, int $note_id, string $notified_at ): void {
		if ( ! $update_id || ! $note_id || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_customer_note_notified( $note_id, $notified_at );
	}

	public function handle_rating_request_sent( int $update_id, string $notified_at ): void {
		if ( ! $update_id || '' === $notified_at ) {
			return;
		}

		$this->order_updates_db->mark_rating_request_notified( $update_id, $notified_at );
	}
}
