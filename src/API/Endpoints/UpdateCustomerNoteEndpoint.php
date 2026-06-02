<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\DateHelper;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class UpdateCustomerNoteEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes/(?P<note_id>\d+)';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $viewer_service,
		private NoteActionPolicy $note_action_policy,
		private Validator $validator,
		private OrderUpdatesSettingsService $settings_service
	) {}

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

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		// Master toggle — admin opts in from the Restricted Features block.
		// Off by default; the endpoint stays registered so callers get a
		// stable 403 with a clear code instead of a route-not-found.
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

		$note = $this->order_updates_db->get_customer_note_by_id( absint( $request->get_param( 'note_id' ) ) );

		if ( empty( $note['id'] ) || absint( $note['update_id'] ?? 0 ) !== absint( $request->get_param( 'update_id' ) ) ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Customer note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		// Latest-only rule: if any newer note exists in this thread, the
		// current note is locked. Reported with a dedicated code so the
		// client can surface "this message has been locked by a follow-up"
		// instead of the generic forbidden message.
		$latest_id = $this->order_updates_db->get_latest_customer_note_id( absint( $note['update_id'] ?? 0 ) );

		if ( (int) $note['id'] !== $latest_id ) {
			return new WP_Error(
				'order_updates_for_woo_note_locked',
				__( 'This message can no longer be edited — a newer reply has been posted.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		// Check the edit window before the auth check so an expired window
		// returns its own clear error instead of a generic "forbidden".
		if ( ! $this->note_action_policy->is_within_customer_note_edit_window( $note ) ) {
			return new WP_Error(
				'order_updates_for_woo_edit_window_expired',
				__( 'You are no longer able to edit this message.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		if ( $this->can_manage_customer_note_as_member( $note, $latest_id ) ) {
			return true;
		}

		if ( $this->can_manage_customer_note_as_customer( $note, $request, $latest_id ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to edit this customer note.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$note_id   = absint( $request->get_param( 'note_id' ) );
		$update_id = absint( $request->get_param( 'update_id' ) );
		$note_row  = $this->order_updates_db->get_customer_note_by_id( $note_id );

		if ( empty( $note_row['id'] ) || absint( $note_row['update_id'] ?? 0 ) !== $update_id ) {
			return new WP_Error( 'order_updates_for_woo_invalid_note', __( 'Customer note not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
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
			return new WP_Error( 'order_updates_for_woo_empty_note', __( 'Customer note is required.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$note      = (string) apply_filters( 'order_updates_for_woo_customer_note_payload', $note, $update_id, $request );
		$edited_at = current_time( 'mysql', true );
		$prior     = (string) ( $note_row['note'] ?? '' );

		// Skip the work entirely on no-op edits — also avoids creating an
		// empty history row that just clutters the audit trail.
		if ( $prior === $note ) {
			return rest_ensure_response(
				array(
					'message' => __( 'No changes to save.', 'order-updates-for-woo' ),
					'note'    => $this->presentation( $note_row, $note, null ),
				) 
			);
		}

		do_action( 'order_updates_for_woo_before_update_customer_note', $note_id, $update_id, $note, $request );

		$editor   = $this->get_note_editor_identity( $note_row );
		$archived = $this->order_updates_db->archive_customer_note_revision(
			$note_id,
			$prior,
			$editor['id'],
			$editor['name'],
			$edited_at
		);

		if ( ! $archived ) {
			return new WP_Error( 'order_updates_for_woo_history_failed', __( 'Could not save edit history. Please try again.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		if ( ! $this->order_updates_db->update_customer_note( $note_id, $note, $edited_at ) ) {
			return new WP_Error( 'order_updates_for_woo_note_save_failed', __( 'Could not update the customer note.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_update_customer_note', $note_id, $update_id, $note, $request );

		$response = array(
			'message' => __( 'Customer note updated.', 'order-updates-for-woo' ),
			'note'    => $this->presentation( $note_row, $note, $edited_at ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_update_customer_note_response', $response, $request ) );
	}

	private function presentation( array $note_row, string $current_text, ?string $edited_at ): array {
		$created_by = (int) ( $note_row['created_by'] ?? 0 );

		return array(
			'id'              => (int) $note_row['id'],
			'note'            => $current_text,
			'created_by'      => $created_by,
			'created_by_name' => (string) ( $note_row['created_by_name'] ?? '' ),
			'avatar_url'      => $created_by > 0 ? (string) get_avatar_url( $created_by, array( 'size' => 56 ) ) : '',
			'created_at'      => DateHelper::format_date( (string) ( $note_row['created_at'] ?? '' ) ),
			'created_at_utc'  => (string) ( $note_row['created_at'] ?? '' ),
			'edited_at'       => null !== $edited_at ? DateHelper::format_date( $edited_at ) : (
				! empty( $note_row['edited_at'] ) ? DateHelper::format_date( (string) $note_row['edited_at'] ) : null
			),
			'edited_at_utc'   => null !== $edited_at ? $edited_at : (
				! empty( $note_row['edited_at'] ) ? (string) $note_row['edited_at'] : null
			),
			'queued_at'       => ! empty( $note_row['queued_at'] ) ? DateHelper::format_date( (string) $note_row['queued_at'] ) : null,
			'queued_at_utc'   => ! empty( $note_row['queued_at'] ) ? (string) $note_row['queued_at'] : null,
			'notified_at'     => ! empty( $note_row['notified_at'] ) ? DateHelper::format_date( (string) $note_row['notified_at'] ) : null,
			'notified_at_utc' => ! empty( $note_row['notified_at'] ) ? (string) $note_row['notified_at'] : null,
		);
	}

	/**
	 * Identify whoever is performing the edit. Logged-in users (staff or
	 * registered customers) get their WP user id + display name. Guest
	 * customers carry no identity, so fall back to the original author's name
	 * — that way the history row reads "edited by {name}" instead of
	 * mysteriously empty.
	 */
	private function get_note_editor_identity( array $note_row ): array {
		$user_id = get_current_user_id();

		if ( $user_id ) {
			$user = get_userdata( $user_id );
			$name = $user instanceof \WP_User ? (string) $user->display_name : '';

			if ( '' === $name ) {
				$name = (string) ( $note_row['created_by_name'] ?? '' );
			}

			return array(
				'id'   => $user_id,
				'name' => $name,
			);
		}

		return array(
			'id'   => 0,
			'name' => (string) ( $note_row['created_by_name'] ?? '' ),
		);
	}

	private function can_manage_customer_note_as_member( array $note, int $latest_note_id ): bool {
		$update   = $this->order_updates_db->get_update( absint( $note['update_id'] ?? 0 ) );
		$order_id = absint( $update['order_id'] ?? 0 );

		if ( ! $this->is_authorized_for_order( $order_id ) ) {
			return false;
		}

		return $this->note_action_policy->can_edit_member_customer_note( $note, $latest_note_id );
	}

	private function can_manage_customer_note_as_customer( array $note, WP_REST_Request $request, int $latest_note_id ): bool {
		$update    = $this->order_updates_db->get_update( absint( $note['update_id'] ?? 0 ) );
		$order_id  = absint( $update['order_id'] ?? 0 );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		if ( ! $this->viewer_service->is_acting_as_customer( $order_id, $order_key ) ) {
			return false;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return false;
		}

		$customer_id = (int) $order->get_customer_id();
		$is_guest    = 0 === get_current_user_id();

		return $this->note_action_policy->can_edit_customer_authored_note( $note, $customer_id, $is_guest, $latest_note_id );
	}
}
