<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class AddCustomerNoteEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private UpdateNoteService $update_note_service,
		private NoteActionPolicy $note_action_policy,
		private Validator $validator,
		private ?AsyncJob $async_job = null
	) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
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

		if ( $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to add customer notes to this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
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
			__( 'Customer note', 'order-updates-for-woo' )
		);

		if ( is_wp_error( $note ) ) {
			return $note;
		}

		if ( '' === $note ) {
			return new WP_Error( 'order_updates_for_woo_empty_note', __( 'Customer note is required when the update is visible to customer.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$note = (string) apply_filters( 'order_updates_for_woo_customer_note_payload', $note, $update_id, $request );

		do_action( 'order_updates_for_woo_before_add_customer_note', $update_id, $note, $request );

		// create_customer_note auto-flips customer_visible to 1 on insert.
		// The endpoint just reports whether this note was the trigger so the
		// admin UI can stop rendering the "Hidden from customer" notice.
		$visibility_changed = ! UpdateState::is_customer_visible( $update );

		$created = $this->update_note_service->create_customer_note( $update_id, $note );

		if ( ! absint( $created['id'] ?? 0 ) ) {
			return new WP_Error( 'order_updates_for_woo_note_save_failed', __( 'Could not save the customer note.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_add_customer_note', (int) $created['id'], $update_id, $note, $request );

		// Auto-email the customer unless they've turned off the email option
		// on their portal. `CustomerEmailPreference::get` reads from user-meta
		// (logged-in) or order-meta (guest).
		$order_id      = (int) ( $update['order_id'] ?? 0 );
		$order         = $order_id && function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		$customer_id   = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;
		$queued_at_utc = '';

		if ( $order instanceof WC_Order && $this->async_job && CustomerEmailPreference::get( $order_id, $customer_id ) ) {
			$queued = $this->async_job->queue(
				Constants::HOOK_CUSTOMER_NOTIFICATION,
				array(
					'update_id' => $update_id,
					'note_id'   => (int) $created['id'],
				)
			);

			if ( $queued ) {
				$queued_at_utc = current_time( 'mysql', true );
				$this->order_updates_db->mark_customer_note_queued( (int) $created['id'], $queued_at_utc );
			}
		}

		// Assignee + creator + everyone else following the thread are emailed
		// from UpdateNoteService::queue_participant_notifications (called by
		// create_customer_note). No extra queueing needed here.
		$current_user_id = get_current_user_id();

		$response = array(
			'message'                  => __( 'Customer note added.', 'order-updates-for-woo' ),
			'customer_visible_changed' => $visibility_changed,
			'note'                     => array(
				'id'              => (int) $created['id'],
				'note'            => $note,
				'created_by'      => $current_user_id,
				'created_by_name' => (string) ( $created['created_by_name'] ?? '' ),
				'avatar_url'      => $current_user_id > 0 ? (string) get_avatar_url( $current_user_id, array( 'size' => 56 ) ) : '',
				'created_at'      => DateHelper::format_date( (string) ( $created['created_at_utc'] ?? '' ) ),
				'created_at_utc'  => (string) ( $created['created_at_utc'] ?? '' ),
				'edited_at'       => null,
				'edited_at_utc'   => null,
				'notified_at'     => null,
				'queued_at'       => '' !== $queued_at_utc ? DateHelper::format_date( $queued_at_utc ) : null,
				'queued_at_utc'   => '' !== $queued_at_utc ? $queued_at_utc : null,
				// Match the post-reload policy — when the admin has "Allow
				// editing notes" off, no pencil. Hardcoding `true` made the
				// pencil show on the just-written bubble regardless.
				'can_edit'        => $this->note_action_policy->can_edit_member_customer_note(
					array(
						'created_by' => $current_user_id,
						'created_at' => (string) ( $created['created_at_utc'] ?? '' ),
					)
				),
			),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_add_customer_note_response', $response, $request ) );
	}
}
