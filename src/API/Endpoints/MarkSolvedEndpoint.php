<?php
/**
 * REST endpoint — mark solved.
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
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Updates\UpdateCardVariableParser;
use OrderUpdatesForWoo\Helpers\UpdateState;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "mark solved" REST request.
 */
final class MarkSolvedEndpoint implements Registrable {
	use VerifiesAccess;
	use RendersCardHtml;

	private const ROUTE = '/updates/(?P<update_id>\d+)/solve';

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

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to mark this update as solved.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
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
			return new WP_Error( 'order_updates_for_woo_already_solved', __( 'Update marked as solved.', 'order-updates-for-woo' ), array( 'status' => 409 ) );
		}

		do_action( 'order_updates_for_woo_before_mark_solved', $update_id, $update, $request );

		$saved = $this->order_updates_db->mark_as_solved( $update_id, get_current_user_id(), current_time( 'mysql', true ) );

		if ( ! $saved ) {
			return new WP_Error( 'order_updates_for_woo_save_failed', __( 'Could not save the order update.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		$solved_update = $this->order_updates_db->get_update( $update_id );

		if ( (bool) $request->get_param( 'notify_customer' ) ) {
			$this->maybe_queue_customer_email( $solved_update );
		}

		$this->maybe_queue_assignee_email( $update_id, $solved_update );

		do_action( 'order_updates_for_woo_after_mark_solved', $update_id, $solved_update, $request );

		$response = array(
			'message'  => __( 'Update marked as solved.', 'order-updates-for-woo' ),
			'updateId' => $update_id,
			'cardHtml' => $this->render_card_html( $solved_update ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_mark_solved_response', $response, $request ) );
	}

	/**
	 * Queue the "your update was resolved" email to the customer. Mirrors
	 * the gate ChangeUpdateStatusEndpoint uses: customer must be able to
	 * see the update + must not have muted the order's email thread.
	 *
	 * @param array $update Update row.
	 */
	private function maybe_queue_customer_email( array $update ): void {
		if ( empty( $update['customer_visible'] ) ) {
			return;
		}

		$update_id = absint( $update['id'] ?? 0 );
		$order_id  = absint( $update['order_id'] ?? 0 );

		if ( ! $update_id || ! $order_id ) {
			return;
		}

		$order = function_exists( 'wc_get_order' ) ? \wc_get_order( $order_id ) : null;

		if ( ! $order ) {
			return;
		}

		$customer_id = (int) $order->get_customer_id();

		if ( ! CustomerEmailPreference::get( $order_id, $customer_id ) ) {
			return;
		}

		$this->async_job->queue(
			Constants::HOOK_CUSTOMER_NOTIFICATION,
			array(
				'update_id' => $update_id,
				'note_id'   => 0,
				'context'   => 'resolved',
			)
		);
	}

	/**
	 * Notify the assignee that the update they own has been resolved —
	 * unless they're the one who marked it solved or they've muted this
	 * update's email thread.
	 *
	 * @param int   $update_id Update id.
	 * @param array $update    Update row.
	 */
	private function maybe_queue_assignee_email( int $update_id, array $update ): void {
		$assignee_id = absint( $update['assignee_user_id'] ?? 0 );

		if ( ! $assignee_id ) {
			return;
		}

		if ( get_current_user_id() === $assignee_id ) {
			return;
		}

		// Safety net: skip when the assignee resolves to the order's customer.
		// The picker UI prevents this, but tampered API calls could slip
		// through and trigger a staff-only email to the customer.
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
				'context'          => 'resolved',
				'actor_user_id'    => get_current_user_id(),
			)
		);
	}
}
