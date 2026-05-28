<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class AssigneeHelper {
	public static function get_assignee_name(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);
		$user_id         = ! empty($resolved_update['assignee_user_id']) ? (int) $resolved_update['assignee_user_id'] : 0;

		if ($user_id > 0) {
			$first_name = (string) get_user_meta($user_id, 'first_name', true);
			$last_name  = (string) get_user_meta($user_id, 'last_name', true);
			$name       = trim($first_name . ' ' . $last_name);

			if ('' !== $name) {
				return $name;
			}
		}

		if (! empty($resolved_update['assignee_name'])) {
			return (string) $resolved_update['assignee_name'];
		}

		return __('Unassigned', 'order-updates-for-woo');
	}

	public static function get_formatted_assigned_to(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);

		if (empty($resolved_update['assignee_name'])) {
			return (string) apply_filters(
				'order_updates_for_woo_assigned_line',
				__('Not assigned yet', 'order-updates-for-woo'),
				$resolved_update
			);
		}

		$assigned_line = sprintf(
			/* translators: 1: assignee name, 2: date */
			__('Assigned to %1$s at %2$s', 'order-updates-for-woo'),
			(string) $resolved_update['assignee_name'],
			DateHelper::format_date((string) ($resolved_update['assigned_at'] ?? ''))
		);

		return (string) apply_filters('order_updates_for_woo_assigned_line', $assigned_line, $resolved_update);
	}
}
