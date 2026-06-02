<?php
/**
 * Round-robin picker for assigning customer-initiated updates to staff.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Pick the next staff user to assign a customer-initiated update to.
 *
 * Reads the ordered priority list configured in plugin settings (admin chose
 * members via checkbox + drag-drop), advances a stored pointer, and returns
 * the next user. Falls back to the first administrator when the priority
 * list is empty or the picked user has been deleted, so customer
 * submissions are never silently left unassigned.
 *
 * Race note: the pointer increment is not atomic under extreme concurrency.
 * A rare duplicate assignment is acceptable for v1.0.
 */
final class AssigneePicker {
	/**
	 * Return the next user ID from the priority list, advancing the pointer.
	 *
	 * Returns 0 only when there are literally no administrators on the site
	 * (broken WP install) — every other empty path falls back to the first
	 * administrator so customer updates always have an owner.
	 */
	public static function next(): int {
		$list_raw = get_option( Constants::ASSIGNEE_PRIORITY_LIST_OPTION, array() );
		$list     = is_array( $list_raw ) ? $list_raw : json_decode( (string) $list_raw, true );
		$list     = self::extract_active_ids( is_array( $list ) ? $list : array() );

		if ( empty( $list ) ) {
			return self::first_admin_user_id();
		}

		$pointer = (int) get_option( Constants::ASSIGNEE_ROTATION_POINTER_OPTION, 0 );
		$index   = $pointer % count( $list );
		$user_id = (int) $list[ $index ];

		update_option( Constants::ASSIGNEE_ROTATION_POINTER_OPTION, $pointer + 1, false );

		// Stale entry (user deleted between save and dispatch) — fall back to
		// the admin the same way an empty list does, rather than dropping
		// the submission on the floor.
		if ( ! get_userdata( $user_id ) ) {
			return self::first_admin_user_id();
		}

		return $user_id;
	}

	/**
	 * Pull the active user ids out of either the new keyed `[id => bool]`
	 * shape or the legacy flat `[id, id]` shape. Order preserved.
	 *
	 * @param array<int|string, mixed> $list Saved priority list, keyed or flat.
	 * @return int[]
	 */
	private static function extract_active_ids( array $list ): array {
		$ids = array();

		foreach ( $list as $key => $value ) {
			if ( is_int( $key ) || ctype_digit( (string) $key ) ) {
				if ( is_bool( $value ) ) {
					if ( $value ) {
						$ids[] = (int) $key;
					}
					continue;
				}

				// Legacy flat shape — array entry is the user id, all active.
				$ids[] = (int) $value;
			}
		}

		return array_values( array_filter( $ids ) );
	}

	/** First administrator's user id (lowest ID), or 0 if the site has none. */
	private static function first_admin_user_id(): int {
		$admins = get_users(
			array(
				'role'    => 'administrator',
				'orderby' => 'ID',
				'order'   => 'ASC',
				'number'  => 1,
				'fields'  => 'ID',
			)
		);

		return isset( $admins[0] ) ? (int) $admins[0] : 0;
	}
}
