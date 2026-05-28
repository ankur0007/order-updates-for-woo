<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\View;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class OrderUpdatesListEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/order-updates';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private OrderUpdatesSettingsService $settings_service,
		private UpdateCardVariableParser $update_card_variable_parser
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args' => array(
					'order_id' => array(
						'required' => true,
						'type' => 'integer',
						'sanitize_callback' => 'absint',
					),
					'offset' => array(
						'required' => false,
						'type' => 'integer',
						'default' => 0,
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

		if ( $this->is_list_authorized() ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view order updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id = absint( $request->get_param( 'order_id' ) );
		$offset = max( 0, absint( $request->get_param( 'offset' ) ) );
		$updates = $this->order_updates_db->get_order_updates( $order_id, Variables::getUpdatesPageSize(), $offset );
		$total = $this->order_updates_db->count_order_updates( $order_id );
		$next_offset = $offset + count( $updates );

		ob_start();

		foreach ( $updates as $order_update ) {
			View::render( 'src/Admin/Orders/Views/OrderUpdateCardViewModern', array(
				'settings' => $this->settings_service->get_feature_settings(),
				'card_variables' => $this->update_card_variable_parser->parse( $order_update ),
			) );
		}

		$response = array(
			'html' => (string) ob_get_clean(),
			'count' => count( $updates ),
			'hasMore' => $next_offset < $total,
			'nextOffset' => $next_offset,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_order_updates_list_response', $response, $order_id, $request ) );
	}
}
