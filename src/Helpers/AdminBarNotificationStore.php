<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Config\Constants;

// One direct meta cleanup query; table names are safe, not user input.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

/**
 * Per-user admin bar notification store backed by user meta + object cache.
 *
 * Each notification is an array:
 *   key       — unique string, e.g. "assigned_5" or "mention_12"
 *   type      — "assigned" | "mention"
 *   update_id — int
 *   order_id  — int
 *   note_id   — int (0 for assigned type)
 *   title     — display text for the notification row
 *   time      — Unix timestamp
 *   dismissed — bool
 */
final class AdminBarNotificationStore {
	private const META_KEY   = 'order_updates_for_woo_notifications';
	private const CACHE_PFX  = 'ab_notifs_';
	private const CACHE_TTL  = 30;
	private const MAX_STORED = 50;

	/** Notify $user_id that they were assigned to an update. Keyed by update — silently ignored if already stored. */
	/**
	 * Notify $user_id that an update they created was deleted by someone
	 * else. The deleted update's row is already gone so we key off the
	 * combination of update_id + actor + time to keep the entry uniquely
	 * dismissable. Title holds the update title so the row reads cleanly.
	 */
	public static function add_deleted( int $update_id, int $order_id, string $title, int $user_id ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'deleted_' . $update_id,
				'type'      => 'deleted',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => 0,
				'title'     => $title,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify $user_id (the old assignee) that they were unassigned from an
	 * update. Keyed by update_id so a series of reassignments doesn't pile
	 * up multiple "you were unassigned" rows for the same ticket.
	 */
	public static function add_unassigned( int $update_id, int $order_id, string $title, int $user_id ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'unassigned_' . $update_id,
				'type'      => 'unassigned',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => 0,
				'title'     => $title,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify $user_id (the creator) that the assignee on an update they
	 * created has changed. Keyed by update_id so multiple reassignments
	 * coalesce into one notification row.
	 */
	public static function add_assignee_changed( int $update_id, int $order_id, string $title, int $user_id ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'assignee_changed_' . $update_id,
				'type'      => 'assignee_changed',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => 0,
				'title'     => $title,
				'time'      => time(),
			),
			$user_id
		);
	}

