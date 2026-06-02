<?php
/**
 * Helper service for the orders table filters feature.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders\Services;

use OrderUpdatesForWoo\Helpers\HposHelper;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Detects the active orders list screen and whether HPOS is enabled.
 */
final class OrderTableFiltersService {
	/**
	 * Whether WooCommerce HPOS (custom orders table) is enabled.
	 */
	public function is_hpos_enabled(): bool {
		return HposHelper::is_enabled();
	}

	/**
	 * Whether the current admin screen is the orders list table.
	 */
	public function is_orders_list_screen(): bool {
		$screen = get_current_screen();

		return $screen && $screen->id === HposHelper::orders_list_screen_id();
	}

	/**
	 * Return the GET parameter name used for the assignee filter.
	 */
	public function assignee_param(): string {
		return 'awts_assignee';
	}

	/**
	 * Return the GET parameter name used for the unsolved filter.
	 */
	public function unsolved_param(): string {
		return 'awts_unsolved';
	}

	/**
	 * Return the currently active assignee filter value (user ID or 0).
	 */
	public function get_active_assignee_filter(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return absint( $_GET[ $this->assignee_param() ] ?? 0 );
	}

	/**
	 * Return whether the unsolved filter is active.
	 */
	public function get_active_unsolved_filter(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return ! empty( $_GET[ $this->unsolved_param() ] );
	}

	public function modify_query( \WP_Query $query, array $order_ids ): void {
		if ( $this->is_hpos_enabled() ) {
			// HPOS list table uses a different query structure, so delegate to a separate method.
			$this->modify_clauses( $query->query_vars['clauses'], $order_ids );
			return;
		}

		// Merge with any existing post__in restriction.
		$existing = (array) $query->get( 'post__in' );

		$ids = $order_ids
			? ( $existing ? array_intersect( $existing, $order_ids ) : $order_ids )
			: array( 0 ); // Force empty result when no orders match.

		$query->set( 'post__in', $ids );
	}

	public function modify_clauses( array &$clauses, array $order_ids ): array {
		if ( ! $this->is_hpos_enabled() ) {
			// Classic orders table uses different table aliases, so we can't reuse the same clause modifications.
			return $clauses;
		}
		$table = OrderUpdatesDb::orders_table_alias();

		if ( ! $order_ids ) {
			// No matches — force empty result.
			$clauses['where'] = ( $clauses['where'] ?? '' ) . ' AND 1 = 0';
			return $clauses;
		}

		$ids_placeholder  = implode( ',', array_map( 'absint', $order_ids ) );
		$clauses['where'] = ( $clauses['where'] ?? '' ) . " AND $table.id IN ({$ids_placeholder})";
		
		return $clauses;
	}
}
