<?php
/**
 * Attachments settings — file size + count limits for note uploads.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Services;

use OrderUpdatesForWoo\Shared\Attachments\AttachmentService;
use OrderUpdatesForWoo\Shared\Config\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings fields and values for the attachments section.
 */
final class AttachmentsSettingsService {
	public const SECTION_ID = 'attachments';

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return __( 'Attachments', 'order-updates-for-woo' );
	}

	/**
	 * Settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return array(
			array(
				'name' => __( 'Attachments', 'order-updates-for-woo' ),
				'type' => 'title',
				'desc' => __( 'Attachments uploaded here are stored privately and accessible only to authorized users. They do not appear in your Media Library and are not picked up by media-offload plugins. Cloud storage support is planned via a future addon.', 'order-updates-for-woo' ),
				'id'   => 'order_updates_for_woo_attachments_section',
			),
			array(
				'name'              => __( 'Max files per note', 'order-updates-for-woo' ),
				'desc'              => __( 'How many files can be attached to a single note.', 'order-updates-for-woo' ),
				'id'                => Constants::MAX_ATTACHMENT_FILES_OPTION,
				'type'              => 'number',
				'default'           => 5,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 50,
					'step' => 1,
				),
			),
			array(
				'name'              => __( 'Max file size (MB)', 'order-updates-for-woo' ),
				'desc'              => $this->size_field_description(),
				'id'                => Constants::MAX_ATTACHMENT_MB_OPTION,
				'type'              => 'number',
				'default'           => 10,
				'desc_tip'          => false,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => $this->server_max_upload_mb(),
					'step' => 1,
				),
			),
			array(
				'name'     => __( 'Allowed file types', 'order-updates-for-woo' ),
				'desc'     => __( 'File types accepted when staff or customers upload attachments. Disabling a type only blocks new uploads — files already stored in that format remain readable.', 'order-updates-for-woo' ),
				'id'       => Constants::ALLOWED_MIMES_OPTION,
				'type'     => 'multiselect',
				'class'    => 'wc-enhanced-select',
				'default'  => AttachmentService::DEFAULT_ACTIVE_MIMES,
				'options'  => $this->mime_options(),
				'desc_tip' => false,
				'css'      => 'min-width:300px;',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'order_updates_for_woo_attachments_section',
			),
		);
	}

	/**
	 * Allowed mime types as value => label.
	 *
	 * @return array<string,string>
	 */
	private function mime_options(): array {
		return array(
			'application/pdf'                         => __( 'PDF (.pdf)', 'order-updates-for-woo' ),
			'application/msword'                      => __( 'Word — legacy (.doc)', 'order-updates-for-woo' ),
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => __( 'Word (.docx)', 'order-updates-for-woo' ),
			'application/vnd.ms-excel'                => __( 'Excel — legacy (.xls)', 'order-updates-for-woo' ),
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => __( 'Excel (.xlsx)', 'order-updates-for-woo' ),
			'application/vnd.ms-powerpoint'           => __( 'PowerPoint — legacy (.ppt)', 'order-updates-for-woo' ),
			'application/vnd.openxmlformats-officedocument.presentationml.presentation' => __( 'PowerPoint (.pptx)', 'order-updates-for-woo' ),
			'application/vnd.oasis.opendocument.text' => __( 'OpenDocument Text (.odt)', 'order-updates-for-woo' ),
			'application/vnd.oasis.opendocument.spreadsheet' => __( 'OpenDocument Spreadsheet (.ods)', 'order-updates-for-woo' ),
			'application/vnd.oasis.opendocument.presentation' => __( 'OpenDocument Presentation (.odp)', 'order-updates-for-woo' ),
			'application/rtf'                         => __( 'RTF (.rtf)', 'order-updates-for-woo' ),
			'text/plain'                              => __( 'Plain text (.txt)', 'order-updates-for-woo' ),
			'text/csv'                                => __( 'CSV (.csv)', 'order-updates-for-woo' ),
			'image/jpeg'                              => __( 'JPEG (.jpg, .jpeg)', 'order-updates-for-woo' ),
			'image/png'                               => __( 'PNG (.png)', 'order-updates-for-woo' ),
			'image/gif'                               => __( 'GIF (.gif)', 'order-updates-for-woo' ),
			'image/webp'                              => __( 'WebP (.webp)', 'order-updates-for-woo' ),
		);
	}

	/** The server's upload limit in whole megabytes. */
	private function server_max_upload_mb(): int {
		return max( 1, (int) floor( wp_max_upload_size() / 1024 / 1024 ) );
	}

	/** Help text for the max-file-size field, noting the server cap. */
	private function size_field_description(): string {
		$php_max_bytes    = (int) wp_max_upload_size();
		$php_max_label    = size_format( $php_max_bytes );
		$configured_mb    = max( 1, (int) get_option( Constants::MAX_ATTACHMENT_MB_OPTION, 10 ) );
		$configured_bytes = $configured_mb * 1024 * 1024;

		$base = __( 'Maximum size per uploaded file, in megabytes.', 'order-updates-for-woo' );

		if ( $configured_bytes > $php_max_bytes ) {
			return $base . ' ' . sprintf(
				/* translators: %s: server upload limit (e.g. "2 MB") */
				__( 'Your server\'s upload limit is %s — your current setting exceeds this and will be capped automatically. To allow larger files, increase <code>upload_max_filesize</code> in your server configuration first.', 'order-updates-for-woo' ),
				'<strong>' . esc_html( $php_max_label ) . '</strong>'
			);
		}

		return $base . ' ' . sprintf(
			/* translators: %s: server upload limit (e.g. "2 MB") */
			__( 'Your server allows up to %s per file.', 'order-updates-for-woo' ),
			'<strong>' . esc_html( $php_max_label ) . '</strong>'
		);
	}
}
