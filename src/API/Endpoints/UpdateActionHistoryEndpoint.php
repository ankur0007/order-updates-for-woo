<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class UpdateActionHistoryEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/history';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb $order_updates_db Injected dependency.
	 */
	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

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

		$update = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );

		if ( $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );

		if ( empty( $this->order_updates_db->get_update( $update_id )['id'] ) ) {
			return $this->update_not_found_error();
		}

		$response = array(
			'events' => $this->order_updates_db->get_update_action_history( $update_id ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_action_history_response', $response, $update_id, $request ) );
	}
}
