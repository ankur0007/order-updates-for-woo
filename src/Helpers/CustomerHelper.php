<?php
/**
 * Display text for an update's customer-visibility state.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Builds the "visible / hidden" labels shown to staff for an update.
 */
final class CustomerHelper {

	/**
	 * "Visible" or "Hidden", depending on whether the customer can see the update.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_is_customer_visible( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( ! UpdateState::is_customer_visible( $resolved_update ) ) {
			return __( 'Hidden', 'order-updates-for-woo' );
		}

		return __( 'Visible', 'order-updates-for-woo' );
	}

	/**
	 * The "Customer - visible/hidden, notified at …" line, filterable for addons.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_customer_update_label( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( ! UpdateState::is_customer_visible( $resolved_update ) ) {
			return (string) apply_filters(
				'order_updates_for_woo_customer_update_line',
				__( 'Customer - hidden, notified at -', 'order-updates-for-woo' ),
				$resolved_update
			);
		}

		$customer_update_line = sprintf(
			/* translators: %s: notification date */
			__( 'Customer - visible, notified at %s', 'order-updates-for-woo' ),
			DateHelper::format_date( (string) ( $resolved_update['notified_customer_at'] ?? '' ), '-' )
		);

		return (string) apply_filters( 'order_updates_for_woo_customer_update_line', $customer_update_line, $resolved_update );
	}
}
