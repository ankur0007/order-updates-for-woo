<?php
/**
 * Works out who created an update and the "created by …" line.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;

/**
 * Names the update's author, showing "Customer" for customer-opened updates.
 */
final class UpdateAuthorHelper {

	/**
	 * Display name of the update's author, or "Customer" / "Unknown user".
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_created_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		// Customer-opened updates show the customer's own name with a
		// "(Customer)" tag — e.g. "James Gobler (Customer)" — whether they
		// were logged in or a guest. Falls back to plain "Customer" when the
		// order carries no billing name.
		if ( self::is_customer_initiated_update( $resolved_update ) ) {
			$customer_name = self::get_customer_name( $resolved_update );
			$label         = '' !== $customer_name
				/* translators: %s: the customer's name. */
				? sprintf( __( '%s (Customer)', 'order-updates-for-woo' ), $customer_name )
				: __( 'Customer', 'order-updates-for-woo' );

			return (string) apply_filters(
				'order_updates_for_woo_customer_creator_label',
				$label,
				$resolved_update
			);
		}

		if ( ! empty( $resolved_update['created_by_name'] ) ) {
			return (string) $resolved_update['created_by_name'];
		}

		return __( 'Unknown user', 'order-updates-for-woo' );
	}

	/**
	 * The name to feed an avatar (drives the initials disc and its colour).
	 * Never the "(Customer)" label — that would corrupt the initials — and
	 * never empty, so the avatar shows letters rather than a "?".
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_avatar_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( self::is_customer_initiated_update( $resolved_update ) ) {
			$customer_name = self::get_customer_name( $resolved_update );
			return '' !== $customer_name ? $customer_name : __( 'Customer', 'order-updates-for-woo' );
		}

		if ( ! empty( $resolved_update['created_by_name'] ) ) {
			return (string) $resolved_update['created_by_name'];
		}

		return __( 'Unknown user', 'order-updates-for-woo' );
	}

	/**
	 * The customer's display name for the update's order — billing first +
	 * last, falling back to the order's formatted billing name. Empty string
	 * when no order or no name is on file.
	 *
	 * @param array $update Normalised update row.
	 */
	public static function get_customer_name( array $update ): string {
		$order_id = (int) ( $update['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return '';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		if ( '' === $name ) {
			$name = trim( $order->get_formatted_billing_full_name() );
		}

		return $name;
	}

	/**
	 * Whether the update was opened by the customer (guest or logged-in)
	 * rather than by a staff member. Used both for labelling ("Created by
	 * Customer") and for permission decisions — customer-initiated updates
	 * have no staff "owner", so any staff member with the right cap can
	 * edit or reassign them.
	 *
	 * @param array $update Normalised update row.
	 */
	public static function is_customer_initiated_update( array $update ): bool {
		$created_by = (int) ( $update['created_by'] ?? 0 );

		// Guests (created_by = 0) can only submit customer-side updates.
		if ( 0 === $created_by ) {
			return true;
		}

		$order_id = (int) ( $update['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return false;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		return (int) $order->get_customer_id() === $created_by;
	}

	/**
	 * The "Created by {name} at {date}" line, filterable for addons.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_created_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );
		$created_line    = sprintf(
			/* translators: 1: user display name, 2: timestamp. */
			__( 'Created by %1$s at %2$s', 'order-updates-for-woo' ),
			self::get_created_by_name( $resolved_update ),
			DateHelper::format_date( (string) ( $resolved_update['created_at'] ?? '' ) )
		);

		return (string) apply_filters( 'order_updates_for_woo_created_line', $created_line, $resolved_update );
	}
}
