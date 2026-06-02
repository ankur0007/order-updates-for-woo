<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Frontend\OrderUpdates\Services\CustomerOrderUpdatesService;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use WC_Order;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Lightweight poll endpoint for the customer order-updates page.
 *
 * The customer JS calls this every 30 seconds with:
 *   - since_note_id  — highest note id already in the DOM
 *   - since_time     — UTC timestamp of the last successful poll
 *
 * Returns new customer-thread notes and any edited notes whose edited_at is
 * more recent than since_time, plus the current highest update_id so the
 * client can detect a brand-new update and reload to render it.
 */
final class PollCustomerThreadEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/customer-thread/poll';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db Injected dependency.
	 * @param CustomerOrderUpdatesService $customer_service Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private CustomerOrderUpdatesService $customer_service
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

		$order_id  = absint( $request->get_param( 'order_id' ) );
		$order_key = $this->order_key( $request );

		if ( $this->customer_service->can_view_order( $order_id, $order_key ) ) {
			return true;
		}

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to poll this order.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$order_id      = absint( $request->get_param( 'order_id' ) );
		$since_note_id = absint( $request->get_param( 'since_note_id' ) );
		$since_time    = sanitize_text_field( (string) $request->get_param( 'since_time' ) );

		// Fall back to epoch so the first poll after page load returns nothing
		// when since_note_id covers all currently rendered notes.
		if ( '' === $since_time ) {
			$since_time = '1970-01-01 00:00:00';
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_order',
				__( 'Order not found.', 'order-updates-for-woo' ),
				array( 'status' => 404 )
			);
		}

		$customer_id = (int) $order->get_customer_id();

		// Short transient cache keyed by order + cursor so concurrent users
		// watching the same inactive order share one DB round-trip. TTL is
		// deliberately shorter than the minimum poll interval so a user never
		// misses a message for more than two poll cycles.
		$cache_key = 'awts_poll_' . $order_id . '_' . $since_note_id;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			// Refresh server_time so the client advances its cursor correctly.
			$cached['server_time'] = current_time( 'mysql', true );

			return rest_ensure_response(
				apply_filters( 'order_updates_for_woo_poll_customer_thread_response', $cached, $order_id, $request )
			);
		}

		$raw_notes = $this->order_updates_db->get_customer_thread_changes(
			$order_id,
			$since_note_id,
			$since_time
		);

		// The highest id in the polled batch is the absolute latest the
		// client knows about — only that note remains editable.
		$latest_note_id = 0;
		foreach ( $raw_notes as $row ) {
			$rid = (int) ( $row['id'] ?? 0 );
			if ( $rid > $latest_note_id ) {
				$latest_note_id = $rid;
			}
		}

		$notes = array_map(
			fn( array $note ) => $this->customer_service->format_customer_thread_note( $note, $customer_id, $latest_note_id ),
			$raw_notes
		);

		$all_updates         = $this->order_updates_db->get_order_updates( $order_id );
		$resolved_update_ids = array_values(
			array_map(
				static fn( array $u ) => (int) $u['id'],
				array_filter(
					$all_updates,
					static fn( array $u ) => ! empty( $u['is_resolved'] ) && ! empty( $u['customer_visible'] )
				)
			)
		);

		// Inverse of resolved_update_ids — visible updates that are currently
		// open. Sent every poll so the JS can detect a reopen (resolved →
		// open) and restore the reply composer + drop any rating form, without
		// the customer needing to refresh.
		$open_update_ids = array_values(
			array_map(
				static fn( array $u ) => (int) $u['id'],
				array_filter(
					$all_updates,
					static fn( array $u ) => empty( $u['is_resolved'] ) && ! empty( $u['customer_visible'] )
				)
			)
		);

		$response = array(
			'notes'               => $notes,
			'latest_update_id'    => $this->order_updates_db->get_latest_update_id_for_order( $order_id ),
			'resolved_update_ids' => $resolved_update_ids,
			'open_update_ids'     => $open_update_ids,
			'server_time'         => current_time( 'mysql', true ),
		);

		set_transient( $cache_key, $response, Constants::POLL_CACHE_TTL_SECONDS );

		return rest_ensure_response(
			apply_filters( 'order_updates_for_woo_poll_customer_thread_response', $response, $order_id, $request )
		);
	}

	private function order_key( WP_REST_Request $request ): ?string {
		$key = (string) $request->get_param( 'order_key' );

		return '' !== $key ? sanitize_text_field( wp_unslash( $key ) ) : null;
	}
}
