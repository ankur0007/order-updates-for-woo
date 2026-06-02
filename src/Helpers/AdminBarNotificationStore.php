<?php
/**
 * Per-user admin bar notification store (user meta + object cache).
 *
 * @package OrderUpdatesForWoo
 */

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
 *   title       — display text for the notification row
 *   time        — Unix timestamp
 *   dismissed   — bool, true once read
 *   favorited   — bool, starred by the user
 *   archived    — bool, moved out of the active list
 *   archived_at — Unix timestamp the row was archived (0 when not archived)
 *   target_deleted — bool, the note/update/order it pointed to was deleted
 */
final class AdminBarNotificationStore {
	private const META_KEY   = 'order_updates_for_woo_notifications';
	private const CACHE_PFX  = 'ab_notifs_';
	private const CACHE_TTL  = 30;
	private const MAX_STORED = 50;

	/**
	 * Notify $user_id that an update they created was deleted by someone
	 * else. The deleted update's row is already gone so we key off the
	 * update_id to keep the entry uniquely dismissable. Title holds the
	 * update title; $actor is who deleted it.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param string $title     Deleted update's title.
	 * @param int    $user_id   Recipient (the update's creator).
	 * @param string $actor     Who deleted it.
	 */
	public static function add_deleted( int $update_id, int $order_id, string $title, int $user_id, string $actor = '' ): void {
		if ( ! $update_id || ! $user_id ) {
			return;
		}

		// Deleted-note notices are after-the-fact (nothing to open or act on),
		// so they land straight in Archived rather than the active list.
		self::store(
			array(
				'key'         => 'deleted_' . $update_id,
				'type'        => 'deleted',
				'update_id'   => $update_id,
				'order_id'    => $order_id,
				'note_id'     => 0,
				'title'       => $title,
				'actor'       => $actor,
				'archived'    => true,
				'archived_at' => time(),
				'time'        => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify $user_id (the old assignee) that they were unassigned from an
	 * update. Keyed by update_id so a series of reassignments doesn't pile
	 * up multiple "you were unassigned" rows for the same ticket.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param string $title     Update title.
	 * @param int    $user_id   Recipient (the old assignee).
	 * @param string $actor     Who unassigned them.
	 */
	public static function add_unassigned( int $update_id, int $order_id, string $title, int $user_id, string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify $user_id (the creator) that the assignee on an update they
	 * created has changed. Keyed by update_id so multiple reassignments
	 * coalesce into one notification row.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param string $title     Update title.
	 * @param int    $user_id   Recipient (the creator).
	 * @param string $actor     Who changed the assignee.
	 */
	public static function add_assignee_changed( int $update_id, int $order_id, string $title, int $user_id, string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify a user they were assigned to an update. Keyed by update.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param string $title     Update title.
	 * @param int    $user_id   Recipient (the new assignee).
	 * @param string $actor     Who assigned them.
	 */
	public static function add_assigned( int $update_id, int $order_id, string $title, int $user_id, string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify a user a customer replied to an update they own. Keyed by note.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param int    $note_id   Customer note id.
	 * @param string $title     The customer's message.
	 * @param int    $user_id   Recipient (assignee or owner).
	 * @param string $actor     The customer's name.
	 */
	public static function add_customer_reply( int $update_id, int $order_id, int $note_id, string $title, int $user_id, string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify a user a staff member replied via the customer portal. Keyed by note.
	 *
	 * @param int    $update_id  Update id.
	 * @param int    $order_id   Order id.
	 * @param int    $note_id    Customer note id.
	 * @param string $staff_name Name of the staff member who replied.
	 * @param int    $user_id    Recipient.
	 * @param string $title      The message; falls back to the staff name.
	 */
	public static function add_staff_reply( int $update_id, int $order_id, int $note_id, string $staff_name, int $user_id, string $title = '' ): void {
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
				'title'     => '' !== $title ? $title : $staff_name,
				'actor'     => $staff_name,
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
	 * the same notification trail. $actor is the note author.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param int    $note_id   Note id.
	 * @param string $snippet   Note text shown on the row.
	 * @param int    $user_id   Recipient (a thread participant).
	 * @param string $note_type 'internal' or 'customer' — picks the deep-link tab.
	 * @param string $actor     The note author.
	 */
	public static function add_participant_reply( int $update_id, int $order_id, int $note_id, string $snippet, int $user_id, string $note_type = '', string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Notify a user they were @mentioned in a note. Keyed by note.
	 *
	 * @param int    $update_id Update id.
	 * @param int    $order_id  Order id.
	 * @param int    $note_id   Note id.
	 * @param string $snippet   Note text shown on the row.
	 * @param int    $user_id   Recipient (the mentioned user).
	 * @param string $actor     Who tagged them.
	 */
	public static function add_mention( int $update_id, int $order_id, int $note_id, string $snippet, int $user_id, string $actor = '' ): void {
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
				'actor'     => $actor,
				'time'      => time(),
			),
			$user_id
		);
	}

	/**
	 * Unread, non-archived notifications for a user (cached briefly).
	 *
	 * @param int $user_id User id.
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

		// Active = unread and not archived — what the admin bar badge counts.
		$all    = self::get_raw( $user_id );
		$active = array_values( array_filter( $all, static fn( array $n ) => empty( $n['dismissed'] ) && empty( $n['archived'] ) ) );

		wp_cache_set( $cache_key, $active, Constants::CACHE_GROUP, self::CACHE_TTL );

		return $active;
	}

	/**
	 * Unread, non-archived count — drives the admin-bar badge.
	 *
	 * @param int $user_id User id.
	 */
	public static function unread_count( int $user_id ): int {
		return count( self::get_active( $user_id ) );
	}

	/**
	 * Mark one notification as read (it stays in the store).
	 *
	 * @param string $key     Notification key.
	 * @param int    $user_id User id.
	 */
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
	 *
	 * @param int $user_id User id.
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

	/**
	 * Mark every notification for an update as read. No-op if none are active.
	 *
	 * @param int $update_id Update id.
	 * @param int $user_id   User id.
	 */
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
	 * Cascade — archive every notification referencing $update_id for all
	 * users when the update is deleted. We keep them (moved to Archived)
	 * rather than removing them, so staff still have a record and nothing
	 * confusing lingers in the active list pointing at a row that's gone.
	 * One query to find the candidate users (bounded by staff size), then
	 * one write per affected user.
	 *
	 * @param int $update_id Update id whose notifications should be archived.
	 */
	public static function archive_for_update_for_all_users( int $update_id ): void {
		if ( ! $update_id ) {
			return;
		}

		global $wpdb;

		$user_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::META_KEY
			) 
		);

		$now = time();

		foreach ( $user_ids as $user_id ) {
			$user_id = (int) $user_id;
			if ( ! $user_id ) {
				continue;
			}

			self::update_all(
				$user_id,
				static function ( array &$n ) use ( $update_id, $now ): bool {
					if ( (int) ( $n['update_id'] ?? 0 ) !== $update_id ) {
						return false;
					}
					if ( ! empty( $n['archived'] ) && ! empty( $n['target_deleted'] ) ) {
						return false;
					}
					$n['archived']       = true;
					$n['target_deleted'] = true;
					if ( empty( $n['archived_at'] ) ) {
						$n['archived_at'] = $now;
					}
					return true;
				}
			);
		}
	}

	/**
	 * Drop the cached active-notification list for a user.
	 *
	 * @param int $user_id User id.
	 */
	public static function clear_cache( int $user_id ): void {
		wp_cache_delete( self::CACHE_PFX . $user_id, Constants::CACHE_GROUP );
	}

	/**
	 * All notifications for a user — both active and dismissed. The history
	 * page renders both, then differentiates read (dismissed) vs unread visually.
	 *
	 * @param int $user_id User id.
	 * @return array<int, array{key:string, type:string, update_id:int, order_id:int, note_id:int, title:string, time:int, dismissed?:bool}>
	 */
	public static function get_all( int $user_id ): array {
		if ( ! $user_id ) {
			return array();
		}

		return self::get_raw( $user_id );
	}

	/**
	 * Flip one notification's read state — the page can mark read or unread.
	 *
	 * @param string $key     Notification key.
	 * @param int    $user_id User id.
	 * @param bool   $read    True to mark read, false to mark unread.
	 */
	public static function set_read( string $key, int $user_id, bool $read ): void {
		self::update_one(
			$key,
			$user_id,
			static function ( array &$n ) use ( $read ): bool {
				if ( (bool) ( $n['dismissed'] ?? false ) === $read ) {
					return false;
				}
				$n['dismissed'] = $read;
				return true;
			}
		);
	}

	/**
	 * Star or un-star one notification.
	 *
	 * @param string $key      Notification key.
	 * @param int    $user_id  User id.
	 * @param bool   $favorite True to star, false to un-star.
	 */
	public static function set_favorite( string $key, int $user_id, bool $favorite ): void {
		self::update_one(
			$key,
			$user_id,
			static function ( array &$n ) use ( $favorite ): bool {
				if ( (bool) ( $n['favorited'] ?? false ) === $favorite ) {
					return false;
				}
				$n['favorited'] = $favorite;
				return true;
			}
		);
	}

	/**
	 * Archive one notification and flag its target as deleted, so the row shows
	 * a "Deleted" tag.
	 *
	 * @param string $key     Notification key.
	 * @param int    $user_id User id.
	 */
	public static function archive_as_deleted( string $key, int $user_id ): void {
		self::update_one(
			$key,
			$user_id,
			static function ( array &$n ): bool {
				if ( ! empty( $n['archived'] ) && ! empty( $n['target_deleted'] ) ) {
					return false;
				}
				$n['archived']       = true;
				$n['target_deleted'] = true;
				if ( empty( $n['archived_at'] ) ) {
					$n['archived_at'] = time();
				}
				return true;
			}
		);
	}

	/**
	 * Archive or unarchive one notification. Stamps archived_at so cleanup can age it out.
	 *
	 * @param string $key      Notification key.
	 * @param int    $user_id  User id.
	 * @param bool   $archived True to archive, false to restore.
	 */
	public static function set_archived( string $key, int $user_id, bool $archived ): void {
		self::update_one(
			$key,
			$user_id,
			static function ( array &$n ) use ( $archived ): bool {
				if ( (bool) ( $n['archived'] ?? false ) === $archived ) {
					return false;
				}
				$n['archived']    = $archived;
				$n['archived_at'] = $archived ? time() : 0;
				return true;
			}
		);
	}

	/**
	 * Bulk archive by key list. One write, one cache bust.
	 *
	 * @param string[] $keys    Notification keys to archive.
	 * @param int      $user_id User id.
	 */
	public static function archive_many( array $keys, int $user_id ): void {
		if ( empty( $keys ) || ! $user_id ) {
			return;
		}

		$set = array_flip( array_map( 'strval', $keys ) );
		self::update_all(
			$user_id,
			static function ( array &$n ) use ( $set ): bool {
				if ( ! isset( $set[ (string) ( $n['key'] ?? '' ) ] ) || ! empty( $n['archived'] ) ) {
					return false;
				}
				$n['archived']    = true;
				$n['archived_at'] = time();
				return true;
			}
		);
	}

	/**
	 * Stage one of retention — move active notifications older than $days into
	 * Archived. Skips already-archived and favourited rows. Stamps archived_at
	 * so stage two can age them out from there.
	 *
	 * @param int $user_id User id.
	 * @param int $days    Archive notifications older than this many days.
	 */
	public static function archive_aged( int $user_id, int $days ): void {
		if ( ! $user_id || $days < 1 ) {
			return;
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );
		$now    = time();

		self::update_all(
			$user_id,
			static function ( array &$n ) use ( $cutoff, $now ): bool {
				if ( ! empty( $n['archived'] ) || ! empty( $n['favorited'] ) ) {
					return false;
				}
				if ( (int) ( $n['time'] ?? 0 ) >= $cutoff ) {
					return false;
				}
				$n['archived']    = true;
				$n['archived_at'] = $now;
				return true;
			}
		);
	}

	/**
	 * Drop notifications whose state bucket is in $tags and that are older
	 * than $days. Runs from the scheduled cleanup. Buckets: 'archived' ages
	 * off archived_at, the rest off time. Favorited rows are always kept.
	 *
	 * @param int      $user_id User id.
	 * @param string[] $tags    Buckets to purge, e.g. array( 'archived', 'read' ).
	 * @param int      $days    Purge rows older than this many days.
	 */
	public static function purge_expired( int $user_id, array $tags, int $days ): void {
		if ( ! $user_id || empty( $tags ) || $days < 1 ) {
			return;
		}

		$cutoff   = time() - ( $days * DAY_IN_SECONDS );
		$tags     = array_flip( $tags );
		$all      = self::get_raw( $user_id );
		$filtered = array_values(
			array_filter(
				$all,
				static function ( array $n ) use ( $tags, $cutoff ): bool {
					$bucket = self::bucket_for( $n );
					if ( ! isset( $tags[ $bucket ] ) ) {
						return true; // Not a purgeable tag — keep.
					}
					$stamp = 'archived' === $bucket ? (int) ( $n['archived_at'] ?? 0 ) : (int) ( $n['time'] ?? 0 );
					return $stamp >= $cutoff; // Keep while newer than the cutoff.
				}
			)
		);

		if ( count( $filtered ) === count( $all ) ) {
			return;
		}

		if ( empty( $filtered ) ) {
			delete_user_meta( $user_id, self::META_KEY );
		} else {
			update_user_meta( $user_id, self::META_KEY, $filtered );
		}

		self::clear_cache( $user_id );
	}

	/**
	 * User ids that currently hold any notifications — bounded by staff size.
	 * Lets the cleanup scheduler chunk work one user at a time.
	 *
	 * @return int[]
	 */
	public static function user_ids_with_notifications(): array {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
				self::META_KEY
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * State bucket used by the cleanup tag match. Favorited always wins so
	 * favourites are never purged.
	 *
	 * @param array $n Notification row.
	 */
	private static function bucket_for( array $n ): string {
		if ( ! empty( $n['favorited'] ) ) {
			return 'favorite';
		}
		if ( ! empty( $n['archived'] ) ) {
			return 'archived';
		}
		if ( ! empty( $n['dismissed'] ) ) {
			return 'read';
		}
		return 'unread';
	}

	/**
	 * Mutate the single row matching $key, saving once if it changed.
	 *
	 * @param string   $key     Notification key.
	 * @param int      $user_id User id.
	 * @param callable $mutator Receives the row by reference; returns true if it changed it.
	 */
	private static function update_one( string $key, int $user_id, callable $mutator ): void {
		if ( '' === $key || ! $user_id ) {
			return;
		}

		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( ( $n['key'] ?? '' ) === $key && $mutator( $n ) ) {
				$changed = true;
			}
		}
		unset( $n );

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $all );
			self::clear_cache( $user_id );
		}
	}

	/**
	 * Run $mutator over every row, saving once if anything changed.
	 *
	 * @param int      $user_id User id.
	 * @param callable $mutator Receives each row by reference; returns true if it changed it.
	 */
	private static function update_all( int $user_id, callable $mutator ): void {
		if ( ! $user_id ) {
			return;
		}

		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( $mutator( $n ) ) {
				$changed = true;
			}
		}
		unset( $n );

		if ( $changed ) {
			update_user_meta( $user_id, self::META_KEY, $all );
			self::clear_cache( $user_id );
		}
	}

	/**
	 * Physically remove one notification (vs dismiss, which just marks it read).
	 *
	 * @param string $key     Notification key.
	 * @param int    $user_id User id.
	 */
	public static function delete( string $key, int $user_id ): void {
		if ( ! $key || ! $user_id ) {
			return;
		}

		$all      = self::get_raw( $user_id );
		$filtered = array_values( array_filter( $all, static fn( array $n ) => ( $n['key'] ?? '' ) !== $key ) );

		if ( count( $filtered ) === count( $all ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $filtered );
		self::clear_cache( $user_id );
	}

	/**
	 * Bulk delete by key list. One write, one cache bust — used by the
	 * notifications history page's bulk-action handler.
	 *
	 * @param string[] $keys    Notification keys to delete.
	 * @param int      $user_id User id.
	 */
	public static function delete_many( array $keys, int $user_id ): void {
		if ( empty( $keys ) || ! $user_id ) {
			return;
		}

		$keys     = array_flip( array_map( 'strval', $keys ) );
		$all      = self::get_raw( $user_id );
		$filtered = array_values( array_filter( $all, static fn( array $n ) => ! isset( $keys[ (string) ( $n['key'] ?? '' ) ] ) ) );

		if ( count( $filtered ) === count( $all ) ) {
			return;
		}

		update_user_meta( $user_id, self::META_KEY, $filtered );
		self::clear_cache( $user_id );
	}

	/**
	 * Bulk mark-as-read by key list. Mirrors dismiss() but accepts many keys
	 * and writes once. Marks rows as dismissed; does not remove from storage.
	 *
	 * @param string[] $keys    Notification keys to mark read.
	 * @param int      $user_id User id.
	 */
	public static function dismiss_many( array $keys, int $user_id ): void {
		if ( empty( $keys ) || ! $user_id ) {
			return;
		}

		$keys    = array_flip( array_map( 'strval', $keys ) );
		$all     = self::get_raw( $user_id );
		$changed = false;

		foreach ( $all as &$n ) {
			if ( isset( $keys[ (string) ( $n['key'] ?? '' ) ] ) && empty( $n['dismissed'] ) ) {
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
	 * Persist a new notification, skipping duplicates and capping the stored count.
	 *
	 * @param array $notification Notification row to store.
	 * @param int   $user_id      User id.
	 */
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

	/**
	 * Raw stored notifications for a user (uncached), or an empty array.
	 *
	 * @param int $user_id User id.
	 */
	private static function get_raw( int $user_id ): array {
		$data = get_user_meta( $user_id, self::META_KEY, true );

		return is_array( $data ) ? $data : array();
	}
}
