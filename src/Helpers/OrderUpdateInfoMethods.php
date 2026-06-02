<?php

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
	public static function get_update( int $update_id, OrderUpdatesDb $order_updates_db ): array {
		return UpdateResolver::get_update( $update_id, $order_updates_db );
	}

	public static function get_internal_note( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return NotesHelper::get_internal_note( $update, $order_updates_db );
	}

	public static function get_card_details( array $update ): array {
		return UpdatePresentationHelper::get_card_details( $update );
	}

	public static function get_formatted_created_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateAuthorHelper::get_formatted_created_by( $update, $order_updates_db );
	}

	public static function get_formatted_assigned_to( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return AssigneeHelper::get_formatted_assigned_to( $update, $order_updates_db );
	}

	public static function get_assignee_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return AssigneeHelper::get_assignee_name( $update, $order_updates_db );
	}

	public static function get_formatted_is_customer_visible( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return CustomerHelper::get_formatted_is_customer_visible( $update, $order_updates_db );
	}

	public static function get_formatted_is_solved( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_formatted_is_solved( $update, $order_updates_db );
	}

	public static function get_solved_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_solved_by_name( $update, $order_updates_db );
	}

	public static function get_formatted_solved_by( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateStatusHelper::get_formatted_solved_by( $update, $order_updates_db );
	}

	public static function get_created_by_name( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdateAuthorHelper::get_created_by_name( $update, $order_updates_db );
	}

	public static function format_date( string $date, string $fallback = '' ): string {
		return DateHelper::format_date( $date, $fallback );
	}

	public static function get_formatted_customer_update_label( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return CustomerHelper::get_formatted_customer_update_label( $update, $order_updates_db );
	}

	public static function get_color_icon( array|object|int $update, ?OrderUpdatesDb $order_updates_db = null ): string {
		return UpdatePresentationHelper::get_color_icon( $update, $order_updates_db );
	}
}
