<?php
/**
 * Status and solved-state display helpers for an update.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Reads an update's status label and its "solved by …" display lines.
 */
final class UpdateStatusHelper {
	/**
	 * Resolve the human-readable label for the update's current status key.
	 * Empty string if no status is set or the stored key no longer matches
	 * a configured status (admin renamed / removed it). Used by status-
	 * change emails so the recipient can see WHAT the status was changed
	 * to, not just that "the status changed."
	 *
	 * @param array $update Normalised update row.
	 */
	public static function get_status_label_for_update( array $update ): string {
		$key = (string) ( $update['status'] ?? '' );

		if ( '' === $key ) {
			return '';
		}

		$statuses = (array) get_option( \OrderUpdatesForWoo\Shared\Config\Constants::STATUSES_OPTION, array() );

		foreach ( $statuses as $row ) {
			if ( ( $row['key'] ?? '' ) === $key ) {
				return (string) ( $row['label'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * "Yes" or "Pending" for an update's solved state.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_is_solved( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );
		$solved_line     = UpdateState::is_resolved( $resolved_update )
			? __( 'Yes', 'order-updates-for-woo' )
			: __( 'Pending', 'order-updates-for-woo' );

		return (string) apply_filters( 'order_updates_for_woo_solved_line', $solved_line, $resolved_update );
	}

	/**
	 * Name of whoever solved the update, or "Not solved yet".
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_solved_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( UpdateState::is_resolved( $resolved_update ) && ! empty( $resolved_update['solved_by_name'] ) ) {
			return (string) $resolved_update['solved_by_name'];
		}

		return __( 'Not solved yet', 'order-updates-for-woo' );
	}

	/**
	 * The "Marked solved by {name} at {date}" line, or '' if not solved.
	 *
	 * @param array|object|int    $update           Update row, object, or id.
	 * @param OrderUpdatesDb|null $order_updates_db Loads the update when an id is passed.
	 */
	public static function get_formatted_solved_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( ! UpdateState::is_resolved( $resolved_update ) ) {
			return '';
		}

		$name = ! empty( $resolved_update['solved_by_name'] )
			? (string) $resolved_update['solved_by_name']
			: __( 'Unknown user', 'order-updates-for-woo' );

		$solved_line = sprintf(
			/* translators: 1: solver name, 2: timestamp. */
			__( 'Marked solved by %1$s at %2$s', 'order-updates-for-woo' ),
			$name,
			DateHelper::format_date( (string) ( $resolved_update['solved_at'] ?? '' ) )
		);

		return (string) apply_filters( 'order_updates_for_woo_solved_line_formatted', $solved_line, $resolved_update );
	}
}
