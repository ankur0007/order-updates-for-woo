<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Notifications;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_REST_Request;

final class NotificationScheduler {
	/**
	 * Inject dependencies.
	 *
	 * @param AsyncJob $async_job Injected dependency.
	 */
	public function __construct( private AsyncJob $async_job ) {}

	/** Hook notification scheduling to the after-update-save event. */
	public function init(): void {
		add_action( 'order_updates_for_woo_after_update_save', array( $this, 'schedule_notifications' ), 10, 5 );
	}

	/**
	 * Queue admin / creator / assignee emails (and admin-bar rows) for a saved update.
	 *
	 * @param int             $update_id         The saved update id.
	 * @param array           $validated_payload Validated request payload.
	 * @param array           $update_data       The data written to the update row.
	 * @param WP_REST_Request $request           The REST request.
	 * @param array           $existing_update   Prior row on edit; empty on create.
	 */
	public function schedule_notifications( int $update_id, array $validated_payload, array $update_data, WP_REST_Request $request, array $existing_update = array() ): void {
		$is_edit          = ! empty( $existing_update );
		$new_assignee_id  = absint( $validated_payload['assignee_id'] ?? 0 );
		$old_assignee_id  = absint( $existing_update['assignee_user_id'] ?? 0 );
		$assignee_changed = $new_assignee_id !== $old_assignee_id;

		$admin_context = 'created';

		if ( $is_edit && $assignee_changed ) {
			$admin_context = 'assignee_changed';
		} elseif ( $is_edit ) {
			$admin_context = 'updated';
		}

		$admin_email   = (string) get_option( 'admin_email' );
		$admin_user    = get_user_by( 'email', $admin_email );
		$admin_user_id = $admin_user ? (int) $admin_user->ID : 0;

		// Find the order's customer once so every branch below can skip them.
		// Otherwise customer-opened updates would email the customer
		// staff-only emails that include internal note bodies.
		$order_id_for_customer = absint( $update_data['order_id'] ?? $existing_update['order_id'] ?? 0 );
		$order_customer_id     = 0;
		if ( $order_id_for_customer > 0 && function_exists( 'wc_get_order' ) ) {
			$order_for_customer = wc_get_order( $order_id_for_customer );
			if ( $order_for_customer ) {
				$order_customer_id = absint( $order_for_customer->get_customer_id() );
			}
		}

		// De-dupe users already queued in this dispatch — one person can play
		// multiple roles (e.g. creator == new assignee).
		$notified_user_ids = array();

		// Admin + creator emails only fire on edits. Creators are never
		// emailed about their own actions; the assignee + customer emails
		// cover the create path.
		if ( $is_edit ) {
			if ( ! StaffEmailPreference::is_muted( $update_id, $admin_user_id ) ) {
				$this->async_job->queue(
					Constants::HOOK_ADMIN_NOTIFICATION,
					array(
						'update_id' => $update_id,
						'context'   => $admin_context,
					)
				);
				if ( $admin_user_id > 0 ) {
					$notified_user_ids[] = $admin_user_id;
				}
			}

			$creator_id = absint( $update_data['created_by'] ?? 0 );
			$creator    = $creator_id ? get_userdata( $creator_id ) : null;

			if (
				$creator
				&& $creator_id !== $order_customer_id
				&& $creator->user_email !== $admin_email
				&& ! in_array( $creator_id, $notified_user_ids, true )
				&& ! StaffEmailPreference::is_muted( $update_id, $creator_id )
			) {
				$this->async_job->queue(
					Constants::HOOK_ADMIN_NOTIFICATION,
					array(
						'update_id'         => $update_id,
						'recipient_user_id' => $creator_id,
						'context'           => $admin_context,
					)
				);
				$notified_user_ids[] = $creator_id;
			}
		}

		if ( $assignee_changed ) {
			// The actor is whoever made the reassignment, so the email can
			// say "John reassigned…" instead of crediting the creator.
			$actor_user_id = get_current_user_id();
			$actor_user    = get_userdata( $actor_user_id );
			$actor_name    = $actor_user instanceof \WP_User ? (string) $actor_user->display_name : '';

			$update_title = (string) ( $update_data['title'] ?? $existing_update['title'] ?? '' );
			$order_id     = absint( $update_data['order_id'] ?? $existing_update['order_id'] ?? 0 );
			$creator_id   = absint( $existing_update['created_by'] ?? $update_data['created_by'] ?? 0 );

			// Admin bar — old assignee learns they were unassigned, creator
			// learns their ticket changed hands. The new assignee already gets
			// an admin-bar row from sync_assignee's admin_bar_assigned action,
			// so we skip it here to avoid duplicates.
			if (
				$old_assignee_id
				&& $old_assignee_id !== $order_customer_id
				&& $old_assignee_id !== $actor_user_id
				&& $old_assignee_id !== $new_assignee_id
			) {
				AdminBarNotificationStore::add_unassigned( $update_id, $order_id, $update_title, $old_assignee_id, $actor_name );
			}

			if (
				$creator_id
				&& $creator_id !== $order_customer_id
				&& $creator_id !== $actor_user_id
				&& $creator_id !== $new_assignee_id
				&& $creator_id !== $old_assignee_id
			) {
				AdminBarNotificationStore::add_assignee_changed( $update_id, $order_id, $update_title, $creator_id, $actor_name );
			}

			if (
				$new_assignee_id
				&& $new_assignee_id !== $order_customer_id
				&& ! in_array( $new_assignee_id, $notified_user_ids, true )
				&& ! StaffEmailPreference::is_muted( $update_id, $new_assignee_id )
			) {
				$this->async_job->queue(
					Constants::HOOK_ASSIGNEE_NOTIFICATION,
					array(
						'update_id'        => $update_id,
						'assignee_user_id' => $new_assignee_id,
						'context'          => ( $is_edit && $old_assignee_id ) ? 'reassigned' : 'assigned',
						'actor_user_id'    => $actor_user_id,
					)
				);
				$notified_user_ids[] = $new_assignee_id;
			}

			if (
				$old_assignee_id
				&& $old_assignee_id !== $order_customer_id
				&& ! in_array( $old_assignee_id, $notified_user_ids, true )
				&& ! StaffEmailPreference::is_muted( $update_id, $old_assignee_id )
			) {
				$this->async_job->queue(
					Constants::HOOK_ASSIGNEE_NOTIFICATION,
					array(
						'update_id'        => $update_id,
						'assignee_user_id' => $old_assignee_id,
						'context'          => 'unassigned',
						'actor_user_id'    => $actor_user_id,
					)
				);
			}
		}
	}
}
