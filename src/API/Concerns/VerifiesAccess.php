<?php
/**
 * Shared REST access checks: nonce, order capability, and canonical errors.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Concerns;

use WP_Error;
use WP_REST_Request;

/**
 * Reused by every REST endpoint for nonce + capability gating and the shared
 * "not found" error shapes.
 */
trait VerifiesAccess {

	/**
	 * Verify the request's `wp_rest` nonce. Returns an error to short-circuit
	 * the permission callback, or null when the nonce is valid.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	protected function verify_nonce( WP_REST_Request $request ): ?WP_Error {
		$nonce = $request->get_header( 'X-WP-Nonce' );

		if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error( 'order_updates_for_woo_invalid_nonce', __( 'Security check failed.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		return null;
	}

	/**
	 * Whether the current user can edit the given order.
	 *
	 * @param int $order_id Order id.
	 */
	protected function can_edit_order( int $order_id ): bool {
		return $order_id > 0
			&& ( current_user_can( 'edit_shop_order', $order_id ) || current_user_can( 'edit_post', $order_id ) );
	}

	/**
	 * Whether the current user may act on the given order's updates.
	 *
	 * @param int $order_id Order id.
	 */
	protected function is_authorized_for_order( int $order_id ): bool {
		return current_user_can( 'manage_woocommerce' ) || $this->can_edit_order( $order_id );
	}

	/** Whether the current user may list order updates. */
	protected function is_list_authorized(): bool {
		return current_user_can( 'edit_shop_orders' ) || current_user_can( 'manage_woocommerce' );
	}

	/**
	 * Canonical "update id didn't resolve" 404. Used across every endpoint
	 * that loads an update first thing — keeps error code + message + status
	 * identical so consumers (admin JS, customer JS, addons) can branch on
	 * one shape.
	 */
	protected function update_not_found_error(): \WP_Error {
		return new \WP_Error(
			'order_updates_for_woo_invalid_update',
			__( 'The selected update could not be found.', 'order-updates-for-woo' ),
			array( 'status' => 404 )
		);
	}

	/**
	 * Canonical "order id didn't resolve to a WC_Order" 400. Same rationale
	 * as update_not_found_error — one shape, every endpoint.
	 */
	protected function order_not_found_error(): \WP_Error {
		return new \WP_Error(
			'order_updates_for_woo_invalid_order',
			__( 'A valid order is required.', 'order-updates-for-woo' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Resolve an order id to a WC_Order, returning null when the id is
	 * missing, WC isn't loaded, or the row isn't actually an order. Lets
	 * callers replace the four-line `wc_get_order` + `instanceof` dance
	 * with one explicit null-check.
	 *
	 * @param int $order_id Order id.
	 */
	protected function resolve_order( int $order_id ): ?\WC_Order {
		if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
			return null;
		}

		$order = \wc_get_order( $order_id );

		return $order instanceof \WC_Order ? $order : null;
	}
}
