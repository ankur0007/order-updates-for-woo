<?php
/**
 * Backward-compatibility wrapper around the focused update helpers.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Compatibility wrapper around the focused helper classes.
 *
 * New code should prefer the dedicated helpers directly:
 * DateHelper, NotesHelper, AssigneeHelper, CustomerHelper,
 * UpdateStatusHelper, UpdateAuthorHelper, UpdatePresentationHelper,
 * and UpdateResolver.
 */
final class OrderUpdateInfoMethods {

	/**
	 * Fetch an update by id (cached for the request).
	 *
	 * @param int            $update_id        Update id.
	 * @param OrderUpdatesDb $order_updates_db DB gateway.
	 */
	public static function get_update( int $update_id, OrderUpdatesDb $order_updates_db ): array {
		return UpdateResolver::get_update( $update_id, $order_updates_db );
	}

	/**
	 * Internal note text for an update.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_internal_note( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return NotesHelper::get_internal_note( $update, $order_updates_db );
	}

	/**
	 * Display lines for an update card.
	 *
	 * @param array $update Normalised update row.
	 */
	public static function get_card_details( array $update ): array {
		return UpdatePresentationHelper::get_card_details( $update );
	}

	/**
	 * The "Created by {name} at {date}" line.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_created_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateAuthorHelper::get_formatted_created_by( $update, $order_updates_db );
	}

	/**
	 * The "Assigned to {name} at {date}" line.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_assigned_to( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return AssigneeHelper::get_formatted_assigned_to( $update, $order_updates_db );
	}

	/**
	 * Assignee display name, or "Unassigned".
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_assignee_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return AssigneeHelper::get_assignee_name( $update, $order_updates_db );
	}

	/**
	 * "Visible" or "Hidden" customer-visibility label.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_is_customer_visible( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return CustomerHelper::get_formatted_is_customer_visible( $update, $order_updates_db );
	}

	/**
	 * "Yes" or "Pending" solved-state label.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_is_solved( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_formatted_is_solved( $update, $order_updates_db );
	}

	/**
	 * Name of whoever solved the update.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_solved_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_solved_by_name( $update, $order_updates_db );
	}

	/**
	 * The "Marked solved by {name} at {date}" line.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_solved_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_formatted_solved_by( $update, $order_updates_db );
	}

	/**
	 * Author display name, or "Customer" / "Unknown user".
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_created_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateAuthorHelper::get_created_by_name( $update, $order_updates_db );
	}

	/**
	 * Format a stored GMT date for display.
	 *
	 * @param string $date     GMT date string, or '' if none.
	 * @param string $fallback Text to show when $date is empty.
	 */
	public static function format_date( string $date, string $fallback = '' ): string {
		return DateHelper::format_date( $date, $fallback );
	}

	/**
	 * The "Customer - visible/hidden, notified at …" line.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_customer_update_label( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return CustomerHelper::get_formatted_customer_update_label( $update, $order_updates_db );
	}

	/**
	 * Inline `background:` style for the status colour dot.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_color_icon( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdatePresentationHelper::get_color_icon( $update, $order_updates_db );
	}
}
