<?php
/**
 * Creates internal staff notes and customer-visible notes on an update.
 *
 * Used by both the admin order edit page and the customer portal. Resolves
 * the note author from the current request, persists the note row through
 * OrderUpdatesDb, and (optionally) queues mention or customer-notification
 * emails via the async job runner.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Shared\Updates;

use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\ParticipantResolver;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WC_Order;

final class UpdateNoteService {
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AsyncJob $async_job,
		private ?ParticipantResolver $participant_resolver = null
	) {}

	/**
	 * Save an internal staff note on an update.
	 *
	 * Any user IDs in $mentioned_user_ids get an async mention email plus an
	 * admin-bar notification. Self-mentions are silently skipped — staff don't
	 * need to be told they tagged themselves.
	 *
	 * Returns the saved note data; 'id' is 0 when the save failed.
	 *
	 * @return array{id:int,note:string,created_by_name:string,created_at_utc:string,mentioned_user_ids:int[]}
	 */
	public function create_internal_note( int $update_id, string $note, array $mentioned_user_ids = array() ): array {
		if ( ! $update_id || '' === $note ) {
			return array(
				'id'                 => 0,
				'note'               => '',
				'created_by_name'    => '',
				'created_at_utc'     => '',
				'mentioned_user_ids' => array(),
			);
		}

		$note_author        = $this->get_current_note_author();
		$mentioned_user_ids = array_values( array_unique( array_filter( array_map( 'absint', $mentioned_user_ids ) ) ) );
		$note_id            = $this->order_updates_db->create_update_note(
			$update_id,
			$note,
			$note_author['id'],
			$note_author['name'],
			$note_author['created_at'],
			$mentioned_user_ids
		);

		if ( $note_id ) {
			$snippet = mb_strlen( $note ) > 80 ? mb_substr( $note, 0, 79 ) . '…' : $note;

			if ( ! empty( $mentioned_user_ids ) ) {
				$this->order_updates_db->invalidate_mention_caches( $mentioned_user_ids );
				$this->queue_mention_emails( $update_id, $note_id, $mentioned_user_ids, $note_author );

				$update   = $this->order_updates_db->get_update( $update_id );
				$order_id = (int) ( $update['order_id'] ?? 0 );

				foreach ( $mentioned_user_ids as $mentioned_user_id ) {
					$mentioned_user_id = absint( $mentioned_user_id );
					if ( $mentioned_user_id && $mentioned_user_id !== absint( $note_author['id'] ?? 0 ) ) {
						do_action( 'order_updates_for_woo_admin_bar_mention', $update_id, $order_id, $note_id, $snippet, $mentioned_user_id );
					}
				}
			}

			// Fan out to other participants (creator, assignee, prior repliers).
			// Anyone @mentioned in THIS note is already emailed above, so they're
			// excluded here.
			$this->queue_participant_notifications(
				$update_id,
				$note_id,
				Constants::NOTE_TYPE_INTERNAL,
				$note_author,
				$snippet,
				$mentioned_user_ids
			);
		}

		return array(
			'id'                 => $note_id,
			'note'               => $note,
			'created_by_name'    => $note_author['name'],
			'created_at_utc'     => $note_author['created_at'],
			'mentioned_user_ids' => $mentioned_user_ids,
		);
	}

	/**
	 * Fan out notifications to every follower of the update except those
	 * already covered elsewhere (the note author, any user we just queued a
	 * more-specific email to, or anyone who switched Get notifications off
	 * for this thread). Queues both the email and the admin-bar entry.
	 *
	 * @param array{id:int,name:string,created_at:string} $note_author
	 * @param int[]                                       $exclude_user_ids
	 */
	private function queue_participant_notifications(
		int $update_id,
		int $note_id,
		string $note_type,
		array $note_author,
		string $snippet,
		array $exclude_user_ids = array()
	): void {
		if ( ! $this->participant_resolver ) {
			return;
		}

		$actor_id = absint( $note_author['id'] ?? 0 );

		$skip              = array();
		$skip[ $actor_id ] = true;
		foreach ( $exclude_user_ids as $excluded ) {
			$excluded = absint( $excluded );
			if ( $excluded > 0 ) {
				$skip[ $excluded ] = true;
			}
		}

		$update   = $this->order_updates_db->get_update( $update_id );
		$order_id = (int) ( $update['order_id'] ?? 0 );

		foreach ( $this->participant_resolver->ids_for( $update_id ) as $recipient_user_id ) {
			$recipient_user_id = absint( $recipient_user_id );

			if ( $recipient_user_id <= 0 || isset( $skip[ $recipient_user_id ] ) ) {
				continue;
			}

			// The Get-notifications switch mutes BOTH email and admin-bar for
			// the recipient. Without this guard, an opted-out user would still
			// see a row in their admin bar — defeating the toggle.
			if ( StaffEmailPreference::is_muted( $update_id, $recipient_user_id ) ) {
				continue;
			}

			AdminBarNotificationStore::add_participant_reply(
				$update_id,
				$order_id,
				$note_id,
				$snippet,
				$recipient_user_id,
				$note_type,
				(string) ( $note_author['name'] ?? '' )
			);

			$this->async_job->queue(
				Constants::HOOK_PARTICIPANT_UPDATE,
				array(
					'update_id'         => $update_id,
					'recipient_user_id' => $recipient_user_id,
					'note_id'           => $note_id,
					'note_type'         => $note_type,
					'actor_user_id'     => $actor_id,
					'actor_name'        => (string) ( $note_author['name'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Queue one mention email per tagged user (skipping the note author).
	 */
	private function queue_mention_emails( int $update_id, int $note_id, array $mentioned_user_ids, array $note_author ): void {
		foreach ( $mentioned_user_ids as $mentioned_user_id ) {
			$mentioned_user_id = absint( $mentioned_user_id );

			if ( ! $mentioned_user_id || $mentioned_user_id === absint( $note_author['id'] ?? 0 ) ) {
				continue;
			}

			$this->async_job->queue(
				Constants::HOOK_INTERNAL_MENTION,
				array(
					'update_id'         => $update_id,
					'note_id'           => $note_id,
					'recipient_user_id' => $mentioned_user_id,
					'mentioned_by_id'   => absint( $note_author['id'] ?? 0 ),
					'mentioned_by_name' => (string) ( $note_author['name'] ?? '' ),
				)
			);
		}
	}

	/**
	 * Save a customer-visible note on an update.
	 *
	 * Pass $queue_notification = true to also schedule the customer email via
	 * the async job runner. The 'notification_queued' field in the return
	 * confirms whether the queue call succeeded.
	 *
	 * Returns the saved note data; 'id' is 0 when the save failed.
	 *
	 * @return array{id:int,note:string,created_by_name:string,created_at_utc:string,queued_at_utc:string,notification_queued:bool}
	 */
	public function create_customer_note( int $update_id, string $note, bool $queue_notification = false ): array {
		if ( ! $update_id || '' === $note ) {
			return array(
				'id'                  => 0,
				'note'                => '',
				'created_by_name'     => '',
				'created_at_utc'      => '',
				'queued_at_utc'       => '',
				'notification_queued' => false,
			);
		}

		$note_author   = $this->get_current_note_author();
		$note_id       = $this->order_updates_db->create_customer_note(
			$update_id,
			$note,
			$note_author['id'],
			$note_author['name'],
			$note_author['created_at']
		);
		$queued        = false;
		$queued_at_utc = '';

		if ( $note_id && $queue_notification ) {
			$queued = $this->async_job->queue(
				Constants::HOOK_CUSTOMER_NOTIFICATION,
				array(
					'update_id' => $update_id,
					'note_id'   => $note_id,
				)
			);

			if ( $queued ) {
				$queued_at_utc = current_time( 'mysql', true );
				$this->order_updates_db->mark_customer_note_queued( $note_id, $queued_at_utc );
			}
		}

		if ( $note_id ) {
			$snippet = mb_strlen( $note ) > 80 ? mb_substr( $note, 0, 79 ) . '…' : $note;

			// Customer notes don't carry @mentions, so participants are the only
			// staff fan-out path here. The customer themselves is excluded
			// implicitly — they're not a "staff participant" and the customer
			// email is already queued separately above.
			$this->queue_participant_notifications(
				$update_id,
				$note_id,
				Constants::NOTE_TYPE_CUSTOMER,
				$note_author,
				$snippet,
				array()
			);
		}

		return array(
			'id'                  => $note_id,
			'note'                => $note,
			'created_by_name'     => $note_author['name'],
			'created_at_utc'      => $note_author['created_at'],
			'queued_at_utc'       => $queued_at_utc,
			'notification_queued' => $queued,
		);
	}

	/**
	 * Return who's authoring the current staff note — the values that get
	 * stamped on the note row's created_by / created_by_name / created_at.
	 *
	 * Name resolution: first+last name from user meta → display_name → email.
	 *
	 * @return array{id:int,name:string,created_at:string}
	 */
	public function get_current_note_author(): array {
		$current_user = wp_get_current_user();
		$user_id      = (int) $current_user->ID;
		$first_name   = (string) get_user_meta( $user_id, 'first_name', true );
		$last_name    = (string) get_user_meta( $user_id, 'last_name', true );
		$name         = trim( $first_name . ' ' . $last_name );

		if ( '' === $name ) {
			$name = (string) $current_user->display_name;
		}

		if ( '' === $name ) {
			$name = (string) $current_user->user_email;
		}

		return array(
			'id'         => $user_id,
			'name'       => $name,
			'created_at' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Return who's authoring this customer-submitted note — the values that
	 * get stamped on the note row's created_by / created_by_name.
	 *
	 * Logged-in user → real WP user id + their display name.
	 * Guest         → id 0 (marks the row as guest-authored, no user account)
	 *                 plus the order's billing first+last name. Falls back to
	 *                 billing email if name is blank, then to "You" as a last
	 *                 resort if both are missing.
	 *
	 * @return array{id:int,name:string}
	 */
	public function get_note_author_for_customer_submit( WC_Order $order ): array {
		$user_id = get_current_user_id();

		if ( $user_id > 0 ) {
			$author = $this->get_current_note_author();

			return array(
				'id'   => (int) $author['id'],
				'name' => (string) $author['name'],
			);
		}

		$first_name = (string) $order->get_billing_first_name();
		$last_name  = (string) $order->get_billing_last_name();
		$name       = trim( $first_name . ' ' . $last_name );

		if ( '' === $name ) {
			$name = (string) $order->get_billing_email();
		}

		if ( '' === $name ) {
			$name = __( 'You', 'order-updates-for-woo' );
		}

		return array(
			'id'   => 0,
			'name' => $name,
		);
	}
}
