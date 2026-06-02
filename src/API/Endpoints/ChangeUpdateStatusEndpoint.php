<?php
/**
 * REST endpoint — change update status.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\Admin\Settings\Services\OrderUpdatesSettingsService;
use OrderUpdatesForWoo\API\Concerns\RendersCardHtml;
use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AsyncJob;
use OrderUpdatesForWoo\Helpers\CustomerEmailPreference;
use OrderUpdatesForWoo\Helpers\StaffEmailPreference;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Inline status changer — admin/member picks a new status from the card
 * footer dropdown. The endpoint validates the picked color against the
 * admin-configured status list, updates the live `updates.color`, and
 * stamps a system row into the customer-notes thread so the change is
 * recorded in the conversation timeline (and visible to the customer).
 */
final class ChangeUpdateStatusEndpoint implements Registrable {
	use VerifiesAccess;
	use RendersCardHtml;

	private const ROUTE = '/updates/(?P<update_id>\d+)/status';

	/**
	 * Inject dependencies.
	 *
	 * @param OrderUpdatesDb              $order_updates_db            Injected dependency.
	 * @param OrderUpdatesSettingsService $settings_service            Injected dependency.
	 * @param UpdateCardVariableParser    $update_card_variable_parser Injected dependency.
	 * @param AsyncJob                    $async_job                   Injected dependency.
	 */
	public function __construct(
		private OrderUpdatesDb $order_updates_db,
		private OrderUpdatesSettingsService $settings_service,
		private UpdateCardVariableParser $update_card_variable_parser,
		private AsyncJob $async_job
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

		return new WP_Error(
			'order_updates_for_woo_forbidden',
			__( 'You are not allowed to change the status of this update.', 'order-updates-for-woo' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Handle the request: validate, run the action, and return the response.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 */
	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$update_id  = absint( $request->get_param( 'update_id' ) );
		$status_key = sanitize_key( (string) $request->get_param( 'status' ) );
		$status     = $this->resolve_status( $status_key );

		if ( null === $status ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_status',
				__( 'The selected status is not a valid choice.', 'order-updates-for-woo' ),
				array( 'status' => 400 )
			);
		}

		$update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['id'] ) ) {
			return new WP_Error(
				'order_updates_for_woo_invalid_update',
				__( 'The selected update could not be found.', 'order-updates-for-woo' ),
				array( 'status' => 404 )
			);
		}

		// A resolved update is locked — re-open it before changing the status.
		if ( ! empty( $update['is_resolved'] ) ) {
			return new WP_Error(
				'order_updates_for_woo_update_resolved',
				__( 'This update is resolved. Re-open it before changing its status.', 'order-updates-for-woo' ),
				array( 'status' => 409 )
			);
		}

		$user            = wp_get_current_user();
		$changed_by_name = $user instanceof \WP_User ? (string) $user->display_name : '';
		$message         = sprintf(
			/* translators: %s: new status label, e.g. "Notice". */
			__( 'Status changed to %s', 'order-updates-for-woo' ),
			(string) $status['label']
		);

		$note_id = $this->order_updates_db->change_update_status(
			$update_id,
			(string) $status['key'],
			(string) $status['color'],
			get_current_user_id(),
			$changed_by_name,
			current_time( 'mysql', true ),
			$message
		);

		// Auto-queue the customer email if the update is customer-visible
		// and the customer hasn't opted out. Hidden updates get nothing.
		if ( $note_id ) {
			$this->maybe_queue_customer_email( $update_id, $note_id );
			$this->maybe_queue_assignee_email( $update_id );
		}

		$fresh_update = $this->order_updates_db->get_update( $update_id );

