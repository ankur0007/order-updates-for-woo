<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Shared\Config\Variables;

/**
 * HMAC signer for customer-facing order-updates URLs sent in email.
 *
 * The legacy email URL carried the order's permanent `?key=...` — anyone with
 * the email had access forever. This helper mints a short-lived token bound
 * to the order id with a configurable expiry, so a forwarded or leaked email
 * stops working after the window passes.
 *
 * Staff-shared links (the admin "Copy customer link" button) deliberately stay
 * on the legacy `?key=...` flow — a human-driven handoff should not expire.
 */
final class SignedCustomerUrl {

	public const QUERY_TOKEN   = 'awts_token';
	public const QUERY_EXPIRES = 'awts_expires';

	/**
	 * @return array{token:string, expires:int}
	 */
	public static function sign( int $order_id, ?int $expiry_days = null ): array {
		$days = $expiry_days ?? Variables::getCustomerLinkExpiryDays();

		/**
		 * Filter the expiry window applied to a freshly-minted customer URL.
		 * Addons can shorten it for sensitive orders, or lengthen it for VIP
		 * threads. Default comes from the Customers settings tab.
		 *
		 * @param int $days     Configured expiry in days.
		 * @param int $order_id Order the token is being minted for.
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
	 * Whether the request carries a structurally valid, non-expired token for
	 * this order. Returns false for missing, malformed, expired, or signature-
	 * mismatched tokens — the caller cannot distinguish those cases from this
	 * method. Use {@see is_expired()} to surface a tailored "your link has
	 * expired" page.
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
	 * Whether the signature is valid but the window has passed. Lets the
	 * customer page show a "this link has expired" message that confirms the
	 * link was genuinely ours — rather than a generic "invalid link" which
	 * would also fire on tampered URLs.
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

	private static function make_token( int $order_id, int $expires ): string {
		return hash_hmac( 'sha256', $order_id . '|' . $expires, wp_salt( 'auth' ) );
	}
}
