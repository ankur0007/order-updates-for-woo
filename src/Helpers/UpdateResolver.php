<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class UpdateResolver {
	private static array $update_cache = [];

	public static function get_update(int $update_id, OrderUpdatesDb $order_updates_db): array {
		if (isset(self::$update_cache[$update_id])) {
			return self::$update_cache[$update_id];
		}

		self::$update_cache[$update_id] = $order_updates_db->get_update($update_id);

		return self::$update_cache[$update_id];
	}

	public static function normalize_update(array|object|int|null $update, ?OrderUpdatesDb $order_updates_db = null): array {
		if (is_array($update)) {
			return $update;
		}

		if (is_object($update)) {
			return get_object_vars($update);
		}

		if (is_int($update) && $update > 0 && $order_updates_db instanceof OrderUpdatesDb) {
			return self::get_update($update, $order_updates_db);
		}

		return [];
	}
}
