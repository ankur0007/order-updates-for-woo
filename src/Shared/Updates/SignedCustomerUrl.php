<?php
/**
 * HMAC-signed, expiring token for customer-email links.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Shared\Config\Variables;

/**
 * HMAC signer for the URL in customer notification emails.
 *
 * Mints a token bound to the order id with a configurable expiry, so a
 * forwarded email stops working at the window edge. The admin panel uses
 * the stateful SharedLink helper instead.
 */
final class SignedCustomerUrl {

	public const QUERY_TOKEN   = 'awts_token';
	public const QUERY_EXPIRES = 'awts_expires';

	/**
	 * Mint a signed, expiring token for an order's customer URL.
	 *
	 * @param int      $order_id    Order the token is for.
	 * @param int|null $expiry_days Days until expiry; defaults to the configured value.
	 * @return array{token:string, expires:int}
	 */
	public static function sign( int $order_id, ?int $expiry_days = null ): array {
		$days = $expiry_days ?? Variables::getCustomerLinkExpiryDays();

		/**
		 * Filter the expiry window for a fresh customer URL.
		 *
		 * @param int $days     Configured expiry in days.
		 * @param int $order_id Order the token is for.
		 */
		$days = (int) apply_filters( 'order_updates_for_woo_customer_link_expiry_days', $days, $order_id );
		$days = max( 1, $days );

		$expires = time() + ( $days * DAY_IN_SECONDS );

		return array(
			'expires' => $expires,
			'token'   => self::make_token( $order_id, $expires ),
		);
	}

	/**
	 * True for a structurally valid, non-expired token. Use is_expired()
	 * to tell "expired" from "tampered" for the user message.
	 *
	 * @param int    $order_id Order the token should be bound to.
	 * @param int    $expires  Expiry timestamp from the URL.
	 * @param string $token    Token from the URL.
	 */
	public static function verify( int $order_id, int $expires, string $token ): bool {
		if ( ! $order_id || ! $expires || '' === $token ) {
			return false;
		}

		if ( $expires < time() ) {
			return false;
		}

		return hash_equals( self::make_token( $order_id, $expires ), $token );
	}

	/**
	 * True if the signature is valid but the window has passed.
	 *
	 * @param int    $order_id Order the token should be bound to.
	 * @param int    $expires  Expiry timestamp from the URL.
	 * @param string $token    Token from the URL.
	 */
	public static function is_expired( int $order_id, int $expires, string $token ): bool {
		if ( ! $order_id || ! $expires || '' === $token ) {
			return false;
		}

		if ( ! hash_equals( self::make_token( $order_id, $expires ), $token ) ) {
			return false;
		}

		return $expires < time();
	}

	/**
	 * HMAC of the order id + expiry, salted with the WP auth salt.
	 *
	 * @param int $order_id Order id.
	 * @param int $expires  Expiry timestamp.
	 */
	private static function make_token( int $order_id, int $expires ): string {
		return hash_hmac( 'sha256', $order_id . '|' . $expires, wp_salt( 'auth' ) );
	}
}
