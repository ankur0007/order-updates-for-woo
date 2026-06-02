<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class NotifyCustomerEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)/notify';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AsyncJob $async_job
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );

		if ( $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to send customer notifications.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$note_id   = absint( $request->get_param( 'note_id' ) );

		if ( empty( $this->order_updates_db->get_update( $update_id )['id'] ) ) {
			return $this->update_not_found_error();
		}

		$note = $this->order_updates_db->get_customer_note_by_id( $note_id );

		if ( empty( $note['id'] ) || absint( $note['update_id'] ) !== $update_id ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Customer note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		if ( ! empty( $note['notified_at'] ) || ! empty( $note['queued_at'] ) ) {
			return new WP_Error( 'order_updates_for_woo_already_notified', __( 'This customer note has already been queued or sent.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		$force       = rest_sanitize_boolean( $request->get_param( 'force' ) );
		$update      = $this->order_updates_db->get_update( $update_id );
		$order       = wc_get_order( (int) ( $update['order_id'] ?? 0 ) );
		$customer_id = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;

		if ( ! $force && ! CustomerEmailPreference::get( (int) ( $update['order_id'] ?? 0 ), $customer_id ) ) {
			return rest_ensure_response(
				array(
					'opted_out' => true,
					'message'   => __( 'This customer has opted out of email notifications.', 'order-updates-for-woo' ),
				) 
			);
		}

		do_action( 'order_updates_for_woo_before_notify_customer', $update_id, $note_id, $note, $request );

		$queued = $this->async_job->queue(
			Constants::HOOK_CUSTOMER_NOTIFICATION,
			array(
				'update_id' => $update_id,
				'note_id'   => $note_id,
			)
		);

		if ( ! $queued ) {
			return new WP_Error( 'order_updates_for_woo_notification_queue_failed', __( 'Could not queue the customer notification.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		$queued_at_utc = current_time( 'mysql', true );
		$this->order_updates_db->mark_customer_note_queued( $note_id, $queued_at_utc );

		do_action( 'order_updates_for_woo_after_notify_customer', $update_id, $note_id, $queued_at_utc, $request );

		$response = array(
			'message'     => __( 'Customer notification queued.', 'order-updates-for-woo' ),
			'updateId'    => $update_id,
			'noteId'      => $note_id,
			'status'      => 'queued',
			'hook'        => Constants::HOOK_CUSTOMER_NOTIFICATION,
			'queuedAt'    => DateHelper::format_date( $queued_at_utc ),
			'queuedAtUtc' => $queued_at_utc,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_notify_customer_response', $response, $request ) );
	}
}
