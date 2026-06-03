<?php
/**
 * No-login "shared chat link" hash + expiry stored on the order.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Shared\Config\Variables;
use WC_Order;

/**
 * Stateful "no-login chat link" for the admin Order Updates panel.
 *
 * The URL holds a random hash; the order holds the matching hash and the
 * expiry. Staff can change the expiry without changing the URL, so a link
 * the customer already received still works.
 */
final class SharedLink {

	public const QUERY_PARAM = 'awts_link';

	// Full prefix so uninstall.php sweeps these. Do not shorten to `_awts_`.
	public const META_HASH       = '_order_updates_for_woo_shared_link_hash';
	public const META_EXPIRES_AT = '_order_updates_for_woo_shared_link_expires_at';
	public const META_LOG        = '_order_updates_for_woo_shared_link_log';

	public const LOG_GENERATED   = 'generated';
	public const LOG_REGENERATED = 'regenerated';
	public const LOG_EXTENDED    = 'extended';
	public const LOG_SHORTENED   = 'shortened';

	/**
	 * Read the order's shared link, minting one on first call.
	 *
	 * @param WC_Order $order         Order the link belongs to.
	 * @param int      $actor_user_id Who triggered the mint (for the log).
	 * @return array{hash:string,expires_at:int,days_left:int}
	 */
	public static function ensure( WC_Order $order, int $actor_user_id = 0 ): array {
		$hash       = (string) $order->get_meta( self::META_HASH, true );
		$expires_at = (int) $order->get_meta( self::META_EXPIRES_AT, true );

		if ( '' === $hash || $expires_at <= 0 ) {
			$days = Variables::getCustomerLinkExpiryDays();

			$hash       = self::new_hash();
			$expires_at = self::expiry_from_days( $days );

			$order->update_meta_data( self::META_HASH, $hash );
			$order->update_meta_data( self::META_EXPIRES_AT, $expires_at );
			self::append_log( $order, self::LOG_GENERATED, $days, $actor_user_id );
			$order->save();
		}

		return self::state_for( $hash, $expires_at );
	}

	/**
	 * Mint a new hash and reset the expiry. The old URL stops working.
	 *
	 * @param WC_Order $order         Order the link belongs to.
	 * @param int      $days          Days until the new link expires.
	 * @param int      $actor_user_id Who triggered it (for the log).
	 * @return array{hash:string,expires_at:int,days_left:int}
	 */
	public static function regenerate( WC_Order $order, int $days, int $actor_user_id = 0 ): array {
		$days = self::clamp_days( $days );

		$hash       = self::new_hash();
		$expires_at = self::expiry_from_days( $days );

		$order->update_meta_data( self::META_HASH, $hash );
		$order->update_meta_data( self::META_EXPIRES_AT, $expires_at );
		self::append_log( $order, self::LOG_REGENERATED, $days, $actor_user_id );
		$order->save();

		return self::state_for( $hash, $expires_at );
	}

	/**
	 * Set the expiry to N days from now. Hash stays the same. Logs as
	 * extended or shortened based on the previous value.
	 *
	 * @param WC_Order $order         Order the link belongs to.
	 * @param int      $days          New days-until-expiry.
	 * @param int      $actor_user_id Who triggered it (for the log).
	 * @return array{hash:string,expires_at:int,days_left:int}
	 */
	public static function set_expiry( WC_Order $order, int $days, int $actor_user_id = 0 ): array {
		$days = self::clamp_days( $days );

		$existing_at   = (int) $order->get_meta( self::META_EXPIRES_AT, true );
		$existing_hash = (string) $order->get_meta( self::META_HASH, true );

		// First-time call on an order that has never had a link minted: fall
		// through to regenerate so the hash is also created.
		if ( $existing_at <= 0 || '' === $existing_hash ) {
			return self::regenerate( $order, $days, $actor_user_id );
		}

		$previous_days = self::days_remaining( $existing_at );
		$expires_at    = self::expiry_from_days( $days );

		$order->update_meta_data( self::META_EXPIRES_AT, $expires_at );

		$action = $days >= $previous_days ? self::LOG_EXTENDED : self::LOG_SHORTENED;
		self::append_log( $order, $action, $days, $actor_user_id );

		$order->save();

		return self::state_for( $existing_hash, $expires_at );
	}

