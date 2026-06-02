<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\CustomerNotePresenter;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Updates\NoteActionPolicy;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class GetCustomerNotesEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/customer-notes';

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

		if ( $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) ) ) {
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

		// Default to the latest CUSTOMER_NOTES_PAGE_SIZE entries; "Load
		// previous" calls the same endpoint with `before_id` set to the
		// oldest visible note to step back another page.
		$limit     = max( 1, min( 50, absint( $request->get_param( 'limit' ) ?: Constants::CUSTOMER_NOTES_PAGE_SIZE ) ) );
		$around_id = absint( $request->get_param( 'around_id' ) );

		// `around_id` is the deep-link jump: a window centred on the target
		// note (older + note + newer) in one query, instead of paging back.
		$paged = $around_id > 0
			? $this->order_updates_db->get_customer_notes_around( $update_id, $around_id )
			: $this->order_updates_db->get_customer_notes_paged( $update_id, $limit, absint( $request->get_param( 'before_id' ) ) );

		$latest_id = $this->order_updates_db->get_latest_customer_note_id( $update_id );

		$notes = array_map(
			fn( array $note ) => CustomerNotePresenter::format_for_admin(
				$note,
				$this->note_action_policy,
				$this->attachments_db,
				$latest_id
			),
			$paged['notes']
		);

		$update      = $this->order_updates_db->get_update( $update_id );
		$order       = wc_get_order( absint( $update['order_id'] ?? 0 ) );
		$customer_id = $order instanceof WC_Order ? (int) $order->get_customer_id() : 0;
		$order_id    = $order instanceof WC_Order ? (int) $order->get_id() : 0;

		$response = array(
			'notes'                       => $notes,
			'has_more'                    => (bool) $paged['has_more'],
			'has_newer'                   => ! empty( $paged['has_newer'] ),
			'order_id'                    => $order_id,
			'email_notifications_enabled' => CustomerEmailPreference::get( $order_id, $customer_id ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_get_customer_notes_response', $response, $update_id, $request ) );
	}
}
