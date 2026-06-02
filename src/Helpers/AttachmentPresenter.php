<?php
/**
 * Shapes attachment rows for output, with the right download URL per context.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Helpers;

use OrderUpdatesForWoo\Shared\Attachments\AttachmentSigner;
use OrderUpdatesForWoo\Shared\Config\Constants;

/**
 * Turns stored attachment rows into view-ready data (name, size, download URL).
 */
final class AttachmentPresenter {

	/**
	 * Shape one attachment row for output.
	 *
	 * @param array  $row     Stored attachment row.
	 * @param string $context Admin or customer — picks the download URL style.
	 */
	public static function format_one( array $row, string $context = Constants::ATTACHMENT_CONTEXT_ADMIN ): array {
		$id = (int) ( $row['id'] ?? 0 );

		return array(
			'id'       => $id,
			'name'     => (string) ( $row['original_name'] ?? '' ),
			'mime'     => (string) ( $row['mime_type'] ?? '' ),
			'size'     => (int) ( $row['file_size'] ?? 0 ),
			'url'      => (string) apply_filters( 'order_updates_for_woo_attachment_url', self::build_url( $id, $context ), $row, $context ),
			'is_image' => str_starts_with( (string) ( $row['mime_type'] ?? '' ), 'image/' ),
		);
	}

	/**
	 * Shape a list of attachment rows.
	 *
	 * @param array  $rows    Stored attachment rows.
	 * @param string $context Admin or customer — picks the download URL style.
	 */
	public static function format_many( array $rows, string $context = Constants::ATTACHMENT_CONTEXT_ADMIN ): array {
		return array_map(
			static fn( array $row ): array => self::format_one( $row, $context ),
			$rows
		);
	}

	/**
	 * Download URL for an attachment — a signed link for customers, a nonced one for staff.
	 *
	 * @param int    $attachment_id Attachment row id.
	 * @param string $context       Admin or customer.
	 */
	private static function build_url( int $attachment_id, string $context ): string {
		$url = RestUrlHelper::attachment_download( $attachment_id );

		if ( Constants::ATTACHMENT_CONTEXT_CUSTOMER === $context ) {
			$signed = AttachmentSigner::sign( $attachment_id );
			return add_query_arg(
				array(
					'awts_expires' => $signed['expires'],
					'awts_token'   => $signed['token'],
				),
				$url
			);
		}

		return add_query_arg( '_wpnonce', wp_create_nonce( 'wp_rest' ), $url );
	}
}
