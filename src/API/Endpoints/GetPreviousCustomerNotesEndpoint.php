<?php
/**
 * REST endpoint — get previous customer notes.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "get previous customer notes" REST request.
 */
final class GetPreviousCustomerNotesEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes/previous';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db Injected dependency.
	 * @param CustomerOrderUpdatesService $customer_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $customer_service
	) {}

	/** Register the REST route. */
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

	/**
	 * Permission check for the route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update    = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );
		$order_id  = absint( $update['order_id'] ?? 0 );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		// Staff path — full access regardless of visibility.
		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		// Customer path — must be acting as the order's customer AND the
		// update must be customer-visible. Stops update_id enumeration
		// against internal-only updates.
		if (
			$order_id
			&& UpdateState::is_customer_visible( is_array( $update ) ? $update : array() )
			&& $this->customer_service->is_acting_as_customer( $order_id, $order_key )
		) {
			return true;
		}

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to view this update.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id       = absint( $request->get_param( 'update_id' ) );
		$before_id       = absint( $request->get_param( 'before_note_id' ) );
		$requested_limit = absint( $request->get_param( 'limit' ) );
		$limit           = min( $requested_limit > 0 ? $requested_limit : Constants::CUSTOMER_NOTES_PAGE_SIZE, 50 );

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
