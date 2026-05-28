<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Returns the prior revisions of a customer-thread note so the UI can show
 * a "View history" panel. The endpoint is open to anyone allowed to view the
 * thread itself — staff (via order-edit caps) and the order's customer.
 */
final class GetCustomerNoteHistoryEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)/history';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $viewer_service
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
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
		$order_id = absint( $update['order_id'] ?? 0 );

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		if ( $this->viewer_service->is_acting_as_customer( $order_id, $order_key ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view this history.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$note_id   = absint( $request->get_param( 'note_id' ) );
		$note      = $this->order_updates_db->get_customer_note_by_id( $note_id );

		if ( empty( $note['id'] ) || absint( $note['update_id'] ?? 0 ) !== $update_id ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Customer note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		$rows = $this->order_updates_db->get_customer_note_history( $note_id );

		$revisions = array_map( static function ( array $row ): array {
			return array(
				'id'             => (int) $row['id'],
				'prior_note'     => (string) $row['prior_note'],
				'edited_by_name' => (string) ( $row['edited_by_name'] ?? '' ),
				'edited_at'      => DateHelper::format_date( (string) ( $row['edited_at'] ?? '' ) ),
				'edited_at_utc'  => (string) ( $row['edited_at'] ?? '' ),
			);
		}, $rows );

		$response = array(
			'note_id'   => $note_id,
			'revisions' => $revisions,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_get_customer_note_history_response', $response, $note_id, $request ) );
	}
}
