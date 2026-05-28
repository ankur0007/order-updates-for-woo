<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;

final class UpdateAuthorHelper {
	public static function get_created_by_name(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);

		// Customer-opened updates render as "Customer" everywhere — same
		// label whether the customer was logged in or a guest.
		if (self::is_customer_initiated_update($resolved_update)) {
			return (string) apply_filters(
				'order_updates_for_woo_customer_creator_label',
				__('Customer', 'order-updates-for-woo'),
				$resolved_update
			);
		}

		if (! empty($resolved_update['created_by_name'])) {
			return (string) $resolved_update['created_by_name'];
		}

		return __('Unknown user', 'order-updates-for-woo');
	}

	/**
	 * Whether the update was opened by the customer (guest or logged-in)
	 * rather than by a staff member. Used both for labelling ("Created by
	 * Customer") and for permission decisions — customer-initiated updates
	 * have no staff "owner", so any staff member with the right cap can
	 * edit or reassign them.
	 */
	public static function is_customer_initiated_update(array $update): bool {
		$created_by = (int) ($update['created_by'] ?? 0);

		// Guests (created_by = 0) can only submit customer-side updates.
		if (0 === $created_by) {
			return true;
		}

		$order_id = (int) ($update['order_id'] ?? 0);
		if ($order_id <= 0) {
			return false;
		}

		$order = wc_get_order($order_id);
		if (! $order instanceof WC_Order) {
			return false;
		}

		return (int) $order->get_customer_id() === $created_by;
	}

	public static function get_formatted_created_by(array|object|int $update, ?OrderUpdatesDb $order_updates_db = null): string {
		$resolved_update = UpdateResolver::normalize_update($update, $order_updates_db);
		$created_line    = sprintf(
			/* translators: 1: user name, 2: date */
			__('Created by %1$s at %2$s', 'order-updates-for-woo'),
			self::get_created_by_name($resolved_update),
			DateHelper::format_date((string) ($resolved_update['created_at'] ?? ''))
		);

		return (string) apply_filters('order_updates_for_woo_created_line', $created_line, $resolved_update);
	}
}
