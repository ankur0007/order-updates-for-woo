<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;

// Direct meta query on a small, indexed lookup; table names are safe, not user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

final class StaffEmailPreference {
	private static function cache_key( int $update_id, int $user_id ): string {
		return "staff_muted_{$update_id}_{$user_id}";
	}

	public static function is_muted( int $update_id, int $user_id ): bool {
		if ( ! $update_id || ! $user_id ) {
			return false;
		}

		$key    = self::cache_key( $update_id, $user_id );
		$cached = wp_cache_get( $key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return (bool) $cached;
		}

		$muted = 'yes' === get_user_meta( $user_id, Constants::STAFF_EMAIL_MUTED_META_PREFIX . $update_id, true );
		wp_cache_set( $key, $muted, Constants::CACHE_GROUP, Variables::getUpdateCacheTtl() );

		return $muted;
	}

	public static function set( int $update_id, int $user_id, bool $muted ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		if ( $muted ) {
			update_user_meta( $user_id, Constants::STAFF_EMAIL_MUTED_META_PREFIX . $update_id, 'yes' );
		} else {
			delete_user_meta( $user_id, Constants::STAFF_EMAIL_MUTED_META_PREFIX . $update_id );
		}

		wp_cache_delete( self::cache_key( $update_id, $user_id ), Constants::CACHE_GROUP );
	}

	public static function delete_all_for_update( int $update_id ): void {
		global $wpdb;

		if ( ! $update_id ) {
			return;
		}

		$wpdb->delete(
			$wpdb->usermeta,
			array( 'meta_key' => Constants::STAFF_EMAIL_MUTED_META_PREFIX . $update_id ),
			array( '%s' )
		);

		// Individual per-user cache entries expire naturally via TTL.
		// No full group flush needed since the update itself is being deleted.
	}
}
