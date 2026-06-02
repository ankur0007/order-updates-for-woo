<?php
/**
 * REST endpoint — get update notes.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "get update notes" REST request.
 */
final class GetUpdateNotesEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/notes';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb   $order_updates_db   Injected dependency.
	 * @param AttachmentsDb    $attachments_db     Injected dependency.
	 * @param NoteActionPolicy $note_action_policy Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private AttachmentsDb $attachments_db,
		private NoteActionPolicy $note_action_policy
	) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::READABLE,
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

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );

		if ( empty( $this->order_updates_db->get_update( $update_id )['id'] ) ) {
			return $this->update_not_found_error();
		}

		// Default to the latest CUSTOMER_NOTES_PAGE_SIZE entries (single
		// constant for both threads). "Load previous" calls the same
		// endpoint with `before_id` set to the oldest visible note id.
		$requested_limit = absint( $request->get_param( 'limit' ) );
		$limit           = max( 1, min( 50, $requested_limit > 0 ? $requested_limit : Constants::CUSTOMER_NOTES_PAGE_SIZE ) );
		$around_id       = absint( $request->get_param( 'around_id' ) );

		// `around_id` is the deep-link jump: a window centred on the target
		// note (older + note + newer) in one query, instead of paging back.
		$paged = $around_id > 0
			? $this->order_updates_db->get_update_notes_around( $update_id, $around_id )
			: $this->order_updates_db->get_update_notes_paged( $update_id, $limit, absint( $request->get_param( 'before_id' ) ) );

		$latest_id = $this->order_updates_db->get_latest_internal_note_id( $update_id );

		$notes = array_map(
			function ( array $note ) use ( $latest_id ): array {
				$mention_ids = array_map( 'absint', (array) ( $note['mentioned_user_ids'] ?? array() ) );
				$created_by  = (int) ( $note['created_by'] ?? 0 );

				return array(
					'id'                 => (int) $note['id'],
					'note'               => (string) $note['note'],
					'created_by'         => $created_by,
					'created_by_name'    => (string) $note['created_by_name'],
					'avatar_url'         => $created_by > 0 ? (string) get_avatar_url( $created_by, array( 'size' => 56 ) ) : '',
					'created_at'         => DateHelper::format_date( (string) $note['created_at'] ),
					'created_at_utc'     => (string) $note['created_at'],
					'edited_at'          => ! empty( $note['edited_at'] ) ? DateHelper::format_date( (string) $note['edited_at'] ) : null,
					'edited_at_utc'      => ! empty( $note['edited_at'] ) ? (string) $note['edited_at'] : null,
					'attachments'        => AttachmentPresenter::format_many(
						$this->attachments_db->get_for_note( (int) $note['id'], Constants::NOTE_TYPE_INTERNAL )
					),
					'mentioned_user_ids' => $mention_ids,
					'mentions'           => $this->lookup_mention_display_names( $mention_ids ),
					'can_edit'           => $this->note_action_policy->can_edit_internal_note( $note, $latest_id ),
					'can_delete'         => $this->note_action_policy->can_delete_internal_note( $note, $latest_id ),
				);
			},
			$paged['notes'] 
		);

		$response = array(
			'notes'     => $notes,
			'has_more'  => (bool) $paged['has_more'],
			'has_newer' => ! empty( $paged['has_newer'] ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_get_update_notes_response', $response, $update_id, $request ) );
	}

	/**
	 * Turn a list of user IDs into { id, display_name } pairs for the
	 * mention chips rendered alongside internal notes. Deleted or
	 * unknown user IDs fall back to "#{id}" so the UI still shows
	 * something rather than silently dropping the chip.
	 *
	 * @param int[] $user_ids Mentioned user ids.
	 * @return array<int, array{id:int,name:string}>
	 */
	private function lookup_mention_display_names( array $user_ids ): array {
		$user_ids = array_values( array_unique( array_filter( array_map( 'absint', $user_ids ) ) ) );

		if ( empty( $user_ids ) ) {
			return array();
		}

		$users = get_users(
			array(
				'include' => $user_ids,
				'fields'  => array( 'ID', 'display_name' ),
			) 
		);

		$display_name_by_user_id = array();

		foreach ( $users as $user ) {
			$display_name_by_user_id[ (int) $user->ID ] = (string) $user->display_name;
		}

		$mentions = array();

		foreach ( $user_ids as $user_id ) {
			$mentions[] = array(
				'id'   => $user_id,
				'name' => $display_name_by_user_id[ $user_id ] ?? '#' . $user_id,
			);
		}

		return $mentions;
	}
}
