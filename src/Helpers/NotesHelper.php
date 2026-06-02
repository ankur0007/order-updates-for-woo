<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

final class NotesHelper {
	public static function get_internal_note( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );
		$internal_note   = (string) ( $resolved_update['internal_note'] ?? '' );

		if ( '' !== $internal_note ) {
			return $internal_note;
		}

		return (string) ( $resolved_update['note'] ?? '' );
	}
}
