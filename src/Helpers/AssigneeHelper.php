<?php
/**
 * Finds an update's assignee name for display.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Looks up the assignee's display name and the "assigned to …" line.
 */
final class AssigneeHelper {

	/**
	 * Assignee's display name, or "Unassigned" when there's none.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_assignee_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );
		$user_id         = ! empty( $resolved_update['assignee_user_id'] ) ? (int) $resolved_update['assignee_user_id'] : 0;

		if ( $user_id > 0 ) {
			$first_name = (string) get_user_meta( $user_id, 'first_name', true );
			$last_name  = (string) get_user_meta( $user_id, 'last_name', true );
			$name       = trim( $first_name . ' ' . $last_name );

			if ( '' !== $name ) {
				return $name;
			}
		}

		if ( ! empty( $resolved_update['assignee_name'] ) ) {
			return (string) $resolved_update['assignee_name'];
		}

		return __( 'Unassigned', 'order-updates-for-woo' );
	}

	/**
	 * The "Assigned to {name} at {date}" line, filterable for addons.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_assigned_to( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( empty( $resolved_update['assignee_name'] ) ) {
			return (string) apply_filters(
				'order_updates_for_woo_assigned_line',
				__( 'Not assigned yet', 'order-updates-for-woo' ),
				$resolved_update
			);
		}

		$assigned_line = sprintf(
			/* translators: 1: assignee name, 2: date */
			__( 'Assigned to %1$s at %2$s', 'order-updates-for-woo' ),
			(string) $resolved_update['assignee_name'],
			DateHelper::format_date( (string) ( $resolved_update['assigned_at'] ?? '' ) )
		);

		return (string) apply_filters( 'order_updates_for_woo_assigned_line', $assigned_line, $resolved_update );
	}
}
