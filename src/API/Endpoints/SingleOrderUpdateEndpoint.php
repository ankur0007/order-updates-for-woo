<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\UpdateResolver;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SingleOrderUpdateEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)';

	public function __construct( private OrderUpdatesDb $order_updates_db ) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args' => array(
					'update_id' => array(
						'required' => true,
						'type' => 'integer',
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update_id = absint( $request->get_param( 'update_id' ) );
		$update = UpdateResolver::get_update( $update_id, $this->order_updates_db );
		$order_id = absint( $update['order_id'] ?? 0 );

		if ( ! $update_id || ! $order_id ) {
			return $this->update_not_found_error();
		}

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to save order updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$update = UpdateResolver::get_update( $update_id, $this->order_updates_db );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		$response = array( 'update' => $update );

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_single_update_response', $response, $update_id, $request ) );
	}
}
