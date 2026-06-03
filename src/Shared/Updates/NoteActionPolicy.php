<?php
/**
 * Permission rules for editing or deleting notes on an update.
 *
 * Centralises every "can this user edit/delete this note right now?" check
 * so the same rules apply on both the admin and customer side. The two
 * gates that always apply: the user must be the note's author, and the edit
 * window (default 15 minutes) must not have expired.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Decides whether the current user may edit or delete a given note.
 */
final class NoteActionPolicy {
	private const DEFAULT_EDIT_WINDOW_MINUTES = 1;

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesSettingsService $settings_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesSettingsService $settings_service
	) {}

	/**
	 * How long after creation a note can still be edited or deleted, in
	 * minutes. Configurable via the `order_updates_for_woo_note_edit_window_minutes`
	 * option. Clamped to 1–1440 minutes (1 minute to 24 hours).
	 */
	public function get_edit_window_minutes(): int {
		$minutes = absint( get_option( Constants::NOTE_EDIT_WINDOW_OPTION, self::DEFAULT_EDIT_WINDOW_MINUTES ) );

		if ( $minutes < 1 ) {
			return self::DEFAULT_EDIT_WINDOW_MINUTES;
		}

		return min( 1440, $minutes );
	}

	/**
	 * A staff member may edit their own internal note. Gates that always apply:
	 *
	 *   1. Master option — admin opted into note editing (off by default).
	 *   2. Authorship — only the note's author can edit it.
	 *   3. Latest-only — any newer note in the same thread locks this one
	 *      permanently. The moment a follow-up arrives, the prior note is
	 *      part of the historical record and cannot be rewritten.
	 *   4. Edit window — author has N minutes (default 15) after posting.
	 *
	 * $latest_note_id is the highest note id in this note's thread; pass 0
	 * to skip the latest-only check (callers that already gate elsewhere).
	 *
	 * @param array $note           Note row.
	 * @param int   $latest_note_id Highest note id in the thread (0 to skip).
	 */
	public function can_edit_internal_note( array $note, int $latest_note_id = 0 ): bool {
		return $this->settings_service->allow_note_edit()
			&& $this->is_current_user_the_note_author( $note )
			&& $this->is_latest_in_thread( $note, $latest_note_id )
			&& $this->is_within_edit_window( (string) ( $note['created_at'] ?? '' ) );
	}

	/**
	 * A staff member may delete their own internal note. Same four gates as
	 * edit (master toggle, authorship, latest-only, window), plus the legacy
	 * internal-note-delete sub-toggle scoping delete to staff-internal rows.
	 *
	 * @param array $note           Note row.
	 * @param int   $latest_note_id Highest note id in the thread (0 to skip).
	 */
	public function can_delete_internal_note( array $note, int $latest_note_id = 0 ): bool {
		return $this->settings_service->allow_note_delete()
			&& $this->settings_service->allow_member_note_delete()
			&& $this->is_current_user_the_note_author( $note )
			&& $this->is_latest_in_thread( $note, $latest_note_id )
			&& $this->is_within_edit_window( (string) ( $note['created_at'] ?? '' ) );
	}

	/**
	 * A staff member may edit their own customer-facing note. Same gates as
	 * internal-note edit, PLUS a once-the-email-fires lock: a note that has
	 * already been delivered to the customer (notified_at is set) cannot be
	 * silently rewritten. Editing after delivery would leave the customer's
	 * inbox showing the original wording while the portal shows the edited
	 * version — different on two surfaces, classic source-of-truth bug.
	 * Edits within the pre-notification window are still fine (typo escape
	 * hatch).
	 *
	 * @param array $note           Note row.
	 * @param int   $latest_note_id Highest note id in the thread (0 to skip).
	 */
	public function can_edit_member_customer_note( array $note, int $latest_note_id = 0 ): bool {
		return $this->settings_service->allow_note_edit()
			&& $this->is_current_user_the_note_author( $note )
			&& $this->is_latest_in_thread( $note, $latest_note_id )
			&& $this->is_within_edit_window( (string) ( $note['created_at'] ?? '' ) )
			&& ! $this->customer_note_already_delivered( $note );
	}

	/**
	 * A customer may edit their own customer-facing note. Same gates as the
	 * member path, just with a customer-authorship check instead of staff.
	 *
	 * @param array $note              Note row.
	 * @param int   $order_customer_id The order's customer user id.
	 * @param bool  $is_guest          Whether the viewer is a guest.
	 * @param int   $latest_note_id    Highest note id in the thread (0 to skip).
	 */
	public function can_edit_customer_authored_note( array $note, int $order_customer_id, bool $is_guest, int $latest_note_id = 0 ): bool {
		return $this->settings_service->allow_note_edit()
			&& $this->is_current_customer_the_note_author( $note, $order_customer_id, $is_guest )
			&& $this->is_latest_in_thread( $note, $latest_note_id )
			&& $this->is_within_edit_window( (string) ( $note['created_at'] ?? '' ) );
	}

	/**
	 * Public accessor for the customer-note edit-window check, used by the
	 * edit endpoint to report "edit window expired" distinctly from generic
	 * forbidden. When the master option is off this returns false so the
	 * endpoint short-circuits before reaching window-specific error paths.
	 *
	 * @param array $note Note row.
	 */
	public function is_within_customer_note_edit_window( array $note ): bool {
		return $this->settings_service->allow_note_edit()
			&& $this->is_within_edit_window( (string) ( $note['created_at'] ?? '' ) );
	}

	/**
	 * True when the note is the most recent one in its thread (or when the
	 * caller passed 0 to skip the check). A note is "latest" when no other
	 * note in the same thread has a higher id.
	 *
	 * @param array $note           Note row.
	 * @param int   $latest_note_id Highest note id in the thread (0 to skip).
	 */
	private function is_latest_in_thread( array $note, int $latest_note_id ): bool {
		if ( 0 === $latest_note_id ) {
			return true;
		}

		return (int) ( $note['id'] ?? 0 ) === $latest_note_id;
	}

	/**
	 * True when the customer notification for this note has already fired
	 * (notified_at is a non-empty timestamp). Once delivered, the wording is
	 * part of the customer's inbox record; editing here would silently
	 * desync the on-portal version. Notes still in the queue (queued_at set,
	 * notified_at empty) are NOT locked — the queued email re-fetches the
	 * note body at send time, so an edit before delivery propagates safely.
	 *
	 * @param array $note Note row.
	 */
	private function customer_note_already_delivered( array $note ): bool {
		return '' !== trim( (string) ( $note['notified_at'] ?? '' ) );
	}

	/**
	 * True when the current logged-in user is the user who created this note.
	 * Used for staff-authored notes (internal or customer-facing).
	 *
	 * @param array $note Note row.
	 */
	private function is_current_user_the_note_author( array $note ): bool {
		$current_user_id = get_current_user_id();

		return $current_user_id > 0
			&& (int) ( $note['created_by'] ?? 0 ) === $current_user_id;
	}

	/**
	 * True when the current viewer is the customer who created this note.
	 *
	 * Logged-in customer: the note's created_by matches both the order's
	 * customer ID and the current logged-in user.
	 * Guest customer:     the note was guest-authored (created_by = 0).
	 *
	 * @param array $note              Note row.
	 * @param int   $order_customer_id The order's customer user id.
	 * @param bool  $is_guest          Whether the viewer is a guest.
	 */
	private function is_current_customer_the_note_author( array $note, int $order_customer_id, bool $is_guest ): bool {
		$created_by = (int) ( $note['created_by'] ?? 0 );

		if ( $is_guest ) {
			return 0 === $created_by;
		}

		return $order_customer_id > 0
			&& $created_by === $order_customer_id
			&& get_current_user_id() === $created_by;
	}

	/**
	 * True if the note was created less than `get_edit_window_minutes()` ago.
	 * Treats unparseable / blank timestamps as already-expired (safe default).
	 *
	 * @param string $created_at_utc Note creation time (GMT mysql).
	 */
	private function is_within_edit_window( string $created_at_utc ): bool {
		if ( '' === $created_at_utc ) {
			return false;
		}

		$created_ts = strtotime( $created_at_utc . ' UTC' );

		if ( false === $created_ts ) {
			return false;
		}

		$window_seconds = $this->get_edit_window_minutes() * MINUTE_IN_SECONDS;

		return time() <= ( $created_ts + $window_seconds );
	}
}