	public static function add_assigned( int $update_id, int $order_id, string $title, int $user_id ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'assigned_' . $update_id,
				'type'      => 'assigned',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => 0,
				'title'     => $title,
				'time'      => time(),
			),
			$user_id
		);
	}

	/** Notify $user_id that a customer replied to an update they own. Keyed by note_id. */
	public static function add_customer_reply( int $update_id, int $order_id, int $note_id, string $title, int $user_id ): void {
		if ( ! $update_id || ! $note_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'reply_' . $note_id,
				'type'      => 'customer_reply',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => $note_id,
				'title'     => $title,
				'time'      => time(),
			),
			$user_id
		);
	}

	/** Notify $user_id that a staff member replied via the customer portal. Title stores the staff display name. Keyed by note_id. */
	public static function add_staff_reply( int $update_id, int $order_id, int $note_id, string $staff_name, int $user_id ): void {
		if ( ! $update_id || ! $note_id || ! $user_id || '' === trim( $staff_name ) ) {
			return;
		}

		self::store(
			array(
				'key'       => 'staff_reply_' . $note_id,
				'type'      => 'staff_reply',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => $note_id,
				'title'     => $staff_name,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify $user_id (a participant — creator, current assignee, or someone
	 * who's already posted/been tagged) that a new note landed on the thread.
	 * Sits beside add_mention but for the non-@mention case so the row shows
	 * up under "Replies" instead of the explicit "You were tagged" bucket.
	 * Keyed by note_id so the row collapses if multiple participants share
	 * the same notification trail.
	 */
	public static function add_participant_reply( int $update_id, int $order_id, int $note_id, string $snippet, int $user_id, string $note_type = '' ): void {
		if ( ! $update_id || ! $note_id || ! $user_id ) {
			return;
		}

		$note_type = in_array( $note_type, array( 'internal', 'customer' ), true ) ? $note_type : '';

		self::store(
			array(
				'key'       => 'participant_' . $note_id,
				'type'      => 'participant_reply',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => $note_id,
				// `note_type` tells the deep link which tab (internal /
				// customer) to open on click — needed because participant
				// notifications fan out for both kinds.
				'note_type' => $note_type,
				'title'     => $snippet,
				'time'      => time(),
			),
			$user_id
		);
	}

	/** Notify $user_id that they were @mentioned in a note. Title stores the note text snippet. Keyed by note_id. */
	public static function add_mention( int $update_id, int $order_id, int $note_id, string $snippet, int $user_id ): void {
		if ( ! $update_id || ! $note_id || ! $user_id ) {
			return;
		}

		self::store(
			array(
				'key'       => 'mention_' . $note_id,
				'type'      => 'mention',
				'update_id' => $update_id,
				'order_id'  => $order_id,
				'note_id'   => $note_id,
				'title'     => $snippet,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * @return array<int, array{key:string, type:string, update_id:int, order_id:int, note_id:int, title:string, time:int}>
	 */
	public static function get_active( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		$cache_key = self::CACHE_PFX . $user_id;
		$cached    = wp_cache_get( $cache_key, Constants::CACHE_GROUP );

		if ( false !== $cached ) {
			return $cached;
		}

		$all    = self::get_raw( $user_id );
		$active = array_values( array_filter( $all, static fn( array $n ) => empty( $n['dismissed'] ) ) );

		wp_cache_set( $cache_key, $active, Constants::CACHE_GROUP, self::CACHE_TTL );

		return $active;
	}

	public static function dismiss( string $key, int $user_id ): void {
		if ( ! $key || ! $user_id ) {
			return;
		}

		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( $n['key'] === $key && empty( $n['dismissed'] ) ) {
				$n['dismissed'] = true;
				$changed        = true;
			}
		}
		unset( $n );

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $all );
			self::clear_cache( $user_id );
		}
	}

	/**
	 * Dismiss every active notification for one user — the "Clear All" path
	 * in the admin-bar dropdown. Dismissed rows stay in the store (so the
	 * dedupe guard in {@see store()} still recognises them) but disappear
	 * from {@see get_active()}, matching the per-row {@see dismiss()} flow.
	 */
	public static function dismiss_all( int $user_id ): void {
		if ( ! $user_id ) {
			return;
		}

		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( empty( $n['dismissed'] ) ) {
				$n['dismissed'] = true;
				$changed        = true;
			}
		}
		unset( $n );

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $all );
			self::clear_cache( $user_id );
		}
	}

	/** Dismiss all notifications for an update — call when a user marks the whole thread as read. No-op if none are active. */
	public static function dismiss_for_update( int $update_id, int $user_id ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( (int) $n['update_id'] === $update_id && empty( $n['dismissed'] ) ) {
				$n['dismissed'] = true;
				$changed        = true;
			}
		}
		unset( $n );

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $all );
			self::clear_cache( $user_id );
		}
	}

	/**
	 * Cascade — remove notifications referencing $update_id from every user
	 * who has any in their bar. Called on update delete so stale entries
	 * don't linger pointing to a row that no longer exists. One query to
	 * find the candidate users (bounded by staff size, typically <10), then
	 * one update_user_meta per match. Notifications are physically removed
	 * rather than just dismissed — the parent row is gone, there's nothing
	 * to undismiss or click through to.
	 */
	public static function dismiss_for_update_for_all_users( int $update_id ): void {
		if ( ! $update_id ) {
			return;
		}

		global $wpdb;

		$user_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
			self::META_KEY
		) );

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! $user_id ) {
				continue;
			}

			$all      = self::get_raw( $user_id );
			$filtered = array_values( array_filter(
				$all,
				static fn( array $n ): bool => (int) ( $n['update_id'] ?? 0 ) !== $update_id
			) );

			if ( count( $filtered ) === count( $all ) ) {
				continue;
			}

			if ( empty( $filtered ) ) {
				delete_user_meta( $user_id, self::META_KEY );
			} else {
				update_user_meta( $user_id, self::META_KEY, $filtered );
			}

			self::clear_cache( $user_id );
		}
	}

	public static function clear_cache( int $user_id ): void {
		wp_cache_delete( self::CACHE_PFX . $user_id, Constants::CACHE_GROUP );
	}

	private static function store( array $notification, int $user_id ): void {
		$all = self::get_raw( $user_id );

		// Prevent duplicates — same key already exists (active or dismissed).
		foreach ( $all as $existing ) {
			if ( $existing['key'] === $notification['key'] ) {
				return;
			}
		}

		$all[] = $notification;

		if ( count( $all ) > self::MAX_STORED ) {
			$all = array_slice( $all, - self::MAX_STORED );
		}

		update_user_meta( $user_id, self::META_KEY, $all );
		self::clear_cache( $user_id );
	}

	private static function get_raw( int $user_id ): array {
		$data = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $data ) ? $data : array();
	}
}
