<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\CustomerOrderUpdatesController;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Updates\SharedLink;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** Admin endpoint to extend / shorten / regenerate the no-login chat link. */
final class SharedLinkEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE_BASE = '/orders/(?P<order_id>\d+)/shared-link';

	public function __construct( private AsyncJob $async_job ) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_BASE . '/expiry',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_set_expiry' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);

		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE_BASE . '/regenerate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_regenerate' ),
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

		if ( $this->is_authorized_for_order( absint( $request->get_param( 'order_id' ) ) ) ) {
			return true;
		}

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to manage this link.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	public function handle_set_expiry( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = $this->resolve_order( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$days = absint( $request->get_param( 'days' ) );
		if ( $days <= 0 ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_days',
				__( 'Pick a number of days between 1 and 365.', 'order-updates-for-woo' ),
				array( 'status' => 400 )
			);
		}

		$state = SharedLink::set_expiry( $order, $days, get_current_user_id() );

		return rest_ensure_response( $this->shape_response( $state, (int) $order->get_id() ) );
	}

	public function handle_regenerate( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order = $this->resolve_order( $request );
		if ( $order instanceof WP_Error ) {
			return $order;
		}

		$days = absint( $request->get_param( 'days' ) );
		if ( $days <= 0 ) {
			$days = Variables::getCustomerLinkExpiryDays();
		}

		$state = SharedLink::regenerate( $order, $days, get_current_user_id() );

		$response = $this->shape_response( $state, (int) $order->get_id() );

		if ( (bool) $request->get_param( 'notify_customer' ) ) {
			$this->async_job->queue(
				Constants::HOOK_SHARED_LINK_EMAIL,
				array( 'order_id' => (int) $order->get_id() )
			);
			$response['emailQueued'] = true;
		}

		return rest_ensure_response( $response );
	}

	private function resolve_order( WP_REST_Request $request ): WC_Order|WP_Error {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$order    = $order_id ? wc_get_order( $order_id ) : null;

		if ( $order instanceof WC_Order ) {
			return $order;
		}

		return new WP_Error(
			'order_updates_for_woo_not_found',
			__( 'Order not found.', 'order-updates-for-woo' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * @param array{hash:string,expires_at:int,days_left:int} $state
	 * @return array<string, mixed>
	 */
	private function shape_response( array $state, int $order_id ): array {
		$response = array(
			'hash'      => $state['hash'],
			'url'       => CustomerOrderUpdatesController::get_shared_link_url( $order_id, (string) $state['hash'] ),
			'expiresAt' => $state['expires_at'],
			'daysLeft'  => $state['days_left'],
		);

		return (array) apply_filters( 'order_updates_for_woo_shared_link_response', $response, $state, $order_id );
	}
}
