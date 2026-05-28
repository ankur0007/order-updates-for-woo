<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class SaveStaffEmailPreferenceEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/staff-email-preference';

	public function __construct(
		private OrderUpdatesDb $order_updates_db
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

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to update this preference.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$muted     = rest_sanitize_boolean( $request->get_param( 'muted' ) );
		$user_id   = get_current_user_id();

		do_action( 'order_updates_for_woo_before_save_staff_email_preference', $update_id, $user_id, $muted, $request );

		StaffEmailPreference::set( $update_id, $user_id, $muted );

		do_action( 'order_updates_for_woo_after_save_staff_email_preference', $update_id, $user_id, $muted, $request );

		$response = array( 'muted' => $muted );

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_save_staff_email_preference_response', $response, $request ) );
	}
}
