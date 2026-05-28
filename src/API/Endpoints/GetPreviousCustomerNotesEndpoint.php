<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GetPreviousCustomerNotesEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes/previous';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $customer_service
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

		$update    = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );
		$order_id  = absint( $update['order_id'] ?? 0 );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		if ( $this->customer_service->can_view_order( $order_id, $order_key ) ) {
			return true;
		}

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to view this update.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$before_id = absint( $request->get_param( 'before_note_id' ) );
		$limit     = min( absint( $request->get_param( 'limit' ) ?: Constants::CUSTOMER_NOTES_PAGE_SIZE ), 50 );

		$update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_update',
				__( 'Update not found.', 'order-updates-for-woo' ),
				array( 'status' => 404 )
			);
		}

		$order       = wc_get_order( (int) ( $update['order_id'] ?? 0 ) );
		$customer_id = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;

		$paged = $this->order_updates_db->get_customer_notes_paged( $update_id, $limit, $before_id );

		$notes = array_map(
			fn( array $note ) => $this->customer_service->format_customer_thread_note( $note, $customer_id ),
			$paged['notes']
		);

		$response = array(
			'notes'    => $notes,
			'has_more' => $paged['has_more'],
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_previous_customer_notes_response', $response, $update_id, $request ) );
	}
}
