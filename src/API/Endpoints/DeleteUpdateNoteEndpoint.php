<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class DeleteUpdateNoteEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/notes/(?P<note_id>\d+)';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private NoteActionPolicy $note_action_policy,
		private OrderUpdatesSettingsService $settings_service
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		// Master toggle — admin opts in from the Restricted Features block.
		if ( ! $this->settings_service->allow_note_delete() ) {
			return new WP_Error(
				'order_updates_for_woo_delete_disabled',
				__( 'Deleting notes is disabled.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$note = $this->order_updates_db->get_update_note_by_id( absint( $request->get_param( 'note_id' ) ) );

		if ( empty( $note['id'] ) ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		if ( absint( $note['update_id'] ?? 0 ) !== absint( $request->get_param( 'update_id' ) ) ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		$update = $this->order_updates_db->get_update( absint( $note['update_id'] ?? 0 ) );

		if (
			! $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) )
			|| ! TeamRosterService::user_is_team_member()
		) {
			return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to delete this note.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		// Latest-only — newer note in the thread locks this one.
		$latest_id = $this->order_updates_db->get_latest_internal_note_id( absint( $note['update_id'] ?? 0 ) );

		if ( (int) $note['id'] !== $latest_id ) {
			return new WP_Error(
				'order_updates_for_woo_note_locked',
				__( 'This note can no longer be deleted — a newer note has been posted.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->note_action_policy->can_delete_internal_note( $note, $latest_id ) ) {
			return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You can no longer delete this note.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$note_id   = absint( $request->get_param( 'note_id' ) );
		$update_id = absint( $request->get_param( 'update_id' ) );
		$note      = $this->order_updates_db->get_update_note_by_id( $note_id );

		if ( empty( $note['id'] ) || absint( $note['update_id'] ?? 0 ) !== $update_id ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		do_action( 'order_updates_for_woo_before_delete_internal_note', $note_id, $update_id, $note, $request );

		if ( ! $this->order_updates_db->delete_update_note( $note_id ) ) {
			return new WP_Error( 'order_updates_for_woo_delete_failed', __( 'Could not delete the note.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_delete_internal_note', $note_id, $update_id, $note, $request );

		return rest_ensure_response(
			apply_filters(
				'order_updates_for_woo_delete_internal_note_response',
				array(
					'message' => __( 'Note deleted.', 'order-updates-for-woo' ),
					'noteId'  => $note_id,
				),
				$request
			)
		);
	}
}
