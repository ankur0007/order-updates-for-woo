<?php
/**
 * Turns an update reference (row, object, or id) into an array, with a small
 * per-request cache so repeated lookups don't re-hit the DB.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Resolves update references to arrays and caches them for the request.
 */
final class UpdateResolver {

	/** @var array<int, array> Update rows seen this request, keyed by update id. */
	private static array $update_cache = array();

	/**
	 * Fetch an update by id, caching it for the rest of the request.
	 *
	 * @param int            $update_id        Update id.
	 * @param OrderUpdatesDb $order_updates_db Loads the row on a cache miss.
	 */
	public static function get_update( int $update_id, OrderUpdatesDb $order_updates_db ): array {
		if ( isset( self::$update_cache[ $update_id ] ) ) {
			return self::$update_cache[ $update_id ];
		}

		self::$update_cache[ $update_id ] = $order_updates_db->get_update( $update_id );

		return self::$update_cache[ $update_id ];
	}

	/**
	 * Coerce an update reference into an array — passthrough for arrays/objects,
	 * a DB lookup for an id, or an empty array when there's nothing to resolve.
	 *
	 * @param array|object|int|null $update           Update row, object, id, or null.
	 * @param OrderUpdatesDb|null   $order_updates_db Loads the row when an id is passed.
	 */
	public static function normalize_update( array|object|int|null $update, ?OrderUpdatesDb $order_updates_db = null ): array {
		if ( is_array( $update ) ) {
			return $update;
		}

		if ( is_object( $update ) ) {
			return get_object_vars( $update );
		}

		if ( is_int( $update ) && $update > 0 && $order_updates_db instanceof OrderUpdatesDb ) {
			return self::get_update( $update, $order_updates_db );
		}

		return array();
	}
}
