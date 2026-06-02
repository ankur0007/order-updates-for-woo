<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Config\Variables;

// Direct queries on our own tables. Table names are safe; user input always uses prepare().
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.SlowDBQuery, PluginCheck.Security.DirectDB.UnescapedDBParameter

final class OrderUpdatesDb {
	public function __construct( private UpdatesTable $updates_table ) {}

	public static function orders_table_alias(): string {
		global $wpdb;
		return "{$wpdb->prefix}wc_orders";
	}

	private function cache_get( string $key ): mixed {
		return wp_cache_get( $key, Constants::CACHE_GROUP );
	}

	private function cache_set( string $key, mixed $value, int $ttl = 0 ): void {
		wp_cache_set( $key, $value, Constants::CACHE_GROUP, $ttl );
	}

	private function cache_delete( string $key ): void {
		wp_cache_delete( $key, Constants::CACHE_GROUP );
	}

	private function increment_order_updates_cache_version( int $order_id ): void {
		$key = "order_updates_ver_{$order_id}";
		$version = (int) $this->cache_get( $key );
		$this->cache_set( $key, $version + 1 );
	}

	private function get_order_updates_cache_version( int $order_id ): int {
		$cached = $this->cache_get( "order_updates_ver_{$order_id}" );
		return $cached !== false ? (int) $cached : 0;
	}

	private function invalidate_order_caches( int $order_id ): void {
		$this->cache_delete( "summary_{$order_id}" );
		$this->cache_delete( "count_{$order_id}" );
		$this->cache_delete( 'unsolved_order_ids' );
		$this->increment_order_updates_cache_version( $order_id );
	}

	/**
	 * Bust only the update row cache and the parent order's list version.
	 *
	 * Use this when an edit changes ONLY columns on the updates table
	 * (title, color, customer_visible, last_updated_at) — not notes,
	 * not history, not rating, not assignee. Saves cache rebuild work
	 * on the unrelated concerns.
	 *
	 * Default rule for everything else: call invalidate_update_caches().
	 * Coarse-but-correct beats fine-but-fragile when a missed bust shows
	 * customers stale data.
	 */
	private function invalidate_update_row_cache( int $update_id, int $order_id ): void {
		$this->cache_delete( "update_{$update_id}" );
		$this->invalidate_order_caches( $order_id );
	}

	/**
	 * Bust every cache touching an update — its row, both note streams,
	 * history, rating, the customer-notes paged version, and the parent
	 * order list version. Optionally also a per-user assigned-orders cache.
	 *
	 * This is the default bust for any write path that touches update
	 * state. Prefer this over inventing fine-grained busts unless a
	 * specific path has been profiled and proven hot — see
	 * invalidate_update_row_cache() for the only narrower variant in use.
	 */
	private function invalidate_update_caches( int $update_id, int $order_id, int $user_id = 0 ): void {
		$this->cache_delete( "update_{$update_id}" );
		$this->cache_delete( "notes_{$update_id}" );
		$this->cache_delete( "customer_notes_{$update_id}" );
		$this->cache_delete( "history_{$update_id}" );
		$this->cache_delete( "rating_{$update_id}" );
		$this->increment_customer_notes_cache_version( $update_id );
		$this->invalidate_order_caches( $order_id );

		if ( $user_id ) {
			$this->cache_delete( "assigned_orders_{$user_id}" );
		}

		$this->cache_delete( 'users_with_assignments' );
	}

	private function increment_customer_notes_cache_version( int $update_id ): void {
		$key     = "customer_notes_ver_{$update_id}";
		$version = (int) $this->cache_get( $key );
		$this->cache_set( $key, $version + 1 );
	}

	private function get_customer_notes_cache_version( int $update_id ): int {
		$cached = $this->cache_get( "customer_notes_ver_{$update_id}" );
		return false !== $cached ? (int) $cached : 0;
	}

	private function increment_update_notes_cache_version( int $update_id ): void {
		$key     = "update_notes_ver_{$update_id}";
		$version = (int) $this->cache_get( $key );
		$this->cache_set( $key, $version + 1 );
	}

	private function get_update_notes_cache_version( int $update_id ): int {
		$cached = $this->cache_get( "update_notes_ver_{$update_id}" );
		return false !== $cached ? (int) $cached : 0;
	}

	/**
	 * Bust the mentions cache for each user listed in $user_ids.
	 * Call this after creating or deleting a note that contains @mentions.
	 *
	 * @param int[] $user_ids
	 */
	public function invalidate_mention_caches( array $user_ids ): void {
		foreach ( $user_ids as $uid ) {
			$uid = (int) $uid;
			if ( $uid ) {
				$this->cache_delete( "user_mentions_{$uid}" );
			}
		}
	}