	/**
	 * True if the hash matches the order and the expiry has not passed.
	 *
	 * @param WC_Order $order Order the link belongs to.
	 * @param string   $hash  Hash from the URL.
	 */
	public static function verify( WC_Order $order, string $hash ): bool {
		if ( '' === $hash ) {
			return false;
		}

		$stored_hash = (string) $order->get_meta( self::META_HASH, true );

		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $hash ) ) {
			return false;
		}

		$expires_at = (int) $order->get_meta( self::META_EXPIRES_AT, true );

		return $expires_at > 0 && $expires_at >= time();
	}

	/**
	 * True if the hash matches but the window has passed.
	 *
	 * @param WC_Order $order Order the link belongs to.
	 * @param string   $hash  Hash from the URL.
	 */
	public static function is_expired( WC_Order $order, string $hash ): bool {
		if ( '' === $hash ) {
			return false;
		}

		$stored_hash = (string) $order->get_meta( self::META_HASH, true );

		if ( '' === $stored_hash || ! hash_equals( $stored_hash, $hash ) ) {
			return false;
		}

		$expires_at = (int) $order->get_meta( self::META_EXPIRES_AT, true );

		return $expires_at > 0 && $expires_at < time();
	}

	/**
	 * The order's shared-link change log, newest entries last.
	 *
	 * @param WC_Order $order Order the link belongs to.
	 * @return array<int, array{action:string,days:int,by_id:int,by_name:string,at:string}>
	 */
	public static function get_log( WC_Order $order ): array {
		$log = $order->get_meta( self::META_LOG, true );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Shape the public state array for a hash + expiry.
	 *
	 * @param string $hash       Link hash.
	 * @param int    $expires_at Expiry timestamp.
	 */
	private static function state_for( string $hash, int $expires_at ): array {
		return array(
			'hash'       => $hash,
			'expires_at' => $expires_at,
			'days_left'  => self::days_remaining( $expires_at ),
		);
	}

	/** A fresh random link hash. */
	private static function new_hash(): string {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * Expiry timestamp N days from now.
	 *
	 * @param int $days Days until expiry.
	 */
	private static function expiry_from_days( int $days ): int {
		return time() + ( $days * DAY_IN_SECONDS );
	}

	/**
	 * Whole days left until an expiry timestamp (0 if past).
	 *
	 * @param int $expires_at Expiry timestamp.
	 */
	private static function days_remaining( int $expires_at ): int {
		if ( $expires_at <= 0 ) {
			return 0;
		}

		$delta = $expires_at - time();

		return $delta > 0 ? (int) ceil( $delta / DAY_IN_SECONDS ) : 0;
	}

	/**
	 * Clamp a requested expiry to the allowed 1–365 day range.
	 *
	 * @param int $days Requested days.
	 */
	private static function clamp_days( int $days ): int {
		return max( 1, min( 365, $days ) );
	}

	/**
	 * Append one entry to the order's shared-link change log.
	 *
	 * @param WC_Order $order         Order the link belongs to.
	 * @param string   $action        Log action (generated / extended / …).
	 * @param int      $days          Days value at the time of the action.
	 * @param int      $actor_user_id Who performed the action.
	 */
	private static function append_log( WC_Order $order, string $action, int $days, int $actor_user_id ): void {
		$log = self::get_log( $order );

		$user      = $actor_user_id ? get_userdata( $actor_user_id ) : null;
		$user_name = $user ? (string) $user->display_name : '';

		$log[] = array(
			'action'  => $action,
			'days'    => $days,
			'by_id'   => $actor_user_id,
			'by_name' => $user_name,
			'at'      => current_time( 'mysql', true ),
		);

		$order->update_meta_data( self::META_LOG, $log );
	}
}
