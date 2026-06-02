<?php
/**
 * Tells customer-written notes apart from staff-written ones.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

/**
 * Decides whether a note's author is the customer or a staff member.
 */
final class NoteAuthor {

	/**
	 * True when the author is a customer — a guest, or a user without shop caps.
	 *
	 * @param int $creator_user_id Author user id; 0 for a guest.
	 */
	public static function is_customer( int $creator_user_id ): bool {
		if ( $creator_user_id <= 0 ) {
			return true;
		}

		$user = get_user_by( 'id', $creator_user_id );

		if ( ! $user ) {
			return true;
		}

		return ! user_can( $user, 'manage_woocommerce' ) && ! user_can( $user, 'edit_shop_orders' );
	}
}
