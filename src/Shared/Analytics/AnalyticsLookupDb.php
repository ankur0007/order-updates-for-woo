<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Analytics;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;
use OrderUpdatesForWoo\Shared\Updates\UpdatesTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read + write API for the analytics lookup table.
 *
 * Write path is `sync_update($update_id)` — reads the update's current state
 * from the live tables (updates, assignees, ratings) and upserts one
 * lookup row. Every mutation point in OrderUpdatesDb calls this; the lookup
 * never drifts because the helper recomputes the canonical row from source
 * data instead of trusting deltas from each call site.
 *
 * Read path bypasses the live tables entirely — every analytics query hits
 * just this one compact table, bounded by the requested date range via the
 * created_date index. The product breakdown still joins WC order_items
 * (no way around that without a child lookup table) but the lookup-side
 * filter keeps the join bounded.
 */
final class AnalyticsLookupDb {
	public function __construct(
		private AnalyticsLookupTable $table,
		private UpdatesTable $updates_table
	) {}

	private const BACKFILL_HOOK   = 'order_updates_for_woo_analytics_backfill_batch';
	private const BACKFILL_GROUP  = 'order-updates-for-woo';
	private const BACKFILL_DONE   = 'order_updates_for_woo_analytics_backfill_done';

	/**
	 * Subscribe to the OrderUpdatesDb mutation hooks. Wiring the sync via
	 * action hooks keeps OrderUpdatesDb decoupled from the analytics layer —
	 * mutations don't have to know an analytics lookup exists, the lookup
	 * just listens. Same shape addons can use to extend their own
	 * incremental projections later.
	 */
	public function init(): void {
		add_action( 'order_updates_for_woo_update_changed', array( $this, 'sync_update' ), 10, 1 );
		add_action( 'order_updates_for_woo_update_deleted', array( $this, 'delete_for_update' ), 10, 1 );

		// Batch handler runs under Action Scheduler. Re-queues itself until
		// every existing update has been written into the lookup, then sets
		// the done flag so we don't re-trigger on every page load.
		add_action( self::BACKFILL_HOOK, array( $this, 'run_backfill_batch' ), 10, 1 );

		// On boot, schedule the first batch if backfill has never completed.
		// Cheap to check (one get_option) and idempotent (as_has_scheduled_action
		// guards against double-enqueue). Auto-runs after a fresh install or
		// after an upgrade where the table was just created with no rows.
		add_action( 'admin_init', array( $this, 'maybe_schedule_backfill' ) );
	}

