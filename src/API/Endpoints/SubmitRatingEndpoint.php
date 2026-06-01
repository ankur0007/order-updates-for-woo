<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Helpers\UpdateState;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Customer-submitted rating for a resolved update.
 *
 * Auth uses the same order_key + login pattern as {@see SubmitCustomerUpdateEndpoint}
 * so guests linked from notification emails can rate without logging in.
 */
final class SubmitRatingEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/updates/(?P<update_id>\d+)/rating';

	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $viewer_service,
		private OrderUpdatesSettingsService $settings_service,
		private AsyncJob $async_job
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
				'args'                => array(
					'stars' => array(
						'required' => true,
						'type'     => 'integer',
					),
				),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update    = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );
		$order_id  = absint( $update['order_id'] ?? 0 );
		$order_key = (string) $request->get_param( 'order_key' );
		$order_key = '' !== $order_key ? sanitize_text_field( wp_unslash( $order_key ) ) : null;

		// Gate on customer-visibility so a guessed update_id on an internal-only update is rejected.
		if (
			! $order_id
			|| ! UpdateState::is_customer_visible( $update )
			|| ! $this->viewer_service->is_acting_as_customer( $order_id, $order_key )
		) {
			return new WP_Error(
				'order_updates_for_woo_forbidden',
				__( 'You are not allowed to rate this update.', 'order-updates-for-woo' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$features = $this->settings_service->get_feature_settings();

		if ( empty( $features['enable_customer_rating'] ) ) {
			return new WP_Error( 'order_updates_for_woo_rating_disabled', __( 'Ratings can only be submitted on resolved updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		$update_id = absint( $request->get_param( 'update_id' ) );
		$update    = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		if ( ! UpdateState::is_resolved( $update ) ) {
			return new WP_Error( 'order_updates_for_woo_rating_not_resolved', __( 'Ratings can only be submitted on resolved updates.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		$existing = $this->order_updates_db->get_rating_for_update( $update_id );

		if ( ! empty( $existing['created_at'] ) ) {
			return new WP_Error( 'order_updates_for_woo_rating_exists', __( 'You\'ve already rated this update.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		$stars = (int) $request->get_param( 'stars' );

		if ( $stars < 1 || $stars > 5 ) {
			return new WP_Error( 'order_updates_for_woo_rating_invalid_stars', __( 'Star rating must be between 1 and 5.', 'order-updates-for-woo' ), array( 'status' => 400 ) );
		}

		$comment = '';

		if ( ! empty( $features['enable_customer_rating_comment'] ) ) {
			$comment_raw = (string) $request->get_param( 'comment' );
			$comment     = mb_substr(
				sanitize_textarea_field( wp_unslash( $comment_raw ) ),
				0,
				500
			);
		}

		$order_id = absint( $update['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return $this->order_not_found_error();
		}

		$user_id   = get_current_user_id();
		$user_name = $user_id
			? (string) ( get_userdata( $user_id )->display_name ?? '' )
			: trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$now       = current_time( 'mysql', true );

		do_action( 'order_updates_for_woo_before_customer_rating', $update_id, $order_id, $stars, $comment, $request );

		$saved = $this->order_updates_db->submit_rating(
			$update_id,
			$order_id,
			$stars,
			$comment,
			$user_id,
			$user_name,
			$now
		);

		if ( ! $saved ) {
			return new WP_Error( 'order_updates_for_woo_rating_save_failed', __( 'Could not save your rating. Please try again.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		// Mirror the rating into the tracking log so the lifecycle view (and
		// the deletion audit snapshot) shows "Customer rated X/5" alongside
		// status flips. Comment, when present, is appended on a new line so
		// long feedback still reads cleanly in the timeline.
		$history_message = sprintf(
			/* translators: %d: star rating (1-5). */
			__( 'Customer rated %d/5', 'order-updates-for-woo' ),
			$stars
		);

		if ( '' !== $comment ) {
			$history_message .= "\n" . $comment;
		}

		$this->order_updates_db->log_lifecycle_event(
			$update_id,
			'rating',
			$history_message,
			$user_id,
			$now
		);

		$rating = $this->order_updates_db->get_rating_for_update( $update_id );

		// Tell the assignee a customer just rated this update — same email
		// pipeline as other assignee notices, gated by the personal mute.
		$update      = $this->order_updates_db->get_update( $update_id );
		$assignee_id = absint( $update['assignee_user_id'] ?? 0 );

		if ( $assignee_id && ! StaffEmailPreference::is_muted( $update_id, $assignee_id ) ) {
			$this->async_job->queue(
				Constants::HOOK_ASSIGNEE_NOTIFICATION,
				array(
					'update_id'        => $update_id,
					'assignee_user_id' => $assignee_id,
					'context'          => 'rated',
					'actor_user_id'    => $user_id,
				)
			);
		}

		// Email the site admin on 1-3 star ratings (gated by the "Email site
		// admin on low ratings" setting). Skipped when admin == assignee
		// to avoid a duplicate on small stores.
		$is_detractor = $stars > 0 && $stars <= 3;

		if ( $is_detractor && $this->settings_service->notify_admin_on_detractor_rating() ) {
			$admin_user    = get_user_by( 'email', (string) get_option( 'admin_email' ) );
			$admin_user_id = $admin_user ? (int) $admin_user->ID : 0;

			if (
				$admin_user_id > 0
				&& $admin_user_id !== $assignee_id
				&& ! StaffEmailPreference::is_muted( $update_id, $admin_user_id )
			) {
				$this->async_job->queue(
					Constants::HOOK_ADMIN_NOTIFICATION,
					array(
						'update_id'         => $update_id,
						'recipient_user_id' => $admin_user_id,
						'context'           => 'rated',
					)
				);
			}
		}

		do_action( 'order_updates_for_woo_after_customer_rating', $update_id, $order_id, $rating, $request );

		$response = array(
			'message'  => __( 'Thanks for your feedback!', 'order-updates-for-woo' ),
			'updateId' => $update_id,
			'stars'    => $stars,
			'comment'  => $comment,
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_customer_rating_response', $response, $request ) );
	}
}
