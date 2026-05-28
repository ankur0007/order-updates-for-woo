<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\API\Endpoints;

use OrderUpdatesForWoo\API\Concerns\VerifiesAccess;
use OrderUpdatesForWoo\API\Contracts\Registrable;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentSigner;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentsDb;
use OrderUpdatesForWoo\Shared\Config\Constants;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ServeAttachmentEndpoint implements Registrable {
	use VerifiesAccess;

	private const ROUTE = '/attachments/(?P<attachment_id>\d+)/download';

	public function __construct(
		private AttachmentService $attachment_service,
		private AttachmentsDb $attachments_db
	) {}

	public function register(): void {
		register_rest_route(
			Constants::REST_NAMESPACE,
			self::ROUTE,
			array(
				'methods' => \WP_REST_Server::READABLE,
				'callback' => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'can_access' ),
			)
		);
	}

	public function can_access( WP_REST_Request $request ): bool|WP_Error {
		$attachment_id = absint( $request->get_param( 'attachment_id' ) );
		$record        = $this->attachments_db->get( $attachment_id );

		if ( empty( $record ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_missing', __( 'Attachment not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		$token   = (string) $request->get_param( 'awts_token' );
		$expires = absint( $request->get_param( 'awts_expires' ) );

		if ( '' !== $token
			&& Constants::NOTE_TYPE_CUSTOMER === (string) $record['note_type']
			&& AttachmentSigner::verify( $attachment_id, $expires, $token )
		) {
			return true;
		}

		if ( $this->is_authorized_for_order( absint( $record['order_id'] ?? 0 ) ) ) {
			return true;
		}

		return new WP_Error( 'order_updates_for_woo_forbidden', __( 'You are not allowed to view this update.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
	}

	public function handle( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$record = $this->attachments_db->get( absint( $request->get_param( 'attachment_id' ) ) );

		if ( empty( $record ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_missing', __( 'Attachment not found.', 'order-updates-for-woo' ), array( 'status' => 404 ) );
		}

		$path = $this->attachment_service->absolute_path_for( $record );

		if ( ! file_exists( $path ) || ! is_readable( $path ) ) {
			return new WP_Error( 'order_updates_for_woo_attachment_file_missing', __( 'File is unavailable.', 'order-updates-for-woo' ), array( 'status' => 410 ) );
		}

		$mime_type = (string) $record['mime_type'];

		// Use the supported catalog, not the active upload list — disabling
		// a mime in settings shouldn't lock readers out of files already
		// in storage from when that type was allowed.
		if ( ! in_array( $mime_type, AttachmentService::supported_mime_types(), true ) ) {
			return new WP_Error( 'order_updates_for_woo_invalid_mime', __( 'File type not permitted.', 'order-updates-for-woo' ), array( 'status' => 403 ) );
		}

		$disposition = str_starts_with( $mime_type, 'image/' ) ? 'inline' : 'attachment';

		do_action( 'order_updates_for_woo_before_serve_attachment', (int) ( $record['id'] ?? 0 ), $record, $request );

		nocache_headers();
		header( 'Content-Type: ' . $mime_type );
		header( 'Content-Length: ' . filesize( $path ) );
		header( 'Content-Disposition: ' . $disposition . '; filename="' . rawurlencode( (string) $record['original_name'] ) . '"' );
		header( 'X-Content-Type-Options: nosniff' );

		// Stream the file directly. WP_Filesystem would load the full file
		// into memory, which breaks on large attachments.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile -- streaming download; loading into memory via WP_Filesystem would be worse.
		readfile( $path );
		exit;
	}
}
