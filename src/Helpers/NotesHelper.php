<?php
/**
 * Reads an update's internal (staff) note text.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Pulls the internal note off an update, however the update is passed in.
 */
final class NotesHelper {

	/**
	 * Internal note text for an update, or '' when it has none.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_internal_note( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );
		$internal_note   = (string) ( $resolved_update['internal_note'] ?? '' );

		if ( '' !== $internal_note ) {
			return $internal_note;
		}

		return (string) ( $resolved_update['note'] ?? '' );
	}
}
