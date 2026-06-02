<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

final class UpdatePresentationHelper {
	public static function get_color_icon( array|object|int $update, ?\OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb $order_updates_db = null ): string {
		$resolved_update = UpdateResolver::normalize_update( $update, $order_updates_db );

		if ( empty( $resolved_update['color'] ) ) {
			return (string) apply_filters( 'order_updates_for_woo_color_icon', '', $resolved_update );
		}

		$color_icon = 'background:' . esc_attr( (string) $resolved_update['color'] ) . ';';

		return (string) apply_filters( 'order_updates_for_woo_color_icon', $color_icon, $resolved_update );
	}

	public static function get_card_details( array $update ): array {
		return array(
			'is_customer_visible' => ! empty( $update['customer_visible'] ),
			'created'             => UpdateAuthorHelper::get_formatted_created_by( $update ),
			'created_by_name'     => UpdateAuthorHelper::get_created_by_name( $update ),
			'created_date'        => DateHelper::format_date( (string) ( $update['created_at'] ?? '' ) ),
			'assigned_to'         => AssigneeHelper::get_assignee_name( $update ),
			'solved_by'           => UpdateStatusHelper::get_solved_by_name( $update ),
			'solved_line'         => UpdateStatusHelper::get_formatted_solved_by( $update ),
			'customer_update'     => CustomerHelper::get_formatted_customer_update_label( $update ),
			'dot_style'           => self::get_color_icon( $update ),
		);
	}
}
