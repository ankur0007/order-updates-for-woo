<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Audit\DeletedUpdatesLog;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class DeleteUpdateEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb    $order_updates_db   Injected dependency.
	 * @param AttachmentService $attachment_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentService $attachment_service
	) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * Permission check for the route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update_id = absint( $request->get_param( 'update_id' ) );
		$update    = $update_id ? $this->order_updates_db->get_update( $update_id ) : array();
		$order_id  = absint( is_array( $update ) ? ( $update['order_id'] ?? 0 ) : 0 );

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to delete this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$update    = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		$notify_customer = (bool) $request->get_param( 'notify_customer' );

		// Send the courtesy email BEFORE the delete so the order context is
		// still loadable. Only fires when the customer could actually see this
		// update (customer_visible=1) — silent deletes on hidden drafts skip.
		if ( $notify_customer && ! empty( $update['customer_visible'] ) ) {
			$this->send_deletion_email_to_customer( $update );
		}

		// Write the deletion record to the order audit log BEFORE the row is
		// gone — captures the same tracking-log content the admin would see
		// in the tab, so the deletion leaves a permanent trail.
		$this->record_audit_note( $update, $update_id );

		do_action( 'order_updates_for_woo_before_delete_update', $update_id, $update, $request );

		if ( ! $this->order_updates_db->delete_order_update( $update_id ) ) {
			return new WP_Error( 'order_updates_for_woo_delete_failed', __( 'Could not save the order update.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		$this->attachment_service->delete_all_for_update(
			absint( $update['order_id'] ?? 0 ),
			$update_id
		);

		// Notify the creator + assignee AFTER delete completes. The delete
		// cascade sweeps every admin-bar entry tied to this update from
		// every user's bar — if we added notifications beforehand, they
		// would be wiped by that sweep.
		$this->maybe_notify_creator_of_deletion( $update );
		$this->maybe_notify_assignee_of_deletion( $update );

		do_action( 'order_updates_for_woo_after_delete_update', $update_id, $update, $request );

		$response = array(
			'message'  => __( 'Update deleted.', 'order-updates-for-woo' ),
			'updateId' => $update_id,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_delete_update_response', $response, $request ) );
	}

	private function send_deletion_email_to_customer( array $update ): void {
		$order = $this->resolve_order( absint( $update['order_id'] ?? 0 ) );

		if ( ! $order ) {
			return;
		}

		$mailer = function_exists( 'WC' ) ? \WC()->mailer() : null;
		$emails = $mailer ? $mailer->get_emails() : array();
		$email  = $emails[ Constants::EMAIL_ID_CUSTOMER_UPDATE_DELETED ] ?? null;

		if ( $email instanceof \OrderUpdatesForWoo\Frontend\Notifications\Emails\CustomerUpdateDeletedEmail ) {
			$email->trigger_for_deletion( $order, (string) ( $update['title'] ?? '' ) );
		}
	}

	/**
	 * Tell the creator their update was deleted — only when someone other
	 * than the creator performed the delete. Same "creator never gets
	 * emailed about their own action" rule that applies everywhere else.
	 */
	private function maybe_notify_creator_of_deletion( array $update ): void {
		$creator_id = absint( $update['created_by'] ?? 0 );
		$actor_id   = get_current_user_id();

		if ( ! $creator_id || $creator_id === $actor_id ) {
			return;
		}

		$creator = get_userdata( $creator_id );

		if ( ! $creator instanceof \WP_User ) {
			return;
		}

		$order = $this->resolve_order( absint( $update['order_id'] ?? 0 ) );

		if ( ! $order ) {
			return;
		}

		// Customer-opened updates store the customer's user_id in created_by.
		// They already got the customer-facing deleted email above, so skip
		// the staff-flavoured "creator" email here to avoid a duplicate.
		if ( $creator_id === (int) $order->get_customer_id() ) {
			return;
		}

		$actor      = wp_get_current_user();
		$actor_name = $actor && $actor->exists() ? (string) $actor->display_name : __( 'A team member', 'order-updates-for-woo' );

		$mailer = function_exists( 'WC' ) ? \WC()->mailer() : null;
		$emails = $mailer ? $mailer->get_emails() : array();
		$email  = $emails[ Constants::EMAIL_ID_CREATOR_UPDATE_DELETED ] ?? null;

		if ( $email instanceof \OrderUpdatesForWoo\Admin\Notifications\Emails\CreatorUpdateDeletedEmail ) {
			$email->trigger_for_deletion( $order, $creator, (string) ( $update['title'] ?? '' ), $actor_name, 'creator' );
		}

		AdminBarNotificationStore::add_deleted(
			absint( $update['id'] ?? 0 ),
			absint( $update['order_id'] ?? 0 ),
			(string) ( $update['title'] ?? '' ),
			$creator_id,
			$actor_name
		);
	}

	/**
	 * Tell the assignee their assigned update was deleted — same "skip when
	 * actor / same as creator / is the order customer" rules as the creator
	 * path. Reuses CreatorUpdateDeletedEmail with a role param so we don't
	 * fragment the email into a second WC class with the same body.
	 */
	private function maybe_notify_assignee_of_deletion( array $update ): void {
		$assignee_id = absint( $update['assignee_user_id'] ?? 0 );
		$actor_id    = get_current_user_id();
		$creator_id  = absint( $update['created_by'] ?? 0 );

		if ( ! $assignee_id || $assignee_id === $actor_id || $assignee_id === $creator_id ) {
			return;
		}

		$order = $this->resolve_order( absint( $update['order_id'] ?? 0 ) );

		if ( ! $order ) {
			return;
		}

		// Same customer-leak gate as the creator path — assignee should
		// never resolve to the order's customer, but defense-in-depth.
		if ( $assignee_id === (int) $order->get_customer_id() ) {
			return;
		}

		$assignee = get_userdata( $assignee_id );

		if ( ! $assignee instanceof \WP_User ) {
			return;
		}

		$actor      = wp_get_current_user();
		$actor_name = $actor && $actor->exists() ? (string) $actor->display_name : __( 'A team member', 'order-updates-for-woo' );

		$mailer = function_exists( 'WC' ) ? \WC()->mailer() : null;
		$emails = $mailer ? $mailer->get_emails() : array();
		$email  = $emails[ Constants::EMAIL_ID_CREATOR_UPDATE_DELETED ] ?? null;

		if ( $email instanceof \OrderUpdatesForWoo\Admin\Notifications\Emails\CreatorUpdateDeletedEmail ) {
			$email->trigger_for_deletion( $order, $assignee, (string) ( $update['title'] ?? '' ), $actor_name, 'assignee' );
		}

		AdminBarNotificationStore::add_deleted(
			absint( $update['id'] ?? 0 ),
			absint( $update['order_id'] ?? 0 ),
			(string) ( $update['title'] ?? '' ),
			$assignee_id,
			$actor_name
		);
	}

	/**
	 * Snapshot the update's full tracking-log into the order's deletion
	 * audit log (rendered by DeletedUpdatesMetaBox on the order edit page).
	 * We own this storage end-to-end, so there's no in-UI delete surface
	 * to fight — the audit is preserved by design.
	 */
	private function record_audit_note( array $update, int $update_id ): void {
		$order = $this->resolve_order( absint( $update['order_id'] ?? 0 ) );

		if ( ! $order ) {
			return;
		}

		$actor = wp_get_current_user();

		DeletedUpdatesLog::record(
			$order,
			array(
				'update_id'       => $update_id,
				'title'           => (string) ( $update['title'] ?? '' ),
				'deleted_at'      => current_time( 'mysql', true ),
				'deleted_by_id'   => $actor && $actor->exists() ? (int) $actor->ID : 0,
				'deleted_by_name' => $actor && $actor->exists() ? (string) $actor->display_name : __( 'Unknown user', 'order-updates-for-woo' ),
				'events'          => $this->order_updates_db->get_update_action_history( $update_id ),
			)
		);
	}
}
