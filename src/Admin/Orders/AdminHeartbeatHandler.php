<?php
/**
 * Heartbeat handler that feeds live order-update changes to the editor panel.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Orders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Helpers\CustomerNotePresenter;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;

/**
 * Piggybacks on the WP Heartbeat to push new notes to logged-in staff without
 * adding a dedicated polling loop. One heartbeat tick aggregates all open
 * update threads, so N open cards = 1 HTTP request, not N.
 *
 * Handles both customer thread notes (since_map) and internal notes
 * (since_internal_map) in the same request.
 */
final class AdminHeartbeatHandler {
	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb   $order_updates_db Injected dependency.
	 * @param NoteActionPolicy $note_action_policy Injected dependency.
	 * @param AttachmentsDb    $attachments_db Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private NoteActionPolicy $note_action_policy,
		private AttachmentsDb $attachments_db
	) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_filter( 'heartbeat_received', array( $this, 'handle' ), 10, 2 );
	}

	/**
	 * Attach changed update cards to the heartbeat response for the open order.
	 *
	 * @param array $response Heartbeat response being built.
	 * @param array $data     Data sent by the JS heartbeat-send event.
	 */
	public function handle( array $response, array $data ): array {
		if ( empty( $data[ Constants::HEARTBEAT_KEY ] ) || ! is_array( $data[ Constants::HEARTBEAT_KEY ] ) ) {
			return $response;
		}

		if ( ! current_user_can( 'edit_shop_orders' ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return $response;
		}

		$payload                  = $data[ Constants::HEARTBEAT_KEY ];
		$since_map                = isset( $payload['since'] ) && is_array( $payload['since'] ) ? $payload['since'] : array();
		$since_internal_map       = isset( $payload['since_internal'] ) && is_array( $payload['since_internal'] ) ? $payload['since_internal'] : array();
		$notes_by_update          = array();
		$internal_notes_by_update = array();

		// Customer thread notes.
		foreach ( $since_map as $update_id_raw => $since_note_id_raw ) {
			$update_id     = absint( $update_id_raw );
			$since_note_id = absint( $since_note_id_raw );

			if ( ! $update_id ) {
				continue;
			}

			$update   = $this->order_updates_db->get_update( $update_id );
			$order_id = absint( $update['order_id'] ?? 0 );

			if ( ! $order_id ) {
				continue;
			}

			if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'edit_post', $order_id ) ) {
				continue;
			}

			$raw_notes = $this->order_updates_db->get_customer_notes_since_id( $update_id, $since_note_id );

			if ( empty( $raw_notes ) ) {
				continue;
			}

			$latest_id = $this->order_updates_db->get_latest_customer_note_id( $update_id );

			$notes_by_update[ $update_id ] = array_map(
				fn( array $note ) => CustomerNotePresenter::format_for_admin(
					$note,
					$this->note_action_policy,
					$this->attachments_db,
					$latest_id
				),
				$raw_notes
			);
		}

		// Internal notes (mentions + general thread updates).
		foreach ( $since_internal_map as $update_id_raw => $since_note_id_raw ) {
			$update_id     = absint( $update_id_raw );
			$since_note_id = absint( $since_note_id_raw );

			if ( ! $update_id ) {
				continue;
			}

			$update   = $this->order_updates_db->get_update( $update_id );
			$order_id = absint( $update['order_id'] ?? 0 );

			if ( ! $order_id ) {
				continue;
			}

			if ( ! current_user_can( 'edit_shop_order', $order_id ) && ! current_user_can( 'edit_post', $order_id ) ) {
				continue;
			}

			$raw_notes = $this->order_updates_db->get_update_notes_since_id( $update_id, $since_note_id );

			if ( empty( $raw_notes ) ) {
				continue;
			}

			$latest_internal_id = $this->order_updates_db->get_latest_internal_note_id( $update_id );

			$internal_notes_by_update[ $update_id ] = array_map(
				fn( array $note ) => $this->format_internal_note( $note, $latest_internal_id ),
				$raw_notes
			);
		}

		$result = array();

		if ( ! empty( $notes_by_update ) ) {
			$result['notes_by_update'] = $notes_by_update;
		}

		if ( ! empty( $internal_notes_by_update ) ) {
			$result['internal_notes_by_update'] = $internal_notes_by_update;
		}

		// Update-level state sync: report each watched update's last-changed
		// time so an open card on another teammate's screen can refresh itself
		// when someone changes the status, title, assignee, or solves/reopens
		// it — no notification or page reload needed. We only report updates
		// that still exist and have a known last-changed time. A deleted update
		// is left off the list (its card clears on the next page load); the card
		// is never removed from a heartbeat signal.
		$state_by_update = array();
		foreach ( array_unique( array_merge( array_keys( $since_map ), array_keys( $since_internal_map ) ) ) as $update_id_raw ) {
			$update_id = absint( $update_id_raw );
			if ( ! $update_id ) {
				continue;
			}
			$update = $this->order_updates_db->get_update( $update_id );
			if ( empty( $update ) ) {
				continue;
			}
			$last_changed = (string) ( $update['last_updated_at'] ?? '' );
			if ( '' === $last_changed ) {
				continue;
			}
			$state_by_update[ $update_id ] = $last_changed;
		}
		if ( ! empty( $state_by_update ) ) {
			$result['state_by_update'] = $state_by_update;
		}

		if ( ! empty( $result ) ) {
			$response[ Constants::HEARTBEAT_KEY ] = apply_filters(
				'order_updates_for_woo_heartbeat_notes_response',
				$result,
				$since_map
			);
		}

		return $response;
	}

	/**
	 * Shape one internal note row for the heartbeat payload.
	 *
	 * @param array $note           Internal note row.
	 * @param int   $latest_note_id Highest note id in the thread (for edit/delete gates).
	 */
	private function format_internal_note( array $note, int $latest_note_id = 0 ): array {
		$mention_ids = array_map( 'absint', (array) ( $note['mentioned_user_ids'] ?? array() ) );
		$created_by  = (int) ( $note['created_by'] ?? 0 );

		return array(
			'id'                 => (int) $note['id'],
			'note'               => (string) $note['note'],
			'created_by'         => $created_by,
			'created_by_name'    => (string) $note['created_by_name'],
			'avatar_url'         => $created_by > 0 ? (string) get_avatar_url( $created_by, array( 'size' => 56 ) ) : '',
			'created_at'         => DateHelper::format_date( (string) $note['created_at'] ),
			'edited_at'          => ! empty( $note['edited_at'] ) ? DateHelper::format_date( (string) $note['edited_at'] ) : null,
			'attachments'        => AttachmentPresenter::format_many(
				$this->attachments_db->get_for_note( (int) $note['id'], Constants::NOTE_TYPE_INTERNAL )
			),
			'mentioned_user_ids' => $mention_ids,
			'mentions'           => $this->lookup_mention_display_names( $mention_ids ),
			'can_edit'           => $this->note_action_policy->can_edit_internal_note( $note, $latest_note_id ),
			'can_delete'         => $this->note_action_policy->can_delete_internal_note( $note, $latest_note_id ),
		);
	}

	/**
	 * Turn a list of user IDs into { id, display_name } pairs for the
	 * mention chips rendered next to internal notes. Skips IDs that no
	 * longer match a real user (e.g. a member was deleted after being
	 * tagged).
	 *
	 * @param int[] $user_ids Tagged user ids.
	 * @return array<int, array{id:int,name:string}>
	 */
	private function lookup_mention_display_names( array $user_ids ): array {
		$mentions = array();

		foreach ( $user_ids as $user_id ) {
			$user_id = absint( $user_id );
			$user    = $user_id ? get_user_by( 'id', $user_id ) : false;

			if ( $user ) {
				$mentions[] = array(
					'id'   => $user_id,
					'name' => $user->display_name,
				);
			}
		}

		return $mentions;
	}
}
