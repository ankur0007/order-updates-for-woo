<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AddUpdateNoteEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/notes';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private UpdateNoteService $update_note_service,
		private NoteActionPolicy $note_action_policy,
		private Validator $validator,
		private ?AsyncJob $async_job = null
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );

		if (
			$this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) )
			&& TeamRosterService::user_is_team_member()
		) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to add notes to this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$update    = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		if ( UpdateState::is_resolved( $update ) ) {
			return new WP_Error( 'order_updates_for_woo_update_resolved', __( 'This update is already resolved.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		$note = $this->validator->sanitize_note(
			(string) $request->get_param( 'note' ),
			500,
			__( 'Internal note', 'order-updates-for-woo' )
		);

		if ( is_wp_error( $note ) ) {
			return $note;
		}

		if ( '' === $note ) {
			return new WP_Error( 'order_updates_for_woo_empty_note', __( 'Note cannot be empty.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$note = (string) apply_filters( 'order_updates_for_woo_internal_note_payload', $note, $update_id, $request );

		$mentioned_ids = $this->validator->sanitize_mentioned_user_ids( (array) $request->get_param( 'mentioned_user_ids' ) );

		do_action( 'order_updates_for_woo_before_add_internal_note', $update_id, $note, $request );

		$created = $this->update_note_service->create_internal_note( $update_id, $note, $mentioned_ids );

		if ( ! absint( $created['id'] ?? 0 ) ) {
			return new WP_Error( 'order_updates_for_woo_note_save_failed', __( 'Could not save the note.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_add_internal_note', (int) $created['id'], $update_id, $note, $request );

		// Assignee + creator + everyone else following the thread are emailed
		// from UpdateNoteService::queue_participant_notifications (called by
		// create_internal_note). Mentioned users get the dedicated mention
		// email there too. No extra queueing needed here.
		$current_user_id = get_current_user_id();

		$response = array(
			'message' => __( 'Note added.', 'order-updates-for-woo' ),
			'note' => array(
				'id' => (int) $created['id'],
				'note' => $note,
				'created_by' => $current_user_id,
				'created_by_name' => (string) ( $created['created_by_name'] ?? '' ),
				'avatar_url' => $current_user_id > 0 ? (string) get_avatar_url( $current_user_id, array( 'size' => 56 ) ) : '',
				'created_at' => DateHelper::format_date( (string) ( $created['created_at_utc'] ?? '' ) ),
				'created_at_utc' => (string) ( $created['created_at_utc'] ?? '' ),
				'edited_at' => null,
				'edited_at_utc' => null,
				'mentioned_user_ids' => array_map( 'absint', (array) ( $created['mentioned_user_ids'] ?? array() ) ),
				// Run the just-written note through the same edit policy used
				// by GetUpdateNotesEndpoint so the bubble matches the post-
				// reload state. Hardcoding `true` here meant the pencil showed
				// even when the admin had "Allow editing notes" switched off.
				'can_edit' => $this->note_action_policy->can_edit_internal_note(
					array(
						'created_by' => get_current_user_id(),
						'created_at' => (string) ( $created['created_at_utc'] ?? '' ),
					)
				),
				'can_delete' => $this->note_action_policy->can_delete_internal_note(
					array(
						'created_by' => get_current_user_id(),
						'created_at' => (string) ( $created['created_at_utc'] ?? '' ),
					)
				),
			),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_add_internal_note_response', $response, $request ) );
	}
}