	public function maybe_schedule_backfill(): void {
		if ( '1' === get_option( self::BACKFILL_DONE, '' ) ) {
			return;
		}

		if ( ! function_exists( 'as_has_scheduled_action' ) || ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		if ( as_has_scheduled_action( self::BACKFILL_HOOK ) ) {
			return;
		}

		as_enqueue_async_action( self::BACKFILL_HOOK, array( 0 ), self::BACKFILL_GROUP );
	}

	/**
	 * Process one backfill batch from $after_id. Re-queues itself with the
	 * next cursor as long as there's more data. Stays well under PHP/MySQL
	 * limits by capping each batch at 500 updates.
	 */
	public function run_backfill_batch( int $after_id ): void {
		$next = $this->backfill_batch( $after_id, 500 );

		if ( 0 === $next ) {
			update_option( self::BACKFILL_DONE, '1', false );
			return;
		}

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::BACKFILL_HOOK, array( $next ), self::BACKFILL_GROUP );
		}
	}

	/**
	 * Reset the backfill state. Used by the "Rebuild analytics" admin
	 * action — truncates the lookup, clears the done flag, kicks off a
	 * fresh backfill from id 0.
	 */
	public function rebuild_from_scratch(): void {
		$this->truncate();
		delete_option( self::BACKFILL_DONE );
		$this->maybe_schedule_backfill();
	}

	// -------------------------------------------------------------------------
	// Write path — mutations call this after touching the live tables.
	// -------------------------------------------------------------------------

	/**
	 * Rebuild the lookup row for an update from its current state in the
	 * live tables. Safe to call after any mutation; a no-op if the update
	 * no longer exists (handles race conditions between delete + retry).
	 */
	public function sync_update( int $update_id ): void {
		if ( ! $update_id ) {
			return;
		}

		$row = $this->compute_row( $update_id );

		if ( null === $row ) {
			// Update is gone — keep the lookup tidy.
			$this->delete_for_update( $update_id );
			return;
		}

		global $wpdb;

		// Existence check decides INSERT vs UPDATE. dbDelta's upsert isn't
		// available outside schema migrations, so a manual check keeps this
		// portable across MySQL versions that may not support ON DUPLICATE
		// KEY UPDATE with all the column expressions we'd want.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$this->table->lookup} WHERE update_id = %d LIMIT 1",
				$update_id
			)
		);

		if ( $existing ) {
			$wpdb->update(
				$this->table->lookup,
				$row,
				array( 'update_id' => $update_id )
			);
		} else {
			$wpdb->insert( $this->table->lookup, $row );
		}

		$this->bump_generation();
	}

	/**
	 * Remove the lookup row for an update. Called from delete_order_update
	 * after the live row is gone — keeps the lookup table in step so a
	 * deleted update vanishes from analytics immediately.
	 */
	public function delete_for_update( int $update_id ): void {
		if ( ! $update_id ) {
			return;
		}

		global $wpdb;

		$wpdb->delete( $this->table->lookup, array( 'update_id' => $update_id ), array( '%d' ) );

		$this->bump_generation();
	}

	// -------------------------------------------------------------------------
	// Read path — dashboards call these.
	// -------------------------------------------------------------------------

	/**
	 * @return array{total:int, solved:int, pending:int, avg_rating:float|null}
	 */
	public function summary( string $from, string $to ): array {
		$cache_key = 'analytics_summary_lk_' . $this->generation() . '_' . md5( $from . $to );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT
					COUNT(*) AS total,
					COALESCE( SUM( CASE WHEN solved_at IS NOT NULL THEN 1 ELSE 0 END ), 0 ) AS solved,
					ROUND( AVG( rating ), 1 ) AS avg_rating
				FROM {$this->table->lookup}
				WHERE created_date >= %s AND created_date <= %s",
				$from,
				$to
			),
			ARRAY_A
		);

		$total  = (int) ( $row['total'] ?? 0 );
		$solved = (int) ( $row['solved'] ?? 0 );

		$result = array(
			'total'      => $total,
			'solved'     => $solved,
			'pending'    => $total - $solved,
			'avg_rating' => null !== $row['avg_rating'] ? (float) $row['avg_rating'] : null,
		);

		set_transient( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * @return array<int, array{date:string, total:int, solved:int}>
	 */
	public function by_date( string $from, string $to ): array {
		$cache_key = 'analytics_by_date_lk_' . $this->generation() . '_' . md5( $from . $to );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					created_date AS date,
					COUNT(*) AS total,
					COALESCE( SUM( CASE WHEN solved_at IS NOT NULL THEN 1 ELSE 0 END ), 0 ) AS solved
				FROM {$this->table->lookup}
				WHERE created_date >= %s AND created_date <= %s
				GROUP BY created_date
				ORDER BY created_date ASC",
				$from,
				$to
			),
			ARRAY_A
		);

		$result = array_map(
			static fn( array $r ) => array(
				'date'   => (string) $r['date'],
				'total'  => (int) $r['total'],
				'solved' => (int) $r['solved'],
			),
			$rows ?: array()
		);

		$ttl = $to < gmdate( 'Y-m-d' ) ? Constants::ANALYTICS_CACHE_TTL : Variables::getUpdateCacheTtl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	/**
	 * @return array<int, array{user_id:int, name:string, total:int, solved:int, pending:int, avg_rating:float|null}>
	 */
	public function by_assignee( string $from, string $to ): array {
		$cache_key = 'analytics_assignees_lk_' . $this->generation() . '_' . md5( $from . $to );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					assignee_user_id AS user_id,
					COUNT(*) AS total,
					COALESCE( SUM( CASE WHEN solved_at IS NOT NULL THEN 1 ELSE 0 END ), 0 ) AS solved,
					ROUND( AVG( rating ), 1 ) AS avg_rating
				FROM {$this->table->lookup}
				WHERE created_date >= %s AND created_date <= %s
				  AND assignee_user_id > 0
				GROUP BY assignee_user_id
				ORDER BY total DESC",
				$from,
				$to
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			set_transient( $cache_key, array(), Variables::getUpdateCacheTtl() );
			return array();
		}

		$user_ids = array_column( $rows, 'user_id' );
		$users    = get_users( array(
			'include' => $user_ids,
			'fields'  => array( 'ID', 'display_name' ),
		) );

		$names = array();
		foreach ( $users as $user ) {
			$names[ (int) $user->ID ] = (string) $user->display_name;
		}

		$result = array_map(
			static fn( array $r ) => array(
				'user_id'    => (int) $r['user_id'],
				'name'       => $names[ (int) $r['user_id'] ] ?? '#' . $r['user_id'],
				'total'      => (int) $r['total'],
				'solved'     => (int) $r['solved'],
				'pending'    => (int) $r['total'] - (int) $r['solved'],
				'avg_rating' => null !== $r['avg_rating'] ? (float) $r['avg_rating'] : null,
			),
			$rows
		);

		set_transient( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * @return array<int, array{product_id:int, name:string, total:int, solved:int, pending:int}>
	 */
	public function by_product( string $from, string $to ): array {
		$cache_key = 'analytics_products_lk_' . $this->generation() . '_' . md5( $from . $to );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		global $wpdb;

		$items_table    = $wpdb->prefix . Constants::WC_ORDER_ITEMS_TABLE;
		$itemmeta_table = $wpdb->prefix . Constants::WC_ORDER_ITEMMETA_TABLE;

		// Filter on the lookup first, then JOIN to order items + itemmeta to
		// expand by product. Outer GROUP BY collapses back to one row per
		// (update, product). Driving table is the small lookup, not updates.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					CAST( im.meta_value AS UNSIGNED ) AS product_id,
					COUNT( DISTINCT l.update_id ) AS total,
					COALESCE( SUM( CASE WHEN l.solved_at IS NOT NULL THEN 1 ELSE 0 END ), 0 ) AS solved
				FROM {$this->table->lookup} l
				INNER JOIN {$items_table} oi
					ON oi.order_id = l.order_id AND oi.order_item_type = 'line_item'
				INNER JOIN {$itemmeta_table} im
					ON im.order_item_id = oi.order_item_id AND im.meta_key = '_product_id'
				WHERE l.created_date >= %s AND l.created_date <= %s
				  AND im.meta_value > 0
				GROUP BY im.meta_value
				ORDER BY total DESC
				LIMIT 20",
				$from,
				$to
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			$ttl = $to < gmdate( 'Y-m-d' ) ? Constants::ANALYTICS_CACHE_TTL : Variables::getUpdateCacheTtl();
			set_transient( $cache_key, array(), $ttl );
			return array();
		}

		$result = array_map(
			static fn( array $r ) => array(
				'product_id' => (int) $r['product_id'],
				'name'       => (string) get_the_title( (int) $r['product_id'] ),
				'total'      => (int) $r['total'],
				'solved'     => (int) $r['solved'],
				'pending'    => (int) $r['total'] - (int) $r['solved'],
			),
			$rows
		);

		$ttl = $to < gmdate( 'Y-m-d' ) ? Constants::ANALYTICS_CACHE_TTL : Variables::getUpdateCacheTtl();
		set_transient( $cache_key, $result, $ttl );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Backfill — one-shot populate for existing installs.
	// -------------------------------------------------------------------------

	/**
	 * Process one batch of legacy updates into the lookup. Returns the next
	 * cursor (0 when done). The caller schedules a follow-up batch until
	 * the cursor hits 0, so the backfill scales linearly without locking
	 * the DB or blowing PHP memory.
	 */
	public function backfill_batch( int $after_id, int $batch_size = 500 ): int {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$this->updates_table->updates}
				WHERE id > %d
				ORDER BY id ASC
				LIMIT %d",
				$after_id,
				$batch_size
			)
		);

		if ( empty( $ids ) ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			$this->sync_update( (int) $id );
		}

		return (int) end( $ids );
	}

	/**
	 * Drop every lookup row. Used by the "Rebuild analytics" admin action
	 * before kicking off a fresh backfill.
	 */
	public function truncate(): void {
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$this->table->lookup}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange -- own-schema table name, no user input

		$this->bump_generation();
	}

	// -------------------------------------------------------------------------
	// Internals.
	// -------------------------------------------------------------------------

	/**
	 * Read the update's current state from the live tables and shape the
	 * lookup row. Returns null when the update no longer exists.
	 *
	 * @return array<string, mixed>|null
	 */
	private function compute_row( int $update_id ): ?array {
		global $wpdb;

		$update = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, order_id, created_at, solved_at, is_resolved, created_by, customer_visible
				FROM {$this->updates_table->updates}
				WHERE id = %d
				LIMIT 1",
				$update_id
			),
			ARRAY_A
		);

		if ( ! $update ) {
			return null;
		}

		$assignee_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT assignee_user_id
				FROM {$this->updates_table->assignees}
				WHERE update_id = %d AND is_active = 1
				ORDER BY assigned_at DESC
				LIMIT 1",
				$update_id
			)
		);

		$rating = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT stars, comment, created_at
				FROM {$this->updates_table->ratings}
				WHERE update_id = %d
				LIMIT 1",
				$update_id
			),
			ARRAY_A
		);

		$created_at  = (string) ( $update['created_at'] ?? '' );
		$is_resolved = (int) ( $update['is_resolved'] ?? 0 );
		// solved_at stays populated in the live table even after a reopen
		// (audit trail of the most recent solve). For analytics, "solved"
		// means *currently* resolved, so null it out when is_resolved = 0.
		$solved_at   = ( $is_resolved && ! empty( $update['solved_at'] ) ) ? (string) $update['solved_at'] : null;
		$created_by  = (int) ( $update['created_by'] ?? 0 );

		$resolution_seconds = null;
		if ( $solved_at && $created_at ) {
			$delta = strtotime( $solved_at ) - strtotime( $created_at );
			$resolution_seconds = $delta > 0 ? $delta : 0;
		}

		$rating_stars     = $rating && null !== $rating['stars'] ? (int) $rating['stars'] : null;
		$rating_comment   = (string) ( $rating['comment'] ?? '' );
		$rating_at        = $rating && ! empty( $rating['created_at'] ) ? (string) $rating['created_at'] : null;

		return array(
			'update_id'             => $update_id,
			'order_id'              => (int) ( $update['order_id'] ?? 0 ),
			'created_at'            => $created_at,
			'created_date'          => substr( $created_at, 0, 10 ),
			'solved_at'             => $solved_at,
			'solved_date'           => $solved_at ? substr( $solved_at, 0, 10 ) : null,
			'resolution_seconds'    => $resolution_seconds,
			'assignee_user_id'      => $assignee_id,
			'created_by_user_id'    => $created_by,
			// Customer-opened updates have created_by = 0 (guest) by convention.
			// The dashboard's "submitted by customer" filter reads from this.
			'is_customer_initiated' => 0 === $created_by ? 1 : 0,
			'customer_visible'      => (int) ( $update['customer_visible'] ?? 0 ),
			'rating'                => $rating_stars,
			'rating_at'             => $rating_at,
			'has_rating_comment'    => '' !== $rating_comment ? 1 : 0,
			'product_id'            => null,
		);
	}

	private function generation(): int {
		$cache_key = Constants::ANALYTICS_GEN_PFX . 'lookup';
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$ver = (int) get_option( Constants::ANALYTICS_GEN_OPTION_PFX . 'lookup', 0 );
		wp_cache_set( $cache_key, $ver, Constants::CACHE_GROUP );

		return $ver;
	}

	/**
	 * Public alias around bump_generation — invalidates every cached
	 * analytics response in one write. Called by the "Clear analytics
	 * cache" admin button (Cache settings tab); other writes bump it
	 * implicitly via sync_update / delete_for_update.
	 */
	public function bust_cache(): void {
		$this->bump_generation();
	}

	private function bump_generation(): void {
		$option_key = Constants::ANALYTICS_GEN_OPTION_PFX . 'lookup';
		$next       = (int) get_option( $option_key, 0 ) + 1;

		update_option( $option_key, $next, false );
		wp_cache_set( Constants::ANALYTICS_GEN_PFX . 'lookup', $next, Constants::CACHE_GROUP );
	}
}