	private function invalidate_mention_caches_for_update( int $update_id ): void {
		global $wpdb;

		$rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT mentioned_user_ids FROM {$this->updates_table->notes}
				WHERE update_id = %d AND mentioned_user_ids != ''",
				$update_id
			)
		);

		if ( empty( $rows ) ) {
			return;
		}

		$user_ids = array();
		foreach ( $rows as $raw ) {
			foreach ( $this->decode_mention_ids( (string) $raw ) as $uid ) {
				$user_ids[] = $uid;
			}
		}

		$this->invalidate_mention_caches( array_unique( $user_ids ) );
	}

	/**
	 * Look up the order_id for an update and invalidate all related caches.
	 * Use this when you have an update_id but no order_id at hand.
	 */
	private function invalidate_for_update( int $update_id ): void {
		$update = $this->get_update( $update_id );
		$order_id = absint( $update['order_id'] ?? 0 );

		if ( $order_id ) {
			$this->invalidate_update_caches( $update_id, $order_id );
		} else {
			$this->cache_delete( "update_{$update_id}" );
			$this->cache_delete( "notes_{$update_id}" );
			$this->cache_delete( "customer_notes_{$update_id}" );
			$this->cache_delete( "history_{$update_id}" );
		}

		// Version-bumped paged caches don't expose a fixed key set to delete,
		// so bumping the version is the invalidation. Cheap (one cache write
		// per write path) and survives any number of stale page snapshots.
		$this->increment_update_notes_cache_version( $update_id );
	}

	public function create_order_update( array $update_data ): int {
		global $wpdb;

		$wpdb->insert(
			$this->updates_table->updates,
			array(
				'order_id' => $update_data['order_id'],
				'title' => $update_data['title'],
				'status' => (string) ( $update_data['status'] ?? '' ),
				'customer_visible' => $update_data['customer_visible'],
				'color' => $update_data['color'],
				'created_by' => $update_data['created_by'],
				'last_updated_by' => $update_data['created_by'],
				'is_resolved' => 0,
				'created_at' => $update_data['created_at'],
				'last_updated_at' => $update_data['created_at'],
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		$insert_id = (int) $wpdb->insert_id;

		if ( $insert_id ) {
			$this->invalidate_order_caches( (int) $update_data['order_id'] );
			do_action( 'order_updates_for_woo_update_changed', $insert_id );
		}

		return $insert_id;
	}

	public function edit_order_update( int $update_id, array $update_data ): bool {
		global $wpdb;

		if ( ! $update_id ) {
			return false;
		}

		$existing  = $this->get_update( $update_id );
		$old_title = (string) ( $existing['title'] ?? '' );
		$new_title = (string) ( $update_data['title'] ?? '' );

		$result = false !== $wpdb->update(
			$this->updates_table->updates,
			array(
				'title' => $new_title,
				'status' => (string) ( $update_data['status'] ?? '' ),
				'customer_visible' => $update_data['customer_visible'],
				'color' => $update_data['color'],
				'last_updated_by' => $update_data['last_updated_by'],
				'last_updated_at' => $update_data['last_updated_at'],
			),
			array( 'id' => $update_id ),
			array( '%s', '%s', '%d', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$order_id = (int) ( $update_data['order_id'] ?? 0 );

			// Log a title-rename event in customer_notes (kind='title_change')
			// so the tracking-log surface lists who renamed an update and when.
			// These rows are filtered out of customer-facing thread queries —
			// title is admin-internal metadata; the customer doesn't see it.
			if ( $old_title !== $new_title ) {
				$this->log_title_change_event(
					$update_id,
					$old_title,
					$new_title,
					(int) ( $update_data['last_updated_by'] ?? 0 ),
					(string) ( $update_data['last_updated_at'] ?? current_time( 'mysql', true ) )
				);

				// Title change writes a customer_notes row, so the narrower
				// row-only bust isn't enough — clear the history cache too.
				if ( $order_id ) {
					$this->invalidate_update_caches( $update_id, $order_id );
				} else {
					$this->cache_delete( "update_{$update_id}" );
					$this->cache_delete( "history_{$update_id}" );
				}
			} elseif ( $order_id ) {
				// Title / color / visible / last_updated edits do not touch
				// notes, history, rating, or assignee — narrower bust avoids
				// pointless rebuilds on unrelated caches.
				$this->invalidate_update_row_cache( $update_id, $order_id );
			} else {
				$this->cache_delete( "update_{$update_id}" );
			}

			do_action( 'order_updates_for_woo_update_changed', $update_id );
		}

		return $result;
	}

	/**
	 * Append a customer_notes row tagged 'title_change' so the tracking log
	 * captures who renamed the update and when. Customer-facing queries skip
	 * this kind — title is purely admin-internal metadata.
	 */
	private function log_title_change_event( int $update_id, string $old_title, string $new_title, int $actor_id, string $changed_at ): void {
		global $wpdb;

		$message = sprintf(
			/* translators: 1: previous title, 2: new title. */
			__( 'Title changed from "%1$s" to "%2$s"', 'order-updates-for-woo' ),
			$old_title,
			$new_title
		);

		$actor      = $actor_id ? get_userdata( $actor_id ) : null;
		$actor_name = $actor instanceof \WP_User ? (string) $actor->display_name : '';

		$wpdb->insert(
			$this->updates_table->customer_notes,
			array(
				'update_id'       => $update_id,
				'note'            => $message,
				'kind'            => 'title_change',
				'created_by'      => $actor_id,
				'created_by_name' => $actor_name,
				'created_at'      => $changed_at,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);
	}

	/**
	 * Write a "lifecycle event" row to customer_notes for surfacing in the
	 * tracking log. Used by reopen + rating events so the tracking history
	 * captures the full lifecycle, not just the most recent state. Both
	 * kinds are filtered out of customer-facing thread queries elsewhere —
	 * these are admin-tracking rows, not chat messages.
	 */
	public function log_lifecycle_event( int $update_id, string $kind, string $message, int $actor_id, string $when ): void {
		global $wpdb;

		if ( ! $update_id || '' === $kind || '' === $when ) {
			return;
		}

		$actor      = $actor_id ? get_userdata( $actor_id ) : null;
		$actor_name = $actor instanceof \WP_User ? (string) $actor->display_name : '';

		$wpdb->insert(
			$this->updates_table->customer_notes,
			array(
				'update_id'       => $update_id,
				'note'            => $message,
				'kind'            => $kind,
				'created_by'      => $actor_id,
				'created_by_name' => $actor_name,
				'created_at'      => $when,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->invalidate_for_update( $update_id );
	}

	/**
	 * Change an update's status (color) and stamp a system row into its
	 * customer-notes thread so the change is visible in-line with the rest
	 * of the conversation. The system row carries the new status in its
	 * `status` column and an empty `note` body so presenters can render it
	 * as a "status changed" marker instead of a regular bubble. Returns
	 * the inserted note id on success, 0 when the color didn't change or
	 * when the write failed.
	 */
	public function change_update_status( int $update_id, string $new_status_key, string $new_color, int $changed_by, string $changed_by_name, string $changed_at, string $message = '' ): int {
		global $wpdb;

		if ( ! $update_id || '' === $new_status_key || '' === $changed_at ) {
			return 0;
		}

		$current        = $this->get_update( $update_id );
		$old_status_key = (string) ( $current['status'] ?? '' );

		// Compare by status key — color is a derived property. No-op when
		// the key didn't change so the thread doesn't accumulate "changed
		// to X" rows for non-changes.
		if ( $old_status_key === $new_status_key ) {
			return 0;
		}

		$updated = false !== $wpdb->update(
			$this->updates_table->updates,
			array(
				'status'          => $new_status_key,
				'color'           => $new_color,
				'last_updated_by' => $changed_by,
				'last_updated_at' => $changed_at,
			),
			array( 'id' => $update_id ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%d' )
		);

		if ( ! $updated ) {
			return 0;
		}

		// Insert a system row in customer_notes. The `note` column carries
		// the human-readable message; `status` carries the status key
		// snapshot at the moment of the change so the history line can
		// render the right pill even if the admin later renames the key.
		$insert_ok = $wpdb->insert(
			$this->updates_table->customer_notes,
			array(
				'update_id'       => $update_id,
				'note'            => $message,
				'kind'            => 'status_change',
				'created_by'      => $changed_by,
				'created_by_name' => $changed_by_name,
				'created_at'      => $changed_at,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$note_id = $insert_ok ? (int) $wpdb->insert_id : 0;

		$order_id = absint( $current['order_id'] ?? 0 );
		if ( $order_id ) {
			$this->invalidate_update_caches( $update_id, $order_id );
		} else {
			$this->cache_delete( "update_{$update_id}" );
		}

		do_action( 'order_updates_for_woo_update_changed', $update_id );
		do_action( 'order_updates_for_woo_status_changed', $update_id, $old_status_key, $new_status_key, $changed_by, $note_id );

		return $note_id;
	}

	public function create_assignee( int $update_id, int $assignee_user_id, int $assigned_by, string $assigned_at ): bool {
		global $wpdb;

		$result = false !== $wpdb->insert(
			$this->updates_table->assignees,
			array(
				'update_id' => $update_id,
				'assignee_user_id' => $assignee_user_id,
				'assigned_by' => $assigned_by,
				'assigned_at' => $assigned_at,
				'is_active' => 1,
				'last_updated_at' => $assigned_at,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%s' )
		);

		if ( $result ) {
			$this->invalidate_for_update( $update_id );
			$this->cache_delete( "assigned_orders_{$assignee_user_id}" );
			$this->cache_delete( 'users_with_assignments' );
		}

		return $result;
	}

	public function sync_assignee( int $update_id, int $assignee_user_id, int $assigned_by, string $assigned_at ): bool {
		global $wpdb;

		$current = $this->get_update( $update_id );
		$current_assignee_id = absint( $current['assignee_user_id'] ?? 0 );

		if ( $current_assignee_id === $assignee_user_id ) {
			return true;
		}

		// Record the reassignment anchor so the customer thread can inject a divider.
		if ( $current_assignee_id > 0 && $assignee_user_id > 0 ) {
			$latest_note_id = $this->get_latest_customer_note_id_for_update( $update_id );
			$prev_name      = (string) ( $current['assignee_name'] ?? '' );

			if ( $latest_note_id > 0 && '' !== $prev_name ) {
				$wpdb->update(
					$this->updates_table->updates,
					array(
						'assignee_since_note_id' => $latest_note_id,
						'previous_assignee_name' => $prev_name,
					),
					array( 'id' => $update_id ),
					array( '%d', '%s' ),
					array( '%d' )
				);
			}
		}

		if ( $current_assignee_id ) {
			$this->deactivate_active_assignees( $update_id, $current_assignee_id, $assigned_by, $assigned_at );
		}

		if ( ! $assignee_user_id ) {
			$this->log_assignee_change_in_thread(
				$update_id,
				(string) ( $current['assignee_name'] ?? '' ),
				'',
				$assigned_by,
				$assigned_at
			);
			do_action( 'order_updates_for_woo_update_changed', $update_id );
			return true;
		}

		$result = $this->create_assignee( $update_id, $assignee_user_id, $assigned_by, $assigned_at );

		if ( $result ) {
			$new_user      = get_userdata( $assignee_user_id );
			$new_name      = $new_user instanceof \WP_User ? (string) $new_user->display_name : '';

			$this->log_assignee_change_in_thread(
				$update_id,
				(string) ( $current['assignee_name'] ?? '' ),
				$new_name,
				$assigned_by,
				$assigned_at
			);

			do_action(
				'order_updates_for_woo_admin_bar_assigned',
				$update_id,
				(int) ( $current['order_id'] ?? 0 ),
				(string) ( $current['title'] ?? '' ),
				$assignee_user_id
			);
			do_action( 'order_updates_for_woo_update_changed', $update_id );
		}

		return $result;
	}

	/**
	 * Stamp a system row into the customer-notes thread describing an
	 * assignee change. Per the email-flow spec (C) the customer never gets
	 * emailed about this, but they DO see it in their chat timeline so they
	 * know who's currently looking after their thread. The acting staff
	 * member's name lands in created_by_name so the entry reads
	 * "Reassigned to Bob — by Alice".
	 */
	private function log_assignee_change_in_thread( int $update_id, string $old_name, string $new_name, int $actor_id, string $changed_at ): void {
		global $wpdb;

		if ( ! $update_id ) {
			return;
		}

		if ( '' !== $new_name ) {
			$message = '' !== $old_name
				? sprintf(
					/* translators: 1: previous assignee name, 2: new assignee name. */
					__( 'Reassigned from %1$s to %2$s', 'order-updates-for-woo' ),
					$old_name,
					$new_name
				)
				: sprintf(
					/* translators: %s: new assignee name. */
					__( 'Assigned to %s', 'order-updates-for-woo' ),
					$new_name
				);
		} elseif ( '' !== $old_name ) {
			$message = sprintf(
				/* translators: %s: previous assignee name. */
				__( 'Unassigned %s', 'order-updates-for-woo' ),
				$old_name
			);
		} else {
			return;
		}

		$actor      = $actor_id ? get_userdata( $actor_id ) : null;
		$actor_name = $actor instanceof \WP_User ? (string) $actor->display_name : '';

		$wpdb->insert(
			$this->updates_table->customer_notes,
			array(
				'update_id'       => $update_id,
				'note'            => $message,
				'kind'            => 'assignee_change',
				'created_by'      => $actor_id,
				'created_by_name' => $actor_name,
				'created_at'      => $changed_at,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->increment_customer_notes_cache_version( $update_id );
	}

	private function get_latest_customer_note_id_for_update( int $update_id ): int {
		global $wpdb;

		if ( ! $update_id ) {
			return 0;
		}

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$this->updates_table->customer_notes} WHERE update_id = %d",
				$update_id
			)
		);
	}

	public function mark_as_solved( int $update_id, int $solved_by, string $solved_at ): bool {
		global $wpdb;

		if ( ! $update_id || ! $solved_by ) {
			return false;
		}

		$current = $this->get_update( $update_id );
		$order_id = absint( $current['order_id'] ?? 0 );

		$result = false !== $wpdb->update(
			$this->updates_table->updates,
			array(
				'is_resolved' => 1,
				'solved_by' => $solved_by,
				'solved_at' => $solved_at,
				'last_updated_at' => $solved_at,
			),
			array( 'id' => $update_id ),
			array( '%d', '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->invalidate_mention_caches_for_update( $update_id );

			if ( $order_id ) {
				$this->invalidate_update_caches( $update_id, $order_id );
			} else {
				$this->cache_delete( "update_{$update_id}" );
				$this->cache_delete( 'unsolved_order_ids' );
			}

			do_action( 'order_updates_for_woo_update_changed', $update_id );
		}

		return $result;
	}

	public function mark_as_unsolved( int $update_id, int $reopened_by = 0 ): bool {
		global $wpdb;

		if ( ! $update_id ) {
			return false;
		}

		$current = $this->get_update( $update_id );
		$order_id = absint( $current['order_id'] ?? 0 );

		$result = false !== $wpdb->update(
			$this->updates_table->updates,
			array(
				'is_resolved' => 0,
				'last_updated_by' => $reopened_by ?: null,
				'last_updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => $update_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->invalidate_mention_caches_for_update( $update_id );

			if ( $order_id ) {
				$this->invalidate_update_caches( $update_id, $order_id );
			} else {
				$this->cache_delete( "update_{$update_id}" );
				$this->cache_delete( 'unsolved_order_ids' );
			}

			do_action( 'order_updates_for_woo_update_changed', $update_id );
		}

		return $result;
	}

	/**
	 * Delete every update row (plus assignees, notes, and customer notes) for an order.
	 * Used by the cascade delete hook when the underlying order is removed.
	 *
	 * Returns the list of update ids that were removed so callers can clean up
	 * related resources such as attachment files on disk.
	 *
	 * @return int[]
	 */
	public function delete_all_for_order( int $order_id ): array {
		global $wpdb;

		if ( ! $order_id ) {
			return array();
		}

		$update_ids = array_map(
			'absint',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT id FROM {$this->updates_table->updates} WHERE order_id = %d",
					$order_id
				)
			)
		);

		if ( empty( $update_ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $update_ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->updates_table->assignees} WHERE update_id IN ({$placeholders})", ...$update_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->updates_table->notes} WHERE update_id IN ({$placeholders})", ...$update_ids ) );

		// Capture customer-note ids first so the history cascade still has
		// something to match against after the parent rows are wiped.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$customer_note_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT id FROM {$this->updates_table->customer_notes} WHERE update_id IN ({$placeholders})",
			...$update_ids
		) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->updates_table->customer_notes} WHERE update_id IN ({$placeholders})", ...$update_ids ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$this->updates_table->ratings} WHERE update_id IN ({$placeholders})", ...$update_ids ) );

		if ( ! empty( $customer_note_ids ) ) {
			$history_placeholders = implode( ',', array_fill( 0, count( $customer_note_ids ), '%d' ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated above
			$wpdb->query( $wpdb->prepare(
				"DELETE FROM {$this->updates_table->customer_note_history} WHERE note_id IN ({$history_placeholders})",
				...array_map( 'intval', $customer_note_ids )
			) );
		}

		$wpdb->delete( $this->updates_table->updates, array( 'order_id' => $order_id ), array( '%d' ) );

		foreach ( $update_ids as $update_id ) {
			$this->cache_delete( "update_{$update_id}" );
			$this->cache_delete( "notes_{$update_id}" );
			$this->cache_delete( "customer_notes_{$update_id}" );
			$this->cache_delete( "history_{$update_id}" );
			$this->increment_customer_notes_cache_version( $update_id );
			do_action( 'order_updates_for_woo_update_deleted', $update_id );
		}

		$this->invalidate_order_caches( $order_id );

		return $update_ids;
	}

	public function delete_order_update( int $update_id ): bool {
		global $wpdb;

		if ( ! $update_id ) {
			return false;
		}

		$update = $this->get_update( $update_id );
		$order_id = absint( $update['order_id'] ?? 0 );

		$result = false !== $wpdb->delete(
			$this->updates_table->updates,
			array( 'id' => $update_id ),
			array( '%d' )
		);

		if ( $result ) {
			$wpdb->delete( $this->updates_table->assignees, array( 'update_id' => $update_id ), array( '%d' ) );
			$wpdb->delete( $this->updates_table->notes, array( 'update_id' => $update_id ), array( '%d' ) );

			// Capture customer-note ids before the parent rows are gone so we
			// can cascade their history. Skipped this until now and the
			// history table would orphan rows on every update delete.
			$customer_note_ids = $wpdb->get_col( $wpdb->prepare(
				"SELECT id FROM {$this->updates_table->customer_notes} WHERE update_id = %d",
				$update_id
			) );

			$wpdb->delete( $this->updates_table->customer_notes, array( 'update_id' => $update_id ), array( '%d' ) );
			$wpdb->delete( $this->updates_table->ratings, array( 'update_id' => $update_id ), array( '%d' ) );

			if ( ! empty( $customer_note_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $customer_note_ids ), '%d' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders generated above
				$wpdb->query( $wpdb->prepare(
					"DELETE FROM {$this->updates_table->customer_note_history} WHERE note_id IN ({$placeholders})",
					...array_map( 'intval', $customer_note_ids )
				) );
			}

			$this->invalidate_update_caches( $update_id, $order_id );
			do_action( 'order_updates_for_woo_update_deleted', $update_id );
		}

		return $result;
	}

	public function mark_assignee_notified( int $update_id, int $assignee_user_id, string $notified_at ): bool {
		global $wpdb;

		if ( ! $update_id || ! $assignee_user_id || '' === $notified_at ) {
			return false;
		}

		$result = false !== $wpdb->update(
			$this->updates_table->assignees,
			array(
				'notified_at' => $notified_at,
				'last_updated_at' => $notified_at,
			),
			array(
				'update_id' => $update_id,
				'assignee_user_id' => $assignee_user_id,
				'is_active' => 1,
			),
			array( '%s', '%s' ),
			array( '%d', '%d', '%d' )
		);

		if ( $result ) {
			$this->invalidate_for_update( $update_id );
		}

		return $result;
	}

	public function get_assigned_order_ids_for_user( int $user_id ): array {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		$cache_key = "assigned_orders_{$user_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$updates_table = $this->updates_table->updates;
		$assignees_table = $this->updates_table->assignees;

		// Return orders where the user is either the active assignee OR the update
		// owner (creator) on an unresolved update. Both roles should see the
		// admin-bar counter when there's activity that needs their attention.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $updates_table and $assignees_table come from UpdatesTable, which builds them from $wpdb->prefix + a hardcoded suffix; no user input.
		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT updates.order_id
				FROM {$updates_table} AS updates
				LEFT JOIN {$assignees_table} AS assignees
					ON assignees.update_id = updates.id
					AND assignees.is_active = 1
				WHERE updates.is_resolved = 0
					AND ( assignees.assignee_user_id = %d OR updates.created_by = %d )
				ORDER BY updates.order_id DESC",
				$user_id,
				$user_id
			)
		);

		$order_ids = is_array( $results ) ? array_map( 'absint', $results ) : array();
		$this->cache_set( $cache_key, $order_ids, Variables::getAssigneeSearchCacheTtl() );

		return $order_ids;
	}

	public function get_order_update_summary( int $order_id ): array {
		$empty = array(
			'update_count' => 0,
			'unsolved_count' => 0,
			'has_customer_visible' => false,
			'assignee_name' => '',
		);

		if ( ! $order_id ) {
			return $empty;
		}

		$cache_key = "summary_{$order_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$summaries = $this->get_order_update_summaries( array( $order_id ) );
		$summary = $summaries[ $order_id ] ?? $empty;
		$this->cache_set( $cache_key, $summary, Variables::getUpdateCacheTtl() );

		return $summary;
	}

	public function get_order_update_summaries( array $order_ids ): array {
		global $wpdb;

		$order_ids = array_values( array_filter( array_map( 'absint', $order_ids ) ) );

		if ( empty( $order_ids ) ) {
			return array();
		}

		$results = array();
		$uncached = array();

		foreach ( $order_ids as $oid ) {
			$cached = $this->cache_get( "summary_{$oid}" );
			if ( false !== $cached ) {
				$results[ $oid ] = $cached;
			} else {
				$uncached[] = $oid;
			}
		}

		if ( ! empty( $uncached ) ) {
			$updates_table = $this->updates_table->updates;
			$assignees_table = $this->updates_table->assignees;
			$users_table = $wpdb->users;
			$placeholders = implode( ', ', array_fill( 0, count( $uncached ), '%d' ) );

			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT
						updates.order_id,
						COUNT( DISTINCT updates.id ) AS update_count,
						SUM( CASE WHEN updates.is_resolved = 0 THEN 1 ELSE 0 END ) AS unsolved_count,
						MAX( CASE WHEN updates.customer_visible = 1 THEN 1 ELSE 0 END ) AS has_customer_visible,
						MIN( u.display_name ) AS assignee_name
					FROM {$updates_table} AS updates
					LEFT JOIN {$assignees_table} AS a
						ON a.update_id = updates.id AND a.is_active = 1
					LEFT JOIN {$users_table} AS u ON u.ID = a.assignee_user_id
					WHERE updates.order_id IN ({$placeholders})
					GROUP BY updates.order_id",
					...$uncached
				),
				ARRAY_A
			);

			$empty = array(
				'update_count' => 0,
				'unsolved_count' => 0,
				'has_customer_visible' => false,
				'assignee_name' => '',
			);

			$fetched = array();
			foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
				$oid = (int) $row['order_id'];
				$fetched[ $oid ] = array(
					'update_count' => (int) $row['update_count'],
					'unsolved_count' => (int) $row['unsolved_count'],
					'has_customer_visible' => (bool) $row['has_customer_visible'],
					'assignee_name' => (string) ( $row['assignee_name'] ?? '' ),
				);
			}

			foreach ( $uncached as $oid ) {
				$summary = $fetched[ $oid ] ?? $empty;
				$results[ $oid ] = $summary;
				$this->cache_set( "summary_{$oid}", $summary, Variables::getUpdateCacheTtl() );
			}
		}

		return $results;
	}

	/**
	 * Unresolved updates where the user is the active assignee or the creator.
	 *
	 * @return array<int, array{id:int, order_id:int, title:string}>
	 */
	public function get_assigned_updates_for_user( int $user_id, int $limit = 10 ): array {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		$cache_key = "assigned_orders_{$user_id}";
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$updates_table   = $this->updates_table->updates;
		$assignees_table = $this->updates_table->assignees;
		$limit           = max( 1, $limit );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from UpdatesTable, built from $wpdb->prefix + hardcoded suffix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT updates.id, updates.order_id, updates.title, updates.last_updated_at
				FROM {$updates_table} AS updates
				LEFT JOIN {$assignees_table} AS assignees
					ON assignees.update_id = updates.id
					AND assignees.is_active = 1
				WHERE updates.is_resolved = 0
					AND ( assignees.assignee_user_id = %d OR updates.created_by = %d )
				ORDER BY updates.last_updated_at DESC, updates.id DESC
				LIMIT %d",
				$user_id,
				$user_id,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array_map(
			static function ( array $row ): array {
				return array(
					'id'              => (int) $row['id'],
					'order_id'        => (int) $row['order_id'],
					'title'           => (string) $row['title'],
					'last_updated_at' => (string) ( $row['last_updated_at'] ?? '' ),
				);
			},
			$rows
		);

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * Updates for the Assignee page. assignee_id = 0 lists every update (the
	 * admin view); a positive id limits to that assignee. Supports a status
	 * filter ('open' | 'solved'), a title/order search, and paging. Returns
	 * the page rows plus the total count for the pager. Not cached — it's a
	 * filtered admin listing read on demand.
	 *
	 * @param array{assignee_id?:int,status?:string,search?:string,per_page?:int,paged?:int} $args
	 * @return array{rows:array<int,array<string,mixed>>, total:int}
	 */
	public function get_assignee_page_updates( array $args ): array {
		global $wpdb;

		$assignee_id = max( 0, (int) ( $args['assignee_id'] ?? 0 ) );
		$status      = (string) ( $args['status'] ?? '' );
		$search      = trim( (string) ( $args['search'] ?? '' ) );
		$orderby     = (string) ( $args['orderby'] ?? 'newest' );
		$per_page    = max( 1, (int) ( $args['per_page'] ?? 20 ) );
		$paged       = max( 1, (int) ( $args['paged'] ?? 1 ) );
		$offset      = ( $paged - 1 ) * $per_page;

		// Whitelisted ORDER BY — fixed clauses only, never raw input.
		$order_sql = match ( $orderby ) {
			'oldest'   => 'updates.last_updated_at ASC, updates.id ASC',
			'assignee' => 'assignee.display_name ASC, updates.last_updated_at DESC',
			default    => 'updates.last_updated_at DESC, updates.id DESC',
		};

		// Short cache — the listing is read on demand and changes as updates
		// flow, so a brief TTL trims repeat queries without lingering stale.
		$cache_key = 'assignee_page_' . md5( $assignee_id . '|' . $status . '|' . $search . '|' . $orderby . '|' . $per_page . '|' . $paged );
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$updates   = $this->updates_table->updates;
		$assignees = $this->updates_table->assignees;
		$users     = $wpdb->users;

		$where  = array( '1 = 1' );
		$params = array();

		if ( $assignee_id > 0 ) {
			$where[]  = 'a.assignee_user_id = %d';
			$params[] = $assignee_id;
		}
		if ( 'open' === $status ) {
			$where[] = 'updates.is_resolved = 0';
		} elseif ( 'solved' === $status ) {
			$where[] = 'updates.is_resolved = 1';
		}
		if ( '' !== $search ) {
			$where[]  = '( updates.title LIKE %s OR updates.order_id = %d )';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
			$params[] = (int) $search;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from UpdatesTable; the WHERE is fixed fragments with %d/%s placeholders bound from $params.
		$count_sql = "SELECT COUNT( DISTINCT updates.id )
			FROM {$updates} AS updates
			LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
			WHERE {$where_sql}";
		$total = (int) ( $params
			? $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			: $wpdb->get_var( $count_sql ) );

		$list_sql = "SELECT updates.id, updates.order_id, updates.title, updates.is_resolved,
				updates.status, updates.color, updates.created_by, updates.created_at, updates.last_updated_at,
				creator.display_name AS created_by_name,
				a.assignee_user_id, assignee.display_name AS assignee_name
			FROM {$updates} AS updates
			LEFT JOIN {$users} AS creator ON creator.ID = updates.created_by
			LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
			LEFT JOIN {$users} AS assignee ON assignee.ID = a.assignee_user_id
			WHERE {$where_sql}
			ORDER BY {$order_sql}
			LIMIT %d OFFSET %d";
		$rows = $wpdb->get_results( $wpdb->prepare( $list_sql, array_merge( $params, array( $per_page, $offset ) ) ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$result = array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);

		$this->cache_set( $cache_key, $result, 30 );

		return $result;
	}

	/**
	 * Latest real customer-thread message (not a status/system row) for each
	 * update, used by the Assignee page SLA badge to tell who spoke last and
	 * how long ago. One indexed query for the whole visible page.
	 *
	 * @param int[] $update_ids
	 * @return array<int, array{created_at:string, created_by:int}>
	 */
	public function get_latest_customer_messages( array $update_ids ): array {
		global $wpdb;

		$update_ids = array_values( array_unique( array_filter( array_map( 'absint', $update_ids ) ) ) );
		if ( empty( $update_ids ) ) {
			return array();
		}

		// Short cache keyed by the id set — the row data (who/when) is stable;
		// the SLA "waiting" age recomputes live in PHP from these timestamps.
		sort( $update_ids );
		$cache_key = 'latest_cust_msgs_' . md5( implode( ',', $update_ids ) );
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$cn           = $this->updates_table->customer_notes;
		$placeholders = implode( ', ', array_fill( 0, count( $update_ids ), '%d' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from UpdatesTable; ids bound via %d placeholders.
		$sql = "SELECT cn.update_id, cn.created_by, cn.created_at
			FROM {$cn} AS cn
			INNER JOIN (
				SELECT update_id, MAX( id ) AS max_id
				FROM {$cn}
				WHERE update_id IN ( {$placeholders} )
					AND kind NOT IN ( 'title_change', 'status_change', 'assignee_change', 'reopen', 'rating' )
				GROUP BY update_id
			) AS latest ON latest.max_id = cn.id";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $update_ids ), ARRAY_A );
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row['update_id'] ] = array(
				'created_at' => (string) ( $row['created_at'] ?? '' ),
				'created_by' => (int) ( $row['created_by'] ?? 0 ),
			);
		}

		$this->cache_set( $cache_key, $out, 30 );

		return $out;
	}

	/**
	 * Open (unresolved) update ids for the assignee menu badge. assignee_id = 0
	 * returns every open update (manager scope). Capped so the badge stays a
	 * lightweight count even on busy stores.
	 *
	 * @return int[]
	 */
	public function get_open_update_ids_for_assignee( int $assignee_id, int $limit = 300 ): array {
		global $wpdb;

		$updates   = $this->updates_table->updates;
		$assignees = $this->updates_table->assignees;
		$limit     = max( 1, $limit );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from UpdatesTable; values bound via placeholders.
		if ( $assignee_id > 0 ) {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT DISTINCT updates.id
					FROM {$updates} AS updates
					LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
					WHERE updates.is_resolved = 0 AND a.assignee_user_id = %d
					ORDER BY updates.id DESC
					LIMIT %d",
					$assignee_id,
					$limit
				)
			);
		} else {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT updates.id FROM {$updates} AS updates
					WHERE updates.is_resolved = 0
					ORDER BY updates.id DESC
					LIMIT %d",
					$limit
				)
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Total and resolved update counts for the assignee summary cards.
	 * assignee_id = 0 counts every update (manager scope). One query.
	 *
	 * @return array{total:int, resolved:int}
	 */
	public function get_assignee_counts( int $assignee_id ): array {
		global $wpdb;

		$updates   = $this->updates_table->updates;
		$assignees = $this->updates_table->assignees;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from UpdatesTable; id bound via placeholder.
		if ( $assignee_id > 0 ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT COUNT( DISTINCT updates.id ) AS total,
						COUNT( DISTINCT CASE WHEN updates.is_resolved = 1 THEN updates.id END ) AS resolved
					FROM {$updates} AS updates
					LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
					WHERE a.assignee_user_id = %d",
					$assignee_id
				),
				ARRAY_A
			);
		} else {
			$row = $wpdb->get_row(
				"SELECT COUNT(*) AS total, SUM( is_resolved ) AS resolved FROM {$updates}",
				ARRAY_A
			);
		}
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array(
			'total'    => (int) ( $row['total'] ?? 0 ),
			'resolved' => (int) ( $row['resolved'] ?? 0 ),
		);
	}

	/**
	 * Recent internal notes that mention the user, joined with their parent update.
	 *
	 * @return array<int, array{note_id:int, update_id:int, order_id:int, title:string, snippet:string, created_at:string, created_by_name:string}>
	 */
	public function get_mentions_for_user( int $user_id, int $limit = 10 ): array {
		global $wpdb;

		if ( ! $user_id ) {
			return array();
		}

		$cache_key = "user_mentions_{$user_id}";
		$cached    = $this->cache_get( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$notes_table   = $this->updates_table->notes;
		$updates_table = $this->updates_table->updates;
		$limit         = max( 1, $limit );

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from UpdatesTable, built from $wpdb->prefix + hardcoded suffix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT notes.id AS note_id, notes.update_id, notes.note, notes.created_at, notes.created_by_name,
					updates.order_id, updates.title
				FROM {$notes_table} AS notes
				INNER JOIN {$updates_table} AS updates ON updates.id = notes.update_id
				WHERE FIND_IN_SET( %d, notes.mentioned_user_ids )
					AND notes.created_by != %d
					AND updates.is_resolved = 0
				ORDER BY notes.created_at DESC, notes.id DESC
				LIMIT %d",
				$user_id,
				$user_id,
				$limit
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$result = array_map(
			static function ( array $row ): array {
				return array(
					'note_id'         => (int) $row['note_id'],
					'update_id'       => (int) $row['update_id'],
					'order_id'        => (int) $row['order_id'],
					'title'           => (string) $row['title'],
					'snippet'         => (string) $row['note'],
					'created_at'      => (string) $row['created_at'],
					'created_by_name' => (string) $row['created_by_name'],
				);
			},
			$rows
		);

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	public function get_users_with_active_assignments(): array {
		global $wpdb;

		$cache_key = 'users_with_assignments';
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$assignees_table = $this->updates_table->assignees;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- $assignees_table from UpdatesTable, built from $wpdb->prefix + hardcoded suffix.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DISTINCT u.ID AS id, u.display_name
				FROM {$assignees_table} AS a
				INNER JOIN {$wpdb->users} AS u ON u.ID = a.assignee_user_id
				WHERE a.is_active = %d
				ORDER BY u.display_name ASC",
				1
			),
			ARRAY_A
		);

		$rows = is_array( $rows ) ? $rows : array();
		$this->cache_set( $cache_key, $rows, Variables::getAssigneeSearchCacheTtl() );

		return $rows;
	}

	public function get_order_ids_with_unsolved_updates(): array {
		global $wpdb;

		$cache_key = 'unsolved_order_ids';
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT order_id FROM {$this->updates_table->updates} WHERE is_resolved = %d ORDER BY order_id DESC",
				0
			)
		);

		$order_ids = is_array( $results ) ? array_map( 'absint', $results ) : array();
		$this->cache_set( $cache_key, $order_ids, Variables::getAssigneeSearchCacheTtl() );

		return $order_ids;
	}

	public function get_order_updates( int $order_id, ?int $limit = null, int $offset = 0 ): array {
		global $wpdb;

		if ( ! $order_id ) {
			return array();
		}

		$limit = max( 1, $limit ?? Variables::getUpdatesPageSize() );
		$offset = max( 0, $offset );
		$version = $this->get_order_updates_cache_version( $order_id );
		$cache_key = "order_updates_{$order_id}_v{$version}_{$limit}_{$offset}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$updates = $this->updates_table->updates;
		$assignees = $this->updates_table->assignees;
		$cn = $this->updates_table->customer_notes;
		$users = $wpdb->users;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT updates.id, updates.title,
					updates.customer_visible, updates.status, updates.color, updates.created_by, updates.solved_by,
					updates.is_resolved, updates.solved_at, updates.created_at,
					updates.assignee_since_note_id, updates.previous_assignee_name,
					(SELECT MAX(cn.notified_at) FROM {$cn} cn WHERE cn.update_id = updates.id AND cn.notified_at IS NOT NULL) AS notified_customer_at,
					creator.display_name AS created_by_name, solver.display_name AS solved_by_name,
					a.assignee_user_id, a.assigned_at, assignee.display_name AS assignee_name
				FROM {$updates} AS updates
				LEFT JOIN {$users} AS creator ON creator.ID = updates.created_by
				LEFT JOIN {$users} AS solver ON solver.ID = updates.solved_by
				LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
				LEFT JOIN {$users} AS assignee ON assignee.ID = a.assignee_user_id
				WHERE updates.order_id = %d
				ORDER BY updates.created_at DESC, updates.id DESC
				LIMIT %d OFFSET %d",
				$order_id,
				$limit,
				$offset
			),
			ARRAY_A
		);

		$rows = is_array( $results ) ? $results : array();
		$this->cache_set( $cache_key, $rows, Variables::getUpdateCacheTtl() );

		return $rows;
	}

	public function get_update( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "update_{$update_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$updates = $this->updates_table->updates;
		$assignees = $this->updates_table->assignees;
		$cn = $this->updates_table->customer_notes;
		$users = $wpdb->users;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT updates.id, updates.order_id, updates.title,
					updates.customer_visible, updates.status, updates.color, updates.created_by, updates.solved_by,
					updates.is_resolved, updates.solved_at, updates.created_at,
					(SELECT MAX(cn.notified_at) FROM {$cn} cn WHERE cn.update_id = updates.id AND cn.notified_at IS NOT NULL) AS notified_customer_at,
					creator.display_name AS created_by_name, solver.display_name AS solved_by_name,
					a.assignee_user_id, a.assigned_at, assignee.display_name AS assignee_name
				FROM {$updates} AS updates
				LEFT JOIN {$users} AS creator ON creator.ID = updates.created_by
				LEFT JOIN {$users} AS solver ON solver.ID = updates.solved_by
				LEFT JOIN {$assignees} AS a ON a.update_id = updates.id AND a.is_active = 1
				LEFT JOIN {$users} AS assignee ON assignee.ID = a.assignee_user_id
				WHERE updates.id = %d
				LIMIT 1",
				$update_id
			),
			ARRAY_A
		);

		$update = is_array( $result ) ? $result : array();

		if ( ! empty( $update ) ) {
			$this->cache_set( $cache_key, $update, Variables::getUpdateCacheTtl() );
		}

		return $update;
	}

	public function count_order_updates( int $order_id ): int {
		global $wpdb;

		if ( ! $order_id ) {
			return 0;
		}

		$cache_key = "count_{$order_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$this->updates_table->updates} WHERE order_id = %d",
				$order_id
			)
		);

		$this->cache_set( $cache_key, $count, Variables::getUpdateCacheTtl() );

		return $count;
	}

	/**
	 * Total update rows across every order on the site. Used by the
	 * review-request notice gate to confirm the plugin has actually been
	 * used before nagging the admin for a rating. Cached for an hour
	 * via the notice's own wp_cache_* layer.
	 */
	public function count_all_updates(): int {
		global $wpdb;

		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only, not user input.
			"SELECT COUNT(id) FROM {$this->updates_table->updates}"
		);
	}

	public function get_update_action_history( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "history_{$update_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$updates_table = $this->updates_table->updates;
		$assignees_table = $this->updates_table->assignees;
		$cn_table = $this->updates_table->customer_notes;
		$users = $wpdb->users;

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from UpdatesTable / $wpdb->users; no user input in identifiers.
		$update = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT u.id, u.order_id, u.created_by, u.created_at, u.solved_at,
					(SELECT MAX(cn.notified_at) FROM {$cn_table} cn WHERE cn.update_id = u.id AND cn.notified_at IS NOT NULL) AS notified_customer_at,
					u.is_resolved, u.last_updated_by, u.last_updated_at,
					creator.display_name AS created_by_name,
					solver.display_name AS solved_by_name,
					reopener.display_name AS reopened_by_name
				FROM {$updates_table} AS u
				LEFT JOIN {$users} AS creator ON creator.ID = u.created_by
				LEFT JOIN {$users} AS solver ON solver.ID = u.solved_by
				LEFT JOIN {$users} AS reopener ON reopener.ID = u.last_updated_by
				WHERE u.id = %d
				LIMIT 1",
				$update_id
			),
			ARRAY_A
		);

		if ( empty( $update ) ) {
			return array();
		}

		$events = array();

		// Customer-opened updates (guest or logged-in customer) carry no
		// staff display name — label them as "Customer" so the tracking
		// log doesn't render "Created by Unknown" for the most common
		// origin of all.
		$creator_label = \OrderUpdatesForWoo\Helpers\UpdateAuthorHelper::is_customer_initiated_update( $update )
			? __( 'Customer', 'order-updates-for-woo' )
			: (string) ( $update['created_by_name'] ?? '' );

		$events[] = array(
			'type' => 'created',
			'timestamp' => $update['created_at'] ?? '',
			'performed_by_name' => $creator_label,
		);

		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from UpdatesTable / $wpdb->users; no user input in identifiers.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT a.assigned_at, a.unassigned_at, a.notified_at,
					assignee.display_name AS assignee_name,
					assigner.display_name AS assigned_by_name,
					unassigner.display_name AS unassigned_by_name
				FROM {$assignees_table} AS a
				LEFT JOIN {$users} AS assignee ON assignee.ID = a.assignee_user_id
				LEFT JOIN {$users} AS assigner ON assigner.ID = a.assigned_by
				LEFT JOIN {$users} AS unassigner ON unassigner.ID = a.unassigned_by
				WHERE a.update_id = %d
				ORDER BY a.assigned_at ASC, a.id ASC",
				$update_id
			),
			ARRAY_A
		);

		foreach ( ( is_array( $rows ) ? $rows : array() ) as $row ) {
			$events[] = array(
				'type' => 'assigned',
				'timestamp' => $row['assigned_at'] ?? '',
				'performed_by_name' => $row['assigned_by_name'] ?? '',
				'assignee_name' => $row['assignee_name'] ?? '',
			);

			if ( ! empty( $row['notified_at'] ) ) {
				$events[] = array(
					'type' => 'notified_assignee',
					'timestamp' => $row['notified_at'],
					'assignee_name' => $row['assignee_name'] ?? '',
				);
			}

			if ( ! empty( $row['unassigned_at'] ) ) {
				$events[] = array(
					'type' => 'unassigned',
					'timestamp' => $row['unassigned_at'],
					'performed_by_name' => $row['unassigned_by_name'] ?? '',
					'assignee_name' => $row['assignee_name'] ?? '',
				);
			}
		}

		if ( ! empty( $update['solved_at'] ) ) {
			$events[] = array(
				'type' => 'solved',
				'timestamp' => $update['solved_at'],
				'performed_by_name' => $update['solved_by_name'] ?? '',
			);
		}

		// Reopen events are explicit log_lifecycle_event rows (kind='reopen')
		// — see the customer_notes JOIN below. The previous current-state-based
		// reopen detection has been removed because it only captured "currently
		// reopened" updates and lost any solve→reopen→solve cycle.

		if ( ! empty( $update['notified_customer_at'] ) ) {
			$events[] = array(
				'type' => 'notified_customer',
				'timestamp' => $update['notified_customer_at'],
			);
		}

		// Status, title, reopen, and rating events live as system rows on
		// customer_notes. Include them so the tracking log shows every
		// lifecycle event in one list.
		// phpcs:ignore PluginCheck.Security.DirectDB.UnescapedDBParameter -- table names from UpdatesTable; no user input in identifiers.
		$system_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT kind, note, created_by_name, created_at
				FROM {$cn_table}
				WHERE update_id = %d AND kind IN ( 'status_change', 'title_change', 'reopen', 'rating' )
				ORDER BY created_at ASC, id ASC",
				$update_id
			),
			ARRAY_A
		);

		$kind_to_type = array(
			'title_change'  => 'title_changed',
			'status_change' => 'status_changed',
			'reopen'        => 'reopened',
			'rating'        => 'rated',
		);

		foreach ( ( is_array( $system_rows ) ? $system_rows : array() ) as $row ) {
			$kind = (string) ( $row['kind'] ?? '' );
			$events[] = array(
				'type'              => $kind_to_type[ $kind ] ?? 'status_changed',
				'timestamp'         => (string) ( $row['created_at'] ?? '' ),
				'performed_by_name' => (string) ( $row['created_by_name'] ?? '' ),
				'message'           => (string) ( $row['note'] ?? '' ),
			);
		}

		usort( $events, static fn( array $a, array $b ) => strcmp( $a['timestamp'] ?? '', $b['timestamp'] ?? '' ) );

		$this->cache_set( $cache_key, $events, Variables::getUpdateCacheTtl() );

		return $events;
	}

	public function create_update_note( int $update_id, string $note, int $created_by, string $created_by_name, string $created_at, array $mentioned_user_ids = array() ): int {
		global $wpdb;

		if ( ! $update_id || '' === $note ) {
			return 0;
		}

		// Convert text emoticons ":)" → 🙂 once at save so every downstream
		// surface (admin card, customer portal, emails) reads real glyphs
		// from the DB.
		$note = \OrderUpdatesForWoo\Helpers\EmoticonConverter::convert( $note );

		$wpdb->insert(
			$this->updates_table->notes,
			array(
				'update_id' => $update_id,
				'note' => $note,
				'mentioned_user_ids' => $this->encode_mention_ids( $mentioned_user_ids ),
				'created_by' => $created_by,
				'created_by_name' => $created_by_name,
				'created_at' => $created_at,
			),
			array( '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$note_id = (int) $wpdb->insert_id;

		if ( $note_id ) {
			$this->invalidate_for_update( $update_id );
		}

		return $note_id;
	}

	public function get_update_notes( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "notes_{$update_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
				FROM {$this->updates_table->notes}
				WHERE update_id = %d
				ORDER BY created_at ASC, id ASC",
				$update_id
			),
			ARRAY_A
		);

		$rows = is_array( $results ) ? $results : array();

		foreach ( $rows as &$row ) {
			$row['mentioned_user_ids'] = $this->decode_mention_ids( (string) ( $row['mentioned_user_ids'] ?? '' ) );
		}
		unset( $row );

		$this->cache_set( $cache_key, $rows, Variables::getUpdateCacheTtl() );

		return $rows;
	}

	/**
	 * Distinct staff user ids that have authored a note on this update —
	 * either an internal staff note or a customer-visible note submitted from
	 * the admin side. Used to broadcast admin-bar notifications to everyone
	 * who's already part of the conversation, not just the current assignee.
	 *
	 * Customer-authored rows store created_by = 0, so they're filtered out
	 * naturally by the > 0 guard.
	 *
	 * @return int[]
	 */
	public function get_staff_participant_user_ids( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$internal = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT created_by
				FROM {$this->updates_table->notes}
				WHERE update_id = %d AND created_by > 0",
				$update_id
			)
		);

		$customer = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT created_by
				FROM {$this->updates_table->customer_notes}
				WHERE update_id = %d AND created_by > 0",
				$update_id
			)
		);

		$ids = array_map( 'absint', array_merge( (array) $internal, (array) $customer ) );

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Paged fetch of internal staff notes — newest-first, with `has_more`
	 * so the admin meta box can wire a "Load previous" button. Mirrors
	 * `get_customer_notes_paged()` exactly so both threads share the same
	 * pagination shape.
	 *
	 * Pass `$before_id = 0` for the first page (most recent N). On
	 * subsequent calls pass the oldest visible note id so the next page
	 * starts strictly older than that.
	 *
	 * @return array{notes:array<int, array<string, mixed>>, has_more:bool}
	 */
	public function get_update_notes_paged( int $update_id, int $limit, int $before_id = 0 ): array {
		global $wpdb;

		if ( ! $update_id || $limit < 1 ) {
			return array( 'notes' => array(), 'has_more' => false );
		}

		$version   = $this->get_update_notes_cache_version( $update_id );
		$cache_key = "update_notes_paged_{$update_id}_v{$version}_{$limit}_{$before_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$fetch = $limit + 1;

		if ( $before_id > 0 ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->notes}
					WHERE update_id = %d AND id < %d
					ORDER BY created_at DESC, id DESC
					LIMIT %d",
					$update_id, $before_id, $fetch
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->notes}
					WHERE update_id = %d
					ORDER BY created_at DESC, id DESC
					LIMIT %d",
					$update_id, $fetch
				),
				ARRAY_A
			);
		}

		$rows     = is_array( $results ) ? $results : array();
		$has_more = count( $rows ) > $limit;

		if ( $has_more ) {
			array_pop( $rows );
		}

		foreach ( $rows as &$row ) {
			$row['mentioned_user_ids'] = $this->decode_mention_ids( (string) ( $row['mentioned_user_ids'] ?? '' ) );
		}
		unset( $row );

		$result = array( 'notes' => array_reverse( $rows ), 'has_more' => $has_more );

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * Window of internal notes centred on $note_id — up to $span older, the
	 * note itself, then up to $span newer, oldest-first. Lets a deep link jump
	 * straight to a note without paging the whole thread. `has_more` flags
	 * older notes above the window; `has_newer` flags notes below it (so the
	 * UI can offer "jump to latest"). Empty when the note is missing or not on
	 * this update.
	 *
	 * @return array{notes:array<int, array<string, mixed>>, has_more:bool, has_newer:bool}
	 */
	public function get_update_notes_around( int $update_id, int $note_id, int $span = 8 ): array {
		global $wpdb;

		$empty = array( 'notes' => array(), 'has_more' => false, 'has_newer' => false );

		if ( ! $update_id || ! $note_id || $span < 1 ) {
			return $empty;
		}

		$version   = $this->get_update_notes_cache_version( $update_id );
		$cache_key = "update_notes_around_{$update_id}_v{$version}_{$note_id}_{$span}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$columns = 'id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at';

		$target = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->notes} WHERE id = %d AND update_id = %d LIMIT 1",
				$note_id, $update_id
			),
			ARRAY_A
		);

		if ( ! is_array( $target ) ) {
			$this->cache_set( $cache_key, $empty, Variables::getUpdateCacheTtl() );
			return $empty;
		}

		$fetch = $span + 1;

		$older = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->notes}
				WHERE update_id = %d AND id < %d
				ORDER BY created_at DESC, id DESC
				LIMIT %d",
				$update_id, $note_id, $fetch
			),
			ARRAY_A
		);
		$older    = is_array( $older ) ? $older : array();
		$has_more = count( $older ) > $span;
		if ( $has_more ) {
			array_pop( $older );
		}
		$older = array_reverse( $older );

		$newer = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->notes}
				WHERE update_id = %d AND id > %d
				ORDER BY created_at ASC, id ASC
				LIMIT %d",
				$update_id, $note_id, $fetch
			),
			ARRAY_A
		);
		$newer     = is_array( $newer ) ? $newer : array();
		$has_newer = count( $newer ) > $span;
		if ( $has_newer ) {
			array_pop( $newer );
		}

		$rows = array_merge( $older, array( $target ), $newer );

		foreach ( $rows as &$row ) {
			$row['mentioned_user_ids'] = $this->decode_mention_ids( (string) ( $row['mentioned_user_ids'] ?? '' ) );
		}
		unset( $row );

		$result = array( 'notes' => $rows, 'has_more' => $has_more, 'has_newer' => $has_newer );

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	public function get_update_note_by_id( int $note_id ): array {
		global $wpdb;

		if ( ! $note_id ) {
			return array();
		}

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
				FROM {$this->updates_table->notes}
				WHERE id = %d
				LIMIT 1",
				$note_id
			),
			ARRAY_A
		);

		if ( ! is_array( $result ) ) {
			return array();
		}

		$result['mentioned_user_ids'] = $this->decode_mention_ids( (string) ( $result['mentioned_user_ids'] ?? '' ) );

		return $result;
	}

	public function update_update_note( int $note_id, string $note, array $mentioned_user_ids, string $edited_at ): bool {
		global $wpdb;

		if ( ! $note_id || '' === $note || '' === $edited_at ) {
			return false;
		}

		$note    = \OrderUpdatesForWoo\Helpers\EmoticonConverter::convert( $note );
		$current = $this->get_update_note_by_id( $note_id );

		$result = false !== $wpdb->update(
			$this->updates_table->notes,
			array(
				'note'               => $note,
				'mentioned_user_ids' => $this->encode_mention_ids( $mentioned_user_ids ),
				'edited_at'          => $edited_at,
			),
			array( 'id' => $note_id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$update_id = absint( $current['update_id'] ?? 0 );

			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	public function delete_update_note( int $note_id ): bool {
		global $wpdb;

		if ( ! $note_id ) {
			return false;
		}

		$note = $this->get_update_note_by_id( $note_id );

		$result = false !== $wpdb->delete(
			$this->updates_table->notes,
			array( 'id' => $note_id ),
			array( '%d' )
		);

		if ( $result ) {
			$update_id = absint( $note['update_id'] ?? 0 );

			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	private function encode_mention_ids( array $ids ): string {
		$clean = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

		if ( empty( $clean ) ) {
			return '';
		}

		return implode( ',', $clean );
	}

	private function decode_mention_ids( string $raw ): array {
		if ( '' === $raw ) {
			return array();
		}

		$parts = array_map( 'absint', explode( ',', $raw ) );

		return array_values( array_unique( array_filter( $parts ) ) );
	}

	public function create_customer_note( int $update_id, string $note, int $created_by, string $created_by_name, string $created_at ): int {
		global $wpdb;

		if ( ! $update_id || '' === $note ) {
			return 0;
		}

		// Same emoticon conversion as create_update_note — single source-of-
		// truth at the lowest write path so customer-portal submissions
		// (which bypass UpdateNoteService and hit this method directly via
		// SubmitCustomerUpdateEndpoint) also get converted.
		$note = \OrderUpdatesForWoo\Helpers\EmoticonConverter::convert( $note );

		$wpdb->insert(
			$this->updates_table->customer_notes,
			array(
				'update_id' => $update_id,
				'note' => $note,
				'created_by' => $created_by,
				'created_by_name' => $created_by_name,
				'created_at' => $created_at,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);

		$note_id = (int) $wpdb->insert_id;

		if ( $note_id ) {
			// First customer-facing note flips the update to visible. We do
			// not gate on "is this the first note?" — UPDATE is cheap and
			// idempotent, and the column is the single source of truth the
			// customer portal queries against.
			$wpdb->update(
				$this->updates_table->updates,
				array( 'customer_visible' => 1 ),
				array( 'id' => $update_id ),
				array( '%d' ),
				array( '%d' )
			);
			$this->invalidate_for_update( $update_id );
		}

		return $note_id;
	}

	public function get_customer_notes( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "customer_notes_{$update_id}";
		$cached = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
				FROM {$this->updates_table->customer_notes}
				WHERE update_id = %d AND kind NOT IN ( 'title_change', 'reopen', 'rating' )
				ORDER BY created_at ASC, id ASC",
				$update_id
			),
			ARRAY_A
		);

		$rows = is_array( $results ) ? $results : array();
		$this->cache_set( $cache_key, $rows, Variables::getUpdateCacheTtl() );

		return $rows;
	}

	/**
	 * Return the most recent $limit customer notes for an update, optionally
	 * before $before_id (cursor-based). Fetches $limit + 1 rows to detect
	 * whether older notes exist without a separate COUNT query.
	 *
	 * @return array{ notes: array, has_more: bool }
	 */
	public function get_customer_notes_paged( int $update_id, int $limit, int $before_id = 0 ): array {
		global $wpdb;

		if ( ! $update_id || $limit < 1 ) {
			return array( 'notes' => array(), 'has_more' => false );
		}

		$version   = $this->get_customer_notes_cache_version( $update_id );
		$cache_key = "customer_notes_paged_{$update_id}_v{$version}_{$limit}_{$before_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$fetch = $limit + 1;

		if ( $before_id > 0 ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->customer_notes}
					WHERE update_id = %d AND id < %d AND kind NOT IN ( 'title_change', 'reopen', 'rating' )
					ORDER BY created_at DESC, id DESC
					LIMIT %d",
					$update_id, $before_id, $fetch
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->customer_notes}
					WHERE update_id = %d AND kind NOT IN ( 'title_change', 'reopen', 'rating' )
					ORDER BY created_at DESC, id DESC
					LIMIT %d",
					$update_id, $fetch
				),
				ARRAY_A
			);
		}

		$rows     = is_array( $results ) ? $results : array();
		$has_more = count( $rows ) > $limit;

		if ( $has_more ) {
			array_pop( $rows );
		}

		$result = array( 'notes' => array_reverse( $rows ), 'has_more' => $has_more );

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * Window of customer notes centred on $note_id — mirrors
	 * {@see get_update_notes_around()} for the customer thread (same status-row
	 * exclusions as the paged fetch). `has_more` flags older notes above the
	 * window; `has_newer` flags notes below it.
	 *
	 * @return array{notes:array<int, array<string, mixed>>, has_more:bool, has_newer:bool}
	 */
	public function get_customer_notes_around( int $update_id, int $note_id, int $span = 8 ): array {
		global $wpdb;

		$empty = array( 'notes' => array(), 'has_more' => false, 'has_newer' => false );

		if ( ! $update_id || ! $note_id || $span < 1 ) {
			return $empty;
		}

		$version   = $this->get_customer_notes_cache_version( $update_id );
		$cache_key = "customer_notes_around_{$update_id}_v{$version}_{$note_id}_{$span}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$columns = 'id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at';
		$exclude = "kind NOT IN ( 'title_change', 'reopen', 'rating' )";

		$target = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->customer_notes} WHERE id = %d AND update_id = %d AND {$exclude} LIMIT 1",
				$note_id, $update_id
			),
			ARRAY_A
		);

		if ( ! is_array( $target ) ) {
			$this->cache_set( $cache_key, $empty, Variables::getUpdateCacheTtl() );
			return $empty;
		}

		$fetch = $span + 1;

		$older = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->customer_notes}
				WHERE update_id = %d AND id < %d AND {$exclude}
				ORDER BY created_at DESC, id DESC
				LIMIT %d",
				$update_id, $note_id, $fetch
			),
			ARRAY_A
		);
		$older    = is_array( $older ) ? $older : array();
		$has_more = count( $older ) > $span;
		if ( $has_more ) {
			array_pop( $older );
		}
		$older = array_reverse( $older );

		$newer = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT {$columns} FROM {$this->updates_table->customer_notes}
				WHERE update_id = %d AND id > %d AND {$exclude}
				ORDER BY created_at ASC, id ASC
				LIMIT %d",
				$update_id, $note_id, $fetch
			),
			ARRAY_A
		);
		$newer     = is_array( $newer ) ? $newer : array();
		$has_newer = count( $newer ) > $span;
		if ( $has_newer ) {
			array_pop( $newer );
		}

		$rows   = array_merge( $older, array( $target ), $newer );
		$result = array( 'notes' => $rows, 'has_more' => $has_more, 'has_newer' => $has_newer );

		$this->cache_set( $cache_key, $result, Variables::getUpdateCacheTtl() );

		return $result;
	}

	/**
	 * Return all customer-thread notes for $order_id that are either:
	 *   - newer than $since_note_id (new messages), or
	 *   - edited after $since_time (so the poller can update existing DOM nodes).
	 *
	 * Used by the 30-second poll endpoint on the customer page.
	 */
	public function get_update_notes_since_id( int $update_id, int $since_note_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "internal_notes_since_{$update_id}_{$since_note_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		if ( 0 === $since_note_id ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->notes}
					WHERE update_id = %d AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 HOUR )
					ORDER BY id ASC",
					$update_id
				),
				ARRAY_A
			);
		} else {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, mentioned_user_ids, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->notes}
					WHERE update_id = %d AND id > %d
					ORDER BY id ASC",
					$update_id,
					$since_note_id
				),
				ARRAY_A
			);
		}

		if ( ! is_array( $results ) ) {
			return array();
		}

		foreach ( $results as &$row ) {
			$row['mentioned_user_ids'] = $this->decode_mention_ids( (string) ( $row['mentioned_user_ids'] ?? '' ) );
		}
		unset( $row );

		$this->cache_set( $cache_key, $results, 10 );

		return $results;
	}

	public function get_customer_notes_since_id( int $update_id, int $since_note_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "customer_notes_since_{$update_id}_{$since_note_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		// When since_note_id is 0 the notes tab hasn't been opened yet.
		// Use a 1-hour window so we don't pull all historical notes on
		// every heartbeat tick before the tab is first opened.
		if ( 0 === $since_note_id ) {
			$results = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
					FROM {$this->updates_table->customer_notes}
					WHERE update_id = %d AND kind NOT IN ( 'title_change', 'reopen', 'rating' ) AND created_at >= DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 HOUR )
					ORDER BY id ASC",
					$update_id
				),
				ARRAY_A
			);

			$rows = is_array( $results ) ? $results : array();
			$this->cache_set( $cache_key, $rows, 10 );

			return $rows;
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
				FROM {$this->updates_table->customer_notes}
				WHERE update_id = %d AND id > %d AND kind NOT IN ( 'title_change', 'reopen', 'rating' )
				ORDER BY id ASC",
				$update_id,
				$since_note_id
			),
			ARRAY_A
		);

		$rows = is_array( $results ) ? $results : array();
		$this->cache_set( $cache_key, $rows, 10 );

		return $rows;
	}

	public function get_customer_thread_changes( int $order_id, int $since_note_id, string $since_time ): array {
		global $wpdb;

		if ( ! $order_id ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cn.id, cn.update_id, cn.note, cn.kind, cn.queued_at, cn.notified_at,
				        cn.created_by, cn.created_by_name, cn.created_at, cn.edited_at
				FROM {$this->updates_table->customer_notes} cn
				INNER JOIN {$this->updates_table->updates} u ON u.id = cn.update_id
				WHERE u.order_id = %d
				  AND u.customer_visible = 1
				  AND (
				        cn.id > %d
				        OR ( cn.edited_at IS NOT NULL AND cn.edited_at > %s )
				      )
				ORDER BY cn.id ASC",
				$order_id,
				$since_note_id,
				$since_time
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	/**
	 * Return the highest update id for an order. Cached for 30 seconds via the
	 * WP object cache (persistent if Redis/Memcached is active, per-process
	 * otherwise) to reduce DB hits from the customer page's polling loop.
	 */
	public function get_latest_update_id_for_order( int $order_id ): int {
		global $wpdb;

		if ( ! $order_id ) {
			return 0;
		}

		$cache_key = "latest_update_id_{$order_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$this->updates_table->updates}
				WHERE order_id = %d AND customer_visible = 1",
				$order_id
			)
		);

		$value = $result ? (int) $result : 0;
		$this->cache_set( $cache_key, $value, 30 );

		return $value;
	}

	/**
	 * Highest customer-note id in the given update's thread. Used by
	 * NoteActionPolicy to enforce the latest-only edit/delete rule: any note
	 * whose id is below this value is locked because at least one newer note
	 * exists. Cached briefly (5s) so a typical presenter loop over a batch
	 * of notes only pays one DB round-trip.
	 */
	public function get_latest_customer_note_id( int $update_id ): int {
		global $wpdb;

		if ( ! $update_id ) {
			return 0;
		}

		$cache_key = "latest_customer_note_id_{$update_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$this->updates_table->customer_notes} WHERE update_id = %d",
				$update_id
			)
		);

		$value = $result ? (int) $result : 0;
		$this->cache_set( $cache_key, $value, 5 );

		return $value;
	}

	/**
	 * Same as get_latest_customer_note_id() but for the internal-note thread.
	 */
	public function get_latest_internal_note_id( int $update_id ): int {
		global $wpdb;

		if ( ! $update_id ) {
			return 0;
		}

		$cache_key = "latest_internal_note_id_{$update_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return (int) $cached;
		}

		$result = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(id) FROM {$this->updates_table->notes} WHERE update_id = %d",
				$update_id
			)
		);

		$value = $result ? (int) $result : 0;
		$this->cache_set( $cache_key, $value, 5 );

		return $value;
	}

	public function get_customer_note_by_id( int $note_id ): array {
		global $wpdb;

		if ( ! $note_id ) {
			return array();
		}

		$cache_key = "customer_note_{$note_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, update_id, note, kind, queued_at, notified_at, created_by, created_by_name, created_at, edited_at
				FROM {$this->updates_table->customer_notes}
				WHERE id = %d
				LIMIT 1",
				$note_id
			),
			ARRAY_A
		);

		$note = is_array( $result ) ? $result : array();
		$this->cache_set( $cache_key, $note, Variables::getUpdateCacheTtl() );

		return $note;
	}

	public function update_customer_note( int $note_id, string $note, string $edited_at ): bool {
		global $wpdb;

		if ( ! $note_id || '' === $note || '' === $edited_at ) {
			return false;
		}

		$note    = \OrderUpdatesForWoo\Helpers\EmoticonConverter::convert( $note );
		$current = $this->get_customer_note_by_id( $note_id );

		$result = false !== $wpdb->update(
			$this->updates_table->customer_notes,
			array(
				'note'      => $note,
				'edited_at' => $edited_at,
			),
			array( 'id' => $note_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "customer_note_{$note_id}" );
			$update_id = absint( $current['update_id'] ?? 0 );

			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	/**
	 * Archive a customer-note revision before the live row is overwritten.
	 * The endpoint calls this immediately before update_customer_note() so the
	 * prior text is preserved for the audit trail.
	 */
	public function archive_customer_note_revision(
		int $note_id,
		string $prior_note,
		int $editor_user_id,
		string $editor_name,
		string $edited_at
	): bool {
		global $wpdb;

		if ( ! $note_id || '' === $prior_note || '' === $edited_at ) {
			return false;
		}

		return false !== $wpdb->insert(
			$this->updates_table->customer_note_history,
			array(
				'note_id'        => $note_id,
				'prior_note'     => $prior_note,
				'edited_by'      => $editor_user_id,
				'edited_by_name' => $editor_name,
				'edited_at'      => $edited_at,
			),
			array( '%d', '%s', '%d', '%s', '%s' )
		);
	}

	public function get_customer_note_history( int $note_id ): array {
		global $wpdb;

		if ( ! $note_id ) {
			return array();
		}

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, note_id, prior_note, edited_by, edited_by_name, edited_at
				FROM {$this->updates_table->customer_note_history}
				WHERE note_id = %d
				ORDER BY edited_at ASC, id ASC",
				$note_id
			),
			ARRAY_A
		);

		return is_array( $results ) ? $results : array();
	}

	public function delete_customer_note( int $note_id ): bool {
		global $wpdb;

		if ( ! $note_id ) {
			return false;
		}

		$note = $this->get_customer_note_by_id( $note_id );

		$result = false !== $wpdb->delete(
			$this->updates_table->customer_notes,
			array( 'id' => $note_id ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "customer_note_{$note_id}" );
			$update_id = absint( $note['update_id'] ?? 0 );
			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	public function mark_customer_note_queued( int $note_id, string $queued_at ): bool {
		global $wpdb;

		if ( ! $note_id || '' === $queued_at ) {
			return false;
		}

		// Look up update_id BEFORE the write so we don't read a stale
		// post-write cache entry just to bust it.
		$note      = $this->get_customer_note_by_id( $note_id );
		$update_id = absint( $note['update_id'] ?? 0 );

		$result = false !== $wpdb->update(
			$this->updates_table->customer_notes,
			array( 'queued_at' => $queued_at ),
			array( 'id' => $note_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "customer_note_{$note_id}" );
			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	public function mark_customer_note_notified( int $note_id, string $notified_at ): bool {
		global $wpdb;

		if ( ! $note_id || '' === $notified_at ) {
			return false;
		}

		// Look up update_id BEFORE the write so we don't read a stale
		// post-write cache entry just to bust it.
		$note      = $this->get_customer_note_by_id( $note_id );
		$update_id = absint( $note['update_id'] ?? 0 );

		$result = false !== $wpdb->update(
			$this->updates_table->customer_notes,
			array( 'notified_at' => $notified_at ),
			array( 'id' => $note_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "customer_note_{$note_id}" );
			if ( $update_id ) {
				$this->invalidate_for_update( $update_id );
			}
		}

		return $result;
	}

	/**
	 * Read the single rating row for an update, if any. Each update has at most
	 * one rating row, lifecycle: created on resolve (requested_at) → email
	 * delivered (request_notified_at) → customer submits (stars/comment/created_at).
	 */
	public function get_rating_for_update( int $update_id ): array {
		global $wpdb;

		if ( ! $update_id ) {
			return array();
		}

		$cache_key = "rating_{$update_id}";
		$cached    = $this->cache_get( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, update_id, order_id, stars, comment, created_by, created_by_name,
					requested_at, request_notified_at, created_at
				FROM {$this->updates_table->ratings}
				WHERE update_id = %d
				LIMIT 1",
				$update_id
			),
			ARRAY_A
		);

		$rating = is_array( $result ) ? $result : array();
		$this->cache_set( $cache_key, $rating, Variables::getUpdateCacheTtl() );

		return $rating;
	}

	/**
	 * Seed the rating cache for a batch of update IDs in a single query.
	 * Call this before iterating over a list of updates to avoid N individual queries.
	 *
	 * @param int[] $update_ids
	 */
	public function prefetch_ratings_for_updates( array $update_ids ): void {
		global $wpdb;

		$update_ids = array_filter( array_map( 'absint', $update_ids ) );

		if ( empty( $update_ids ) ) {
			return;
		}

		// Only fetch what isn't already in cache.
		$missing = array_filter( $update_ids, function ( int $id ): bool {
			return false === $this->cache_get( "rating_{$id}" );
		} );

		if ( empty( $missing ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $missing ), '%d' ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, update_id, order_id, stars, comment, created_by, created_by_name,
					requested_at, request_notified_at, created_at
				FROM {$this->updates_table->ratings}
				WHERE update_id IN ({$placeholders})",
				...$missing
			),
			ARRAY_A
		);

		$indexed = array();

		if ( is_array( $rows ) ) {
			foreach ( $rows as $row ) {
				$indexed[ (int) $row['update_id'] ] = $row;
			}
		}

		foreach ( $missing as $id ) {
			$this->cache_set( "rating_{$id}", $indexed[ $id ] ?? array(), Variables::getUpdateCacheTtl() );
		}
	}

	/**
	 * Idempotent: creates a rating-request row for the update if none exists,
	 * stamping requested_at. Returns the rating id.
	 */
	public function create_rating_request( int $update_id, int $order_id, string $requested_at ): int {
		global $wpdb;

		if ( ! $update_id || ! $order_id || '' === $requested_at ) {
			return 0;
		}

		$existing = $this->get_rating_for_update( $update_id );

		if ( ! empty( $existing['id'] ) ) {
			return (int) $existing['id'];
		}

		$wpdb->insert(
			$this->updates_table->ratings,
			array(
				'update_id'    => $update_id,
				'order_id'     => $order_id,
				'requested_at' => $requested_at,
			),
			array( '%d', '%d', '%s' )
		);

		$id = (int) $wpdb->insert_id;

		if ( $id ) {
			$this->cache_delete( "rating_{$update_id}" );
		}

		return $id;
	}

	/**
	 * Wipe the rating-request row for an update — but only when no actual
	 * rating has been submitted yet (created_at is empty). Used by the reopen
	 * flow so a fresh resolve afterwards re-creates the request row and the
	 * "How did we do?" email fires again. We never delete a row that has a
	 * customer's submitted rating: that's the audit trail.
	 *
	 * Returns true when a row was actually deleted, false otherwise.
	 */
	public function clear_rating_request( int $update_id ): bool {
		global $wpdb;

		if ( ! $update_id ) {
			return false;
		}

		$existing = $this->get_rating_for_update( $update_id );

		if ( empty( $existing['id'] ) || ! empty( $existing['created_at'] ) ) {
			return false;
		}

		$result = $wpdb->delete(
			$this->updates_table->ratings,
			array( 'update_id' => $update_id ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "rating_{$update_id}" );
		}

		return (bool) $result;
	}

	public function mark_rating_request_notified( int $update_id, string $notified_at ): bool {
		global $wpdb;

		if ( ! $update_id || '' === $notified_at ) {
			return false;
		}

		$result = false !== $wpdb->update(
			$this->updates_table->ratings,
			array( 'request_notified_at' => $notified_at ),
			array( 'update_id' => $update_id ),
			array( '%s' ),
			array( '%d' )
		);

		if ( $result ) {
			$this->cache_delete( "rating_{$update_id}" );
		}

		return $result;
	}

	/**
	 * Persist a customer's rating response. Will not overwrite an existing
	 * submission — caller should check via {@see get_rating_for_update()}.
	 */
	public function submit_rating(
		int $update_id,
		int $order_id,
		int $stars,
		string $comment,
		int $created_by,
		string $created_by_name,
		string $created_at
	): bool {
		global $wpdb;

		if ( ! $update_id || ! $order_id || $stars < 1 || $stars > 5 ) {
			return false;
		}

		$existing = $this->get_rating_for_update( $update_id );

		if ( ! empty( $existing['created_at'] ) ) {
			return false;
		}

		if ( empty( $existing['id'] ) ) {
			$wpdb->insert(
				$this->updates_table->ratings,
				array(
					'update_id'       => $update_id,
					'order_id'        => $order_id,
					'stars'           => $stars,
					'comment'         => $comment,
					'created_by'      => $created_by,
					'created_by_name' => $created_by_name,
					'created_at'      => $created_at,
				),
				array( '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
			);

			$result = (bool) $wpdb->insert_id;
		} else {
			$result = false !== $wpdb->update(
				$this->updates_table->ratings,
				array(
					'stars'           => $stars,
					'comment'         => $comment,
					'created_by'      => $created_by,
					'created_by_name' => $created_by_name,
					'created_at'      => $created_at,
				),
				array( 'update_id' => $update_id ),
				array( '%d', '%s', '%d', '%s', '%s' ),
				array( '%d' )
			);
		}

		if ( $result ) {
			$this->invalidate_for_update( $update_id );
			do_action( 'order_updates_for_woo_update_changed', $update_id );
		}

		return $result;
	}

	private function deactivate_active_assignees( int $update_id, int $current_assignee_id, int $unassigned_by, string $unassigned_at ): bool {
		global $wpdb;

		$result = false !== $wpdb->update(
			$this->updates_table->assignees,
			array(
				'is_active' => 0,
				'unassigned_at' => $unassigned_at,
				'unassigned_by' => $unassigned_by,
				'last_updated_at' => $unassigned_at,
			),
			array(
				'update_id' => $update_id,
				'is_active' => 1,
			),
			array( '%d', '%s', '%d', '%s' ),
			array( '%d', '%d' )
		);

		if ( $result ) {
			$this->invalidate_for_update( $update_id );
			$this->cache_delete( "assigned_orders_{$current_assignee_id}" );
			$this->cache_delete( 'users_with_assignments' );
		}

		return $result;
	}

	// Analytics queries live in `Shared\Analytics\AnalyticsLookupDb`. That class
	// reads from a separate lookup table kept in sync via the
	// `order_updates_for_woo_update_changed` / `_deleted` hooks.
}
