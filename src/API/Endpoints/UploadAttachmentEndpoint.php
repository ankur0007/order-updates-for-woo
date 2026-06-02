<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Helpers\AttachmentPresenter;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Updates\OrderUpdatesDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use OrderUpdatesForWoo\Shared\Validation\Validator;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class UploadAttachmentEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/attachments';

	public function __construct(
		private AttachmentService $attachment_service,
		private OrderUpdatesDb $order_updates_db,
		private Validator $validator
	) {}

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

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		if ( $error = $this->verify_nonce( $request ) ) {
			return $error;
		}

		$update = $this->order_updates_db->get_update( absint( $request->get_param( 'update_id' ) ) );

		if ( $this->is_authorized_for_order( absint( $update['order_id'] ?? 0 ) ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to save order updates.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$validated = $this->validator->validate_attachment_payload(
			array(
				'update_id' => $request->get_param( 'update_id' ),
				'note_id'   => $request->get_param( 'note_id' ),
				'note_type' => $request->get_param( 'note_type' ),
				'file'      => $request->get_file_params()['file'] ?? null,
			)
		);

		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$update = $this->order_updates_db->get_update( (int) $validated['update_id'] );

		if ( empty( $update['id'] ) ) {
			return $this->update_not_found_error();
		}

		$context = array(
			'order_id'    => (int) $update['order_id'],
			'update_id'   => (int) $validated['update_id'],
			'note_id'     => (int) $validated['note_id'],
			'note_type'   => (string) $validated['note_type'],
			'uploaded_by' => get_current_user_id(),
		);

		$context = (array) apply_filters( 'order_updates_for_woo_attachment_upload_context', $context, $validated, $request );

		do_action( 'order_updates_for_woo_before_upload_attachment', $validated, $context, $request );

		$stored = $this->attachment_service->store_upload( $validated['file'], $context );

		if ( is_wp_error( $stored ) ) {
			return $stored;
		}

		do_action( 'order_updates_for_woo_after_upload_attachment', $stored, $context, $request );

		$response = array(
			'attachment' => AttachmentPresenter::format_one( $stored ),
		);

		return rest_ensure_response( apply_filters( 'order_updates_for_woo_upload_attachment_response', $response, $stored, $request ) );
	}
}
