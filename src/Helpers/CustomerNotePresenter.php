<?php
/**
 * Shapes a customer-thread note row for the admin JS.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;

/**
 * Formats a raw customer-thread note row into the view-ready shape that the
 * admin JS expects from buildCustomerNoteHtml(). Used by both
 * GetCustomerNotesEndpoint and AdminHeartbeatHandler so the shape is defined
 * in exactly one place.
 */
final class CustomerNotePresenter {
	/**
	 * Shape one customer-note row into what the admin JS expects.
	 *
	 * Pass $latest_note_id (the highest customer-note id in the thread) so the
	 * policy's latest-only edit rule can fire here. Callers looping over notes
	 * should resolve it once and pass the same value each time; 0 skips the check.
	 *
	 * @param array            $note           Raw customer-note row.
	 * @param NoteActionPolicy $policy         Decides whether the note is still editable.
	 * @param AttachmentsDb    $attachments_db Loads the note's attachments.
	 * @param int              $latest_note_id Highest customer-note id in the thread, or 0.
	 */
	public static function format_for_admin(
		array $note,
		NoteActionPolicy $policy,
		AttachmentsDb $attachments_db,
		int $latest_note_id = 0
	): array {
		$created_by = (int) ( $note['created_by'] ?? 0 );

		// Rows tagged with a non-default `kind` are system events (e.g.
		// status_change) rather than actual messages. Exposing both the
		// kind and a flag keeps the JS template branch tight.
		$kind      = (string) ( $note['kind'] ?? 'note' );
		$is_system = '' !== $kind && 'note' !== $kind;

		return array(
			'id'              => (int) $note['id'],
			'note'            => (string) $note['note'],
			'kind'            => $kind,
			'is_system'       => $is_system,
			'created_by'      => $created_by,
			'created_by_name' => (string) $note['created_by_name'],
			'avatar_url'      => $created_by > 0 ? (string) get_avatar_url( $created_by, array( 'size' => 56 ) ) : '',
			'created_at'      => DateHelper::format_date( (string) $note['created_at'] ),
			'created_at_utc'  => (string) $note['created_at'],
			'edited_at'       => ! empty( $note['edited_at'] )
				? DateHelper::format_date( (string) $note['edited_at'] )
				: null,
			'edited_at_utc'   => ! empty( $note['edited_at'] ) ? (string) $note['edited_at'] : null,
			'notified_at'     => ! empty( $note['notified_at'] )
				? DateHelper::format_date( (string) $note['notified_at'] )
				: null,
			'notified_at_utc' => ! empty( $note['notified_at'] ) ? (string) $note['notified_at'] : null,
			'queued_at'       => ! empty( $note['queued_at'] )
				? DateHelper::format_date( (string) $note['queued_at'] )
				: null,
			'queued_at_utc'   => ! empty( $note['queued_at'] ) ? (string) $note['queued_at'] : null,
			'from_customer'   => NoteAuthor::is_customer( (int) ( $note['created_by'] ?? 0 ) ),
			'can_edit'        => $policy->can_edit_member_customer_note( $note, $latest_note_id ),
			'attachments'     => AttachmentPresenter::format_many(
				$attachments_db->get_for_note( (int) $note['id'], Constants::NOTE_TYPE_CUSTOMER )
			),
		);
	}
}
