<?php
/**
 * REST endpoint — update update note.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "update update note" REST request.
 */
final class UpdateUpdateNoteEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/notes/(?P<note_id>\d+)';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db   Injected dependency.
	 * @param NoteActionPolicy            $note_action_policy Injected dependency.
	 * @param Validator                   $validator          Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service   Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private NoteActionPolicy $note_action_policy,
		private Validator $validator,
		private OrderUpdatesSettingsService $settings_service
	) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	/**
	 * Permission check for the route.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		// Master toggle — admin opts in from the Restricted Features block.
		if ( ! $this->settings_service->allow_note_edit() ) {
			return new WP_Error(
				'order_updates_for_woo_edit_disabled',
				__( 'Editing notes is disabled.', 'order-updates-for-woo' ),
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
			return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to edit this note.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		// Latest-only rule — newer note in the thread locks this one.
		$latest_id = $this->order_updates_db->get_latest_internal_note_id( absint( $note['update_id'] ?? 0 ) );

		if ( (int) $note['id'] !== $latest_id ) {
			return new WP_Error(
				'order_updates_for_woo_note_locked',
				__( 'This note can no longer be edited — a newer note has been posted.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		if ( ! $this->note_action_policy->can_edit_internal_note( $note, $latest_id ) ) {
			return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You can no longer edit this note.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$note_id   = absint( $request->get_param( 'note_id' ) );
		$update_id = absint( $request->get_param( 'update_id' ) );
		$note_row  = $this->order_updates_db->get_update_note_by_id( $note_id );

		if ( empty( $note_row['id'] ) || absint( $note_row['update_id'] ?? 0 ) !== $update_id ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
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

		$note          = (string) apply_filters( 'order_updates_for_woo_internal_note_payload', $note, $update_id, $request );
		$mentioned_ids = $this->validator->sanitize_mentioned_user_ids( (array) $request->get_param( 'mentioned_user_ids' ) );
		$edited_at     = current_time( 'mysql', true );

		do_action( 'order_updates_for_woo_before_update_internal_note', $note_id, $update_id, $note, $request );

		if ( ! $this->order_updates_db->update_update_note( $note_id, $note, $mentioned_ids, $edited_at ) ) {
			return new WP_Error( 'order_updates_for_woo_note_save_failed', __( 'Could not update the note.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_update_internal_note', $note_id, $update_id, $note, $request );

		$created_by = (int) ( $note_row['created_by'] ?? 0 );

		$response = array(
			'message' => __( 'Note updated.', 'order-updates-for-woo' ),
			'note'    => array(
				'id'                 => $note_id,
				'note'               => $note,
				'created_by'         => $created_by,
				'created_by_name'    => (string) ( $note_row['created_by_name'] ?? '' ),
				'avatar_url'         => $created_by > 0 ? (string) get_avatar_url( $created_by, array( 'size' => 56 ) ) : '',
				'created_at'         => DateHelper::format_date( (string) ( $note_row['created_at'] ?? '' ) ),
				'created_at_utc'     => (string) ( $note_row['created_at'] ?? '' ),
				'edited_at'          => DateHelper::format_date( $edited_at ),
				'edited_at_utc'      => $edited_at,
				'mentioned_user_ids' => $mentioned_ids,
			),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_update_internal_note_response', $response, $request ) );
	}
}
