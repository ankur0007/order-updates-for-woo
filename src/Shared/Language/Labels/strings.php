<?php

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$labels = [
	'loadMore' => __('Load more', 'order-updates-for-woo'),
	'internalNoteLabel' => __('Internal note', 'order-updates-for-woo'),
	'customerNoteLabel' => __('Customer note', 'order-updates-for-woo'),
	'saveFailed' => __('Could not save the update.', 'order-updates-for-woo'),
	'titleRequired' => __('Update title is required.', 'order-updates-for-woo'),
	/* translators: %s: field name (e.g. "Title", "Note"). */
	'plainTextOnly' => __('%s must be plain text only.', 'order-updates-for-woo'),
	/* translators: 1: field name, 2: maximum character count. */
	'characterLimit' => __('%1$s must be %2$d characters or less.', 'order-updates-for-woo'),
	'addHeading' => __('Add New Update', 'order-updates-for-woo'),
	'editHeading' => __('Edit Update', 'order-updates-for-woo'),
	'addAction' => __('Publish Update', 'order-updates-for-woo'),
	'editAction' => __('Save Changes', 'order-updates-for-woo'),
	'loadingMore' => __('Loading more updates...', 'order-updates-for-woo'),
	'invalidNonce' => __('Security check failed.', 'order-updates-for-woo'),
	'sessionExpiredRefresh' => __('Your session expired. Please refresh the page and try again.', 'order-updates-for-woo'),
	/* translators: 1: user display name, 2: timestamp. */
	'historyCreated' => __('Created by %1$s at %2$s', 'order-updates-for-woo'),
	/* translators: 1: assignee name, 2: assigner name, 3: timestamp. */
	'historyAssigned' => __('Assigned to %1$s by %2$s at %3$s', 'order-updates-for-woo'),
	/* translators: 1: prior assignee name, 2: actor name, 3: timestamp. */
	'historyUnassigned' => __('Unassigned from %1$s by %2$s at %3$s', 'order-updates-for-woo'),
	/* translators: 1: assignee name, 2: timestamp. */
	'historyNotifiedAssignee' => __('Notified assignee (%1$s) at %2$s', 'order-updates-for-woo'),
	/* translators: 1: solver name, 2: timestamp. */
	'historySolved' => __('Marked solved by %1$s at %2$s', 'order-updates-for-woo'),
	/* translators: 1: reopener name, 2: timestamp. */
	'historyReopened' => __('Reopened by %1$s at %2$s', 'order-updates-for-woo'),
	/* translators: %s: timestamp. */
	'historyNotifiedCustomer' => __('Notified customer at %s', 'order-updates-for-woo'),
	/* translators: 1: status-change message, 2: actor display name, 3: timestamp. */
	'historyStatusChanged' => __('%1$s by %2$s at %3$s', 'order-updates-for-woo'),
	/* translators: 1: rating message ("Customer rated 4/5 …"), 2: timestamp. */
	'historyRated' => __('%1$s at %2$s', 'order-updates-for-woo'),
	'historyModalTitle' => __('Update Actions History', 'order-updates-for-woo'),
	'historyLoading' => __('Loading history...', 'order-updates-for-woo'),
	'historyEmpty' => __('No actions recorded for this update.', 'order-updates-for-woo'),
	'deleteInlinePrompt' => __('Delete this update?', 'order-updates-for-woo'),
	'deleteInlineCancel' => __('Cancel', 'order-updates-for-woo'),
	'deleteInlineSilent' => __('Silently delete', 'order-updates-for-woo'),
	'deleteInlineBare' => __('Delete', 'order-updates-for-woo'),
	'deleteInlineNotify' => __('Notify customer & delete', 'order-updates-for-woo'),
	'reopenConfirm' => __('Re-open this update?', 'order-updates-for-woo'),
	'solveInlinePrompt' => __('Mark this update as solved?', 'order-updates-for-woo'),
	'solveInlineSilent' => __('Silently mark solved', 'order-updates-for-woo'),
	'solveInlineBare' => __('Mark solved', 'order-updates-for-woo'),
	'solveInlineNotify' => __('Notify customer & mark solved', 'order-updates-for-woo'),
	'notesEmpty' => __('No notes yet.', 'order-updates-for-woo'),
	'newLabel' => __('New', 'order-updates-for-woo'),
	'markAsReadLabel' => __('Mark as read', 'order-updates-for-woo'),
	'customerNotesEmpty' => __('No customer notes yet.', 'order-updates-for-woo'),
	'sendToCustomerAction' => __('Notify customer via email', 'order-updates-for-woo'),
	'sentToCustomerLabel' => __('Sent', 'order-updates-for-woo'),
	'queuedToCustomerLabel' => __('Queued', 'order-updates-for-woo'),
	'fromCustomerBadge' => __('From customer', 'order-updates-for-woo'),
	'showMore' => __('Show more', 'order-updates-for-woo'),
	'showLess' => __('Show less', 'order-updates-for-woo'),
	'loadPreviousNotes' => __('Load previous', 'order-updates-for-woo'),
	/* translators: %s: maximum file size, e.g. "10 MB". */
	'attachmentTooLarge' => __('File is too large. Maximum size is %s.', 'order-updates-for-woo'),
	'attachmentUnsupported' => __('File type is not supported. Allowed: PDF, JPG, PNG, GIF, WEBP.', 'order-updates-for-woo'),
	'attachmentDeleteConfirm' => __('Remove this attachment?', 'order-updates-for-woo'),
	'attachmentRemoveLabel' => __('Remove attachment', 'order-updates-for-woo'),
	/* translators: %d: maximum number of attachments allowed. */
	'attachmentTooManyFiles' => __('You can attach up to %d files.', 'order-updates-for-woo'),
	'editNoteAction' => __('Edit', 'order-updates-for-woo'),
	'editNotePrompt' => __('Edit your note:', 'order-updates-for-woo'),
	'editedLabel' => __('Edited', 'order-updates-for-woo'),
	'noteHistoryHeading' => __('Edit history', 'order-updates-for-woo'),
	'noteHistoryEmpty' => __('No prior versions available.', 'order-updates-for-woo'),
	'loadingLabel' => __('Loading…', 'order-updates-for-woo'),
	'unknownUser'  => __('Unknown', 'order-updates-for-woo'),
	// Internal-note delete only — customer-facing notes are never deletable, they keep edit history.
	'deleteNoteAction' => __('Delete', 'order-updates-for-woo'),
	'customerOptedOutConfirm' => __('This customer has opted out of email notifications. Send anyway?', 'order-updates-for-woo'),
	'customerEmailPrefLabel'  => __('Customer email receiving preference', 'order-updates-for-woo'),
	'customerEmailPrefOn'     => __('On', 'order-updates-for-woo'),
	'customerEmailPrefOff'    => __('Off', 'order-updates-for-woo'),
	'customerEmailPrefOverrideConfirm' => __('Customer has opted out of emails. Override and turn back on?', 'order-updates-for-woo'),
	'deleteNoteConfirm' => __('Are you sure you want to delete this note?', 'order-updates-for-woo'),
	'saveNoteAction' => __('Save', 'order-updates-for-woo'),
	'cancelNoteAction' => __('Cancel', 'order-updates-for-woo'),

	// Customer page — "new" badge.
	'customerUpdatesNewBadge'      => __('New', 'order-updates-for-woo'),
	/* translators: %d: count of new (unread) updates. */
	'customerUpdatesNewBadgeCount' => __('%d new', 'order-updates-for-woo'),
	'customerUpdatesNoNotes'       => __('No messages yet.', 'order-updates-for-woo'),

	// Customer "Write a note" flow.
	'customerWriteNoteSubmitting'  => __('Sending…', 'order-updates-for-woo'),
	'customerWriteNoteSuccess'     => __('Thanks! Your note has been sent to our team.', 'order-updates-for-woo'),
	'customerWriteNoteGenericFail' => __('Could not send your note. Please try again.', 'order-updates-for-woo'),
	'customerWriteNoteSubjectRequired'   => __('Please add a subject.', 'order-updates-for-woo'),
	'customerWriteNoteMessageRequired'   => __('Please write a message.', 'order-updates-for-woo'),
	'noteRequired'                 => __('Please write a note.', 'order-updates-for-woo'),
	/* translators: %d: maximum number of attachments allowed. */
	'customerWriteNoteTooManyFiles'      => __('You can attach up to %d files.', 'order-updates-for-woo'),
	'customerWriteNoteRemoveFile'  => __('Remove', 'order-updates-for-woo'),

	// Inline reply composer under each update thread.
	'customerReplySuccess'         => __('Your reply has been sent.', 'order-updates-for-woo'),
	'customerNoteUpdated'          => __('Your note has been updated.', 'order-updates-for-woo'),

	// Customer rating UI.
	'customerRatingSubmitting'     => __('Sending…', 'order-updates-for-woo'),
	'customerRatingSuccess'        => __('Thanks for your feedback!', 'order-updates-for-woo'),
	/* translators: %s: star rating (1-5). */
	'customerRatingThanks'         => __('You rated this %s/5. Thanks for your feedback!', 'order-updates-for-woo'),
	'customerRatingMissing'        => __('Please pick a star rating.', 'order-updates-for-woo'),
	'customerRatingSaveFailed'     => __('Could not save your rating. Please try again.', 'order-updates-for-woo'),

	// "Still has issue?" escape hatch on a resolved-but-unrated update.
	'customerReopenButton'         => __('Still has issue?', 'order-updates-for-woo'),
	'customerReopenSubmitting'     => __('Re-opening…', 'order-updates-for-woo'),
	'customerReopenFailed'         => __('Could not re-open. Please try again.', 'order-updates-for-woo'),

	// Rating form injected via JS when an update resolves during a poll cycle.
	'ratingHeading'      => __('How did we do?', 'order-updates-for-woo'),
	'ratingIntro'        => __('Your feedback helps us improve. Rate this update and leave a comment if you like.', 'order-updates-for-woo'),
	'ratingCommentLabel' => __('Comment (optional)', 'order-updates-for-woo'),
	'ratingCommentPh'    => __('Tell us what worked or what could be better…', 'order-updates-for-woo'),
	'ratingSubmitLabel'  => __('Submit rating', 'order-updates-for-woo'),
	'ratingStar1Label'   => __('1 star', 'order-updates-for-woo'),
	/* translators: %d: star count (2-5). */
	'ratingStarLabel'    => __('%d stars', 'order-updates-for-woo'),

	// Admin — stale-state 409 responses for markSolved / reopenUpdate.
	'alreadySolved' => __('This update has already been resolved.', 'order-updates-for-woo'),
	'alreadyOpen'   => __('This update is already open.', 'order-updates-for-woo'),

];

return apply_filters('order_updates_for_woo_admin_labels', $labels);
