<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\RendersCardHtml;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ReopenUpdateEndpoint implements Registrable {
	use VerifiesAccess;
	use RendersCardHtml;

	private const ROUTE = '/updates/(?P<update_id>\d+)/reopen';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private OrderUpdatesSettingsService $settings_service,
		private UpdateCardVariableParser $update_card_variable_parser,
		private CustomerOrderUpdatesService $viewer_service
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

		$update   = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );
		$order_id = absint( $update['order_id'] ?? 0 );

		if ( $this->is_authorized_for_order( $order_id ) ) {
			return true;
		}

		// Customer-acting path — the "Still has issue?" button on the
		// customer-facing thread hits this endpoint with the order_key so a
		// guest/logged-in customer can re-open their own unrated update
		// without staff capabilities.
		$order_key_raw = (string) $request->get_param( 'order_key' );
		$order_key     = '' !== $order_key_raw ? sanitize_text_field( wp_unslash( $order_key_raw ) ) : null;

		if ( $order_id && $this->viewer_service->is_acting_as_customer( $order_id, $order_key ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to re-open this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id = absint( $request->get_param( 'update_id' ) );
		$update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		if ( ! UpdateState::is_resolved( $update ) ) {
			return new WP_Error( 'order_updates_for_woo_not_solved', __( 'This update is not resolved.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		$rating = $this->order_updates_db->get_rating_for_update( $update_id );

		if ( ! empty( $rating['created_at'] ) ) {
			return new WP_Error( 'order_updates_for_woo_already_rated', __( 'This update has been rated and cannot be re-opened.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		do_action( 'order_updates_for_woo_before_reopen_update', $update_id, $update, $request );

		$saved = $this->order_updates_db->mark_as_unsolved( $update_id, get_current_user_id() );

		if ( ! $saved ) {
			return new WP_Error( 'order_updates_for_woo_save_failed', __( 'Could not save the order update.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		// Reopens were previously inferred from "is_resolved is false but
		// solved_at exists" — that lost any solve→reopen→solve cycle. Log an
		// explicit row so the tracking log captures every reopen, not just
		// the most recent state.
		$this->order_updates_db->log_lifecycle_event(
			$update_id,
			'reopen',
			__( 'Re-opened', 'order-updates-for-woo' ),
			get_current_user_id(),
			current_time( 'mysql', true )
		);

		// Wipe the rating-request row so RatingRequestScheduler queues a
		// fresh "How did we do?" email when the update is solved again. The
		// "already rated" gate above means we never delete a real rating
		// here — only an unanswered request row.
		$this->order_updates_db->clear_rating_request( $update_id );

		$reopened_update = $this->order_updates_db->get_update( $update_id );

		do_action( 'order_updates_for_woo_after_reopen_update', $update_id, $reopened_update, $request );

		$response = array(
			'message' => __( 'Update re-opened.', 'order-updates-for-woo' ),
			'updateId' => $update_id,
			'cardHtml' => $this->render_card_html( $reopened_update ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_reopen_update_response', $response, $request ) );
	}
}
