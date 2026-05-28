<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Analytics;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema owner for the analytics lookup table.
 *
 * One denormalized row per update, written incrementally whenever an update
 * is created / solved / reopened / rated / reassigned / edited / deleted.
 * The dashboard reads from this table only — never joining back to the live
 * `updates` table. That's how the dashboard stays fast even when the store
 * grows to millions of orders: every query hits a compact, well-indexed
 * row set bounded by the requested date range, not a full join across
 * updates + assignees + ratings + order items.
 */
final class AnalyticsLookupTable {
	private const VERSION     = '1.0.0';
	private const VERSION_KEY = 'order_updates_for_woo_analytics_lookup_version';

	public string $lookup;

	public function __construct() {
		global $wpdb;
		$this->lookup = $wpdb->prefix . 'order_updates_for_woo_analytics_lookup';
	}

	public function init(): void {
		add_action( 'init', array( $this, 'maybe_create_table' ) );
	}

	public function maybe_create_table(): void {
		if ( (string) get_option( self::VERSION_KEY, '' ) === self::VERSION && $this->table_exists() ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		global $wpdb;
		$c = $wpdb->get_charset_collate();

		dbDelta( "CREATE TABLE {$this->lookup} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			update_id BIGINT(20) UNSIGNED NOT NULL,
			order_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL,
			created_date DATE NOT NULL,
			solved_at DATETIME NULL,
			solved_date DATE NULL,
			resolution_seconds INT UNSIGNED NULL,
			assignee_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_by_user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			is_customer_initiated TINYINT(1) NOT NULL DEFAULT 0,
			customer_visible TINYINT(1) NOT NULL DEFAULT 0,
			rating TINYINT(1) UNSIGNED NULL,
			rating_at DATETIME NULL,
			has_rating_comment TINYINT(1) NOT NULL DEFAULT 0,
			product_id BIGINT(20) UNSIGNED NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY update_id (update_id),
			KEY order_id (order_id),
			KEY created_date (created_date),
			KEY solved_date (solved_date),
			KEY assignee_created (assignee_user_id, created_date),
			KEY rating (rating),
			KEY product_id (product_id)
		) {$c};" );

		update_option( self::VERSION_KEY, self::VERSION );
	}

	private function table_exists(): bool {
		global $wpdb;

		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->lookup ) );

		return null !== $found && '' !== (string) $found;
	}
}
