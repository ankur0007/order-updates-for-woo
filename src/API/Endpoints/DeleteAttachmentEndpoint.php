<?php
/**
 * REST endpoint — delete attachment.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Handles the "delete attachment" REST request.
 */
final class DeleteAttachmentEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/attachments/(?P<attachment_id>\d+)';

	/**
	 * Inject dependencies.
	 *
	 * @param AttachmentService $attachment_service Injected dependency.
	 * @param AttachmentsDb     $attachments_db     Injected dependency.
	 */
	public function __construct(
		private AttachmentService $attachment_service,
		private AttachmentsDb $attachments_db
	) {}

	/** Register the REST route. */
	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
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

		$record = $this->attachments_db->get( absint( $request->get_param( 'attachment_id' ) ) );

		if ( $this->is_authorized_for_order( absint( $record['order_id'] ?? 0 ) ) ) {
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
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$record        = $this->attachments_db->get( $attachment_id );

		if ( empty( $record ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_missing', __( 'Attachment not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		do_action( 'order_updates_for_woo_before_delete_attachment', $attachment_id, $record, $request );

		$deleted = $this->attachment_service->delete( $attachment_id );

		if ( ! $deleted ) {
			return new WP_Error( 'order_updates_for_woo_attachment_delete_failed', __( 'Could not delete attachment.', 'order-updates-for-woo' ), array( 'status' => 500 ) );
		}

		do_action( 'order_updates_for_woo_after_delete_attachment', $attachment_id, $record, $request );

		return rest_ensure_response(
			apply_filters(
				'order_updates_for_woo_delete_attachment_response',
				array(
					'deleted' => true,
					'id'      => $attachment_id,
				),
				$record,
				$request 
			) 
		);
	}
}
