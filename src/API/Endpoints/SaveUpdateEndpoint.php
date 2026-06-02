<?php
/**
 * REST endpoint — save update.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\RendersCardHtml;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\UpdateAuthorHelper;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use OrderUpdatesForWoo\Shared\Updates\UpdateNoteService;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "save update" REST request.
 */
final class SaveUpdateEndpoint implements Registrable {
	use VerifiesAccess;
	use RendersCardHtml;

	private const ROUTE = '/updates';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db            Injected dependency.
	 * @param Validator                   $validator                   Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service            Injected dependency.
	 * @param UpdateCardVariableParser    $update_card_variable_parser Injected dependency.
	 * @param UpdateNoteService           $update_note_service         Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private Validator $validator,
		private OrderUpdatesSettingsService $settings_service,
		private UpdateCardVariableParser $update_card_variable_parser,
		private UpdateNoteService $update_note_service
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

		$order_id = absint( $request->get_param( 'order_id' ) );

		if ( ! $order_id || ! wc_get_order( $order_id ) ) {
			return $this->order_not_found_error();
		}

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to save order updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		// Status is the source of truth — admin's configured list owns it.
		// Resolve the picked key to its color now so the rest of the save
		// path (validation, DB write, cache bust) sees both in sync.
		$status_key   = sanitize_key( (string) $request->get_param( 'status' ) );
		$resolved     = $this->resolve_status_color( $status_key );
		$status_color = $resolved['color'];
		$status_key   = $resolved['key'];

		$validated = $this->validator->validate_update_payload(
			array(
				'update_id'     => $request->get_param( 'update_id' ),
				'order_id'      => $request->get_param( 'order_id' ),
				'title'         => $request->get_param( 'title' ),
				'internal_note' => $request->get_param( 'internal_note' ),
				'customer_note' => $request->get_param( 'customer_note' ),
				'color'         => $status_color,
				'assignee_id'   => $request->get_param( 'assignee_id' ),
			) 
		);

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$is_edit  = ! empty( $validated['update_id'] );
		$existing = array();

		if ( $is_edit ) {
			$existing = $this->order_updates_db->get_update( $validated['update_id'] );

			if ( empty( $existing['id'] ) || absint( $existing['order_id'] ) !== $validated['order_id'] ) {
				return $this->update_not_found_error();
			}

			// A resolved update is locked — re-open it before editing its
			// title, status or assignee.
			if ( ! empty( $existing['is_resolved'] ) ) {
				return new WP_Error(
					'order_updates_for_woo_update_resolved',
					__( 'This update is resolved. Re-open it before making changes.', 'order-updates-for-woo' ),
					array( 'status' => 409 )
				);
			}
		}

		$user_id = get_current_user_id();
		$now     = current_time( 'mysql', true );

		// Any staff member with the order cap can edit any update (title,
		// status, assignee). No creator-only gate — same model as Zendesk.

		// customer_visible defaults to 0 on CREATE and auto-flips to 1 the
		// moment a customer-facing note is added. On EDIT, keep the existing
		// value so editing title/status/assignee doesn't reset visibility.
		$update_data = apply_filters(
			'order_updates_for_woo_update_data',
			array(
				'order_id'         => $validated['order_id'],
				'title'            => $validated['title'],
				'customer_visible' => $is_edit ? (int) ( $existing['customer_visible'] ?? 0 ) : 0,
				'status'           => $status_key,
				'color'            => $validated['color'],
				'created_by'       => $is_edit ? absint( $existing['created_by'] ?? $user_id ) : $user_id,
				'created_at'       => $is_edit ? (string) ( $existing['created_at'] ?? $now ) : $now,
				'last_updated_by'  => $user_id,
				'last_updated_at'  => $now,
			),
			$validated,
			$request 
		);

		do_action( 'order_updates_for_woo_before_update_save', $validated, $update_data, $request );

		if ( $is_edit ) {
			$update_saved = $this->order_updates_db->edit_order_update( $validated['update_id'], $update_data );
			$update_id    = $validated['update_id'];
		} else {
			$update_id    = $this->order_updates_db->create_order_update( $update_data );
			$update_saved = (bool) $update_id;
		}

		if ( ! $update_saved || ! $update_id ) {
			return new WP_Error( 'order_updates_for_woo_save_failed', __( 'Could not save the order update.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		$saved_payload = $validated;

		// Admin-side update creation always honours the manually-picked
		// assignee. The rotation helper is reserved for customer-initiated
		// submissions where there is no human to pick — staff using the
		// order-edit form are expected to assign deliberately.
		$assignee_saved = $this->order_updates_db->sync_assignee( $update_id, (int) $validated['assignee_id'], $user_id, $now );

		if ( ! $assignee_saved ) {
			$saved_payload['assignee_id'] = 0;
		}

		$mentioned_ids       = $this->validator->sanitize_mentioned_user_ids( (array) $request->get_param( 'mentioned_user_ids' ) );
		$created_note        = $this->update_note_service->create_internal_note(
			$update_id,
			(string) ( $validated['internal_note'] ?? '' ),
			$mentioned_ids
		);
		$created_note_id     = absint( $created_note['id'] ?? 0 );
		$customer_note_id    = 0;
		$notification_queued = false;

		if ( ! $is_edit ) {
			$customer_note       = $this->update_note_service->create_customer_note( $update_id, (string) ( $validated['customer_note'] ?? '' ), true );
			$customer_note_id    = absint( $customer_note['id'] ?? 0 );
			$notification_queued = ! empty( $customer_note['notification_queued'] );
		}

		do_action( 'order_updates_for_woo_after_update_save', $update_id, $saved_payload, $update_data, $request, $existing );

		$updated_record = $this->order_updates_db->get_update( $update_id );

		$message = $is_edit
			? __( 'Update edited successfully.', 'order-updates-for-woo' )
			: ( $customer_note_id && ! $notification_queued
				? __( 'Update saved, but the customer notification could not be queued.', 'order-updates-for-woo' )
				: __( 'Update saved successfully.', 'order-updates-for-woo' ) );

		$response = array(
			'message'                    => $message,
			'updateId'                   => $update_id,
			'isEdit'                     => $is_edit,
			'cardHtml'                   => $this->render_card_html( $updated_record ),
			'noteId'                     => $created_note_id ? $created_note_id : null,
			'customerNoteId'             => $customer_note_id ? $customer_note_id : null,
			'customerNotificationQueued' => $notification_queued,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_save_update_response', $response, $updated_record, $request ) );
	}

	/**
	 * Look up a status row by key and return both its canonical key and the
	 * color the admin associated with it. Falls back to the first status in
	 * the list when the submitted key isn't recognised — the dropdown always
	 * sends a valid one in normal use, but a stale form or addon-driven save
	 * shouldn't break with a blank color.
	 *
	 * @param string $key Submitted status key.
	 * @return array{key:string, color:string}
	 */
	private function resolve_status_color( string $key ): array {
		$statuses = $this->settings_service->get_statuses();

		foreach ( $statuses as $status ) {
			if ( sanitize_key( (string) ( $status['key'] ?? '' ) ) === $key ) {
				return array(
					'key'   => (string) $status['key'],
					'color' => (string) $status['color'],
				);
			}
		}

		$fallback = $statuses[0] ?? array(
			'key'   => '',
			'color' => Constants::STATUS_FALLBACK_COLOR,
		);

		return array(
			'key'   => (string) ( $fallback['key'] ?? '' ),
			'color' => (string) ( $fallback['color'] ?? Constants::STATUS_FALLBACK_COLOR ),
		);
	}
}
