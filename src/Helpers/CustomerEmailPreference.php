<?php
/**
 * Stores whether a customer wants email updates for an order.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;

/**
 * Reads and writes the customer's email on/off preference — keyed to the user
 * for logged-in customers, or to the order for guests. Cached per request.
 */
final class CustomerEmailPreference {

	/**
	 * Cache key for the preference — per user when logged in, else per order.
	 *
	 * @param int $order_id Order id (used for guests).
	 * @param int $user_id  Customer user id, or 0 for a guest.
	 */
	private static function cache_key( int $order_id, int $user_id ): string {
		return $user_id > 0 ? "customer_email_pref_user_{$user_id}" : "customer_email_pref_order_{$order_id}";
	}

	/**
	 * Whether the customer wants emails for this order — defaults to on (true).
	 *
	 * @param int $order_id Order id.
	 * @param int $user_id  Customer user id, or 0 for a guest.
	 */
	public static function get( int $order_id, int $user_id ): bool {
		$key    = self::cache_key( $order_id, $user_id );
		$cached = wp_cache_get( $key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		if ( $user_id > 0 ) {
			$val = get_user_meta( $user_id, Constants::CUSTOMER_EMAIL_PREF_USER_META, true );
		} else {
			// Read through the order object so HPOS stores read from the
			// custom-orders table and classic stores read from post meta —
			// `$order->get_meta()` routes to the right backing store either
			// way. A direct `get_post_meta()` silently misses on HPOS.
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			$val   = $order ? (string) $order->get_meta( Constants::CUSTOMER_EMAIL_PREF_ORDER_META, true ) : '';
		}

		// Empty string means the preference has never been set — default ON.
		$result = '' === $val || 'yes' === $val;
		wp_cache_set( $key, $result, Constants::CACHE_GROUP, Variables::getUpdateCacheTtl() ); // phpcs:ignore WordPressVIPMinimum.Performance.LowExpiryCacheTime.CacheTimeUndetermined -- TTL is a configured value (Variables / constant), which the sniff can't resolve to a literal.

		return $result;
	}

	/**
	 * Save the customer's email preference and bust the cache.
	 *
	 * @param int  $order_id Order id.
	 * @param int  $user_id  Customer user id, or 0 for a guest.
	 * @param bool $enabled  True to receive emails, false to mute.
	 */
	public static function set( int $order_id, int $user_id, bool $enabled ): void {
		$value = $enabled ? 'yes' : 'no';

		if ( $user_id > 0 ) {
			update_user_meta( $user_id, Constants::CUSTOMER_EMAIL_PREF_USER_META, $value );
		} else {
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;

			if ( $order ) {
				$order->update_meta_data( Constants::CUSTOMER_EMAIL_PREF_ORDER_META, $value );
				$order->save();
			}
		}

		wp_cache_delete( self::cache_key( $order_id, $user_id ), Constants::CACHE_GROUP );
	}
}
