<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class UpdateStatusHelper {
	/**
	 * Resolve the human-readable label for the update's current status key.
	 * Empty string if no status is set or the stored key no longer matches
	 * a configured status (admin renamed / removed it). Used by status-
	 * change emails so the recipient can see WHAT the status was changed
	 * to, not just that "the status changed."
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

	public static function get_formatted_is_solved(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);
		$solved_line     = UpdateState::is_resolved($resolved_update)
			? __('Yes', 'order-updates-for-woo')
			: __('Pending', 'order-updates-for-woo');

		return (string) apply_filters('order_updates_for_woo_solved_line', $solved_line, $resolved_update);
	}

	public static function get_solved_by_name(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);

		if (UpdateState::is_resolved($resolved_update) && ! empty($resolved_update['solved_by_name'])) {
			return (string) $resolved_update['solved_by_name'];
		}

		return __('Not solved yet', 'order-updates-for-woo');
	}

	public static function get_formatted_solved_by(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);

		if (! UpdateState::is_resolved($resolved_update)) {
			return '';
		}

		$name = ! empty($resolved_update['solved_by_name'])
			? (string) $resolved_update['solved_by_name']
			: __('Unknown user', 'order-updates-for-woo');

		$solved_line = sprintf(
			/* translators: 1: solver name, 2: date */
			__('Marked solved by %1$s at %2$s', 'order-updates-for-woo'),
			$name,
			DateHelper::format_date((string) ($resolved_update['solved_at'] ?? ''))
		);

		return (string) apply_filters('order_updates_for_woo_solved_line_formatted', $solved_line, $resolved_update);
	}
}
