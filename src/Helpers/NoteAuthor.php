<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

final class NoteAuthor {
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
