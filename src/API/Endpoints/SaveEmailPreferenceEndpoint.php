<?php
/**
 * REST endpoint — save email preference.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "save email preference" REST request.
 */
final class SaveEmailPreferenceEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/customer-email-preference';

	/**
	 * Inject dependencies.
	 *
	 * @param CustomerOrderUpdatesService $customer_service Injected dependency.
	 */
	public function __construct(
		private CustomerOrderUpdatesService $customer_service
	) {}

	/** Register the REST route. */
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

	/**
	 * Permission check for the route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$order_id  = absint( $request->get_param( 'order_id' ) );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		if ( $this->customer_service->can_view_order( $order_id, $order_key ) ) {
			return true;
		}

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to update this preference.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$enabled  = rest_sanitize_boolean( $request->get_param( 'enabled' ) );

		// When staff calls this endpoint, update the customer's preference,
		// not the staff member's own preference.
		if ( $this->is_authorized_for_order( $order_id ) ) {
			$order       = wc_get_order( $order_id );
			$customer_id = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;
		} else {
			$customer_id = get_current_user_id();
		}

		do_action( 'order_updates_for_woo_before_save_customer_email_preference', $order_id, $customer_id, $enabled, $request );

		CustomerEmailPreference::set( $order_id, $customer_id, $enabled );

		do_action( 'order_updates_for_woo_after_save_customer_email_preference', $order_id, $customer_id, $enabled, $request );

		$response = array( 'enabled' => $enabled );

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_save_customer_email_preference_response', $response, $request ) );
	}
}