		$response = array(
			'message'  => __( 'Status updated.', 'order-updates-for-woo' ),
			'updateId' => $update_id,
			'noteId'   => $note_id,
			'status'   => (string) ( $fresh_update['status'] ?? $status['key'] ),
			'color'    => (string) ( $fresh_update['color'] ?? $status['color'] ),
			'cardHtml' => $this->render_card_html( $fresh_update ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_change_status_response', $response, $request ) );
	}

	/**
	 * Queue an assignee-notification email for a status change. Gated by the
	 * usual personal-mute preference + "don't email the person who just made
	 * the change" rule — when the admin who flipped the status is also the
	 * assignee, no email goes out (they performed it; they know).
	 */
	private function maybe_queue_assignee_email( int $update_id ): void {
		$update      = $this->order_updates_db->get_update( $update_id );
		$assignee_id = absint( $update['assignee_user_id'] ?? 0 );

		if ( ! $assignee_id ) {
			return;
		}

		$actor_id = get_current_user_id();

		if ( $assignee_id === $actor_id ) {
			return;
		}

		// Defense in depth: the assignee picker is staff-only (TeamRoster
		// gate), but if assignee_user_id ever pointed at the order's
		// customer, we'd email them a staff-targeted body. Skip to keep the
		// "customers never receive staff emails" rule absolute.
		$order_id          = absint( $update['order_id'] ?? 0 );
		$order_customer_id = $order_id && function_exists( 'wc_get_order' )
			? (int) ( wc_get_order( $order_id )?->get_customer_id() ?? 0 )
			: 0;

		if ( $assignee_id === $order_customer_id ) {
			return;
		}

		if ( StaffEmailPreference::is_muted( $update_id, $assignee_id ) ) {
			return;
		}

		$this->async_job->queue(
			Constants::HOOK_ASSIGNEE_NOTIFICATION,
			array(
				'update_id'        => $update_id,
				'assignee_user_id' => $assignee_id,
				'context'          => 'status_change',
				'actor_user_id'    => $actor_id,
			)
		);
	}

	/**
	 * Queue the customer-notification email for this status-change row, but
	 * only when both conditions hold:
	 *   1. The update is customer-visible — hidden updates produce no email,
	 *      same as for regular notes.
	 *   2. The customer hasn't muted email for this order's thread.
	 *
	 * The HOOK_CUSTOMER_NOTIFICATION dispatch path is shared with the manual
	 * "Notify customer" button, so the customer's existing inbox preferences,
	 * de-dup logic, and email template all apply automatically.
	 */
	private function maybe_queue_customer_email( int $update_id, int $note_id ): void {
		$update = $this->order_updates_db->get_update( $update_id );

		if ( empty( $update['customer_visible'] ) ) {
			return;
		}

		$order_id = (int) ( $update['order_id'] ?? 0 );
		if ( ! $order_id ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();

		if ( ! CustomerEmailPreference::get( $order_id, $customer_id ) ) {
			return;
		}

		$queued = $this->async_job->queue(
			Constants::HOOK_CUSTOMER_NOTIFICATION,
			array(
				'update_id' => $update_id,
				'note_id'   => $note_id,
			)
		);

		if ( $queued ) {
			$this->order_updates_db->mark_customer_note_queued( $note_id, current_time( 'mysql', true ) );
		}
	}

	/**
	 * Match the submitted status key against the admin-configured list.
	 * Returns the full status row { key, label, color } on success, null
	 * when the key isn't a configured choice. Keeping the dropdown as the
	 * only authority means an addon that filters the status list passes
	 * through naturally — no extra hook plumbing required.
	 *
	 * @return array{key:string, label:string, color:string}|null
	 */
	private function resolve_status( string $key ): ?array {
		if ( '' === $key ) {
			return null;
		}

		foreach ( $this->settings_service->get_statuses() as $status ) {
			if ( sanitize_key( (string) ( $status['key'] ?? '' ) ) === $key ) {
				return array(
					'key'   => (string) $status['key'],
					'label' => (string) $status['label'],
					'color' => (string) $status['color'],
				);
			}
		}

		return null;
	}
}
