<?php
/**
 * Attachments settings controller — wires the Attachments sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\AttachmentsSettingsService;
use OrderUpdatesForWoo\Shared\Attachments\AttachmentStorage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AttachmentsSettingsController implements SettingsSectionController {
	public function __construct( private AttachmentsSettingsService $service ) {}

	public function init(): void {
		// No section-specific hooks — settings save flows through the orchestrator.
	}

	public function id(): string {
		return AttachmentsSettingsService::SECTION_ID;
	}

	public function label(): string {
		return $this->service->label();
	}

	public function get_settings(): array {
		return $this->service->get_settings();
	}

	public function render(): void {
		$this->maybe_show_nginx_notice();
		woocommerce_admin_fields( $this->service->get_settings() );
	}

	/**
	 * Apache .htaccess rules don't apply on Nginx, so warn admins that the
	 * uploads directory might be web-accessible without a server-block rule.
	 */
	private function maybe_show_nginx_notice(): void {
		// $_SERVER read is unslashed + sanitized + cast to string before use.
		$software = isset( $_SERVER['SERVER_SOFTWARE'] )
			? strtolower( sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) )
			: '';

		if ( ! str_contains( $software, 'nginx' ) ) {
			return;
		}

		if ( ! is_dir( AttachmentStorage::attachments_dir() ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
			esc_html__( 'Order Updates — Nginx detected:', 'order-updates-for-woo' ),
			esc_html__( 'Your attachment upload directory may be publicly accessible. Apache .htaccess rules are ignored on Nginx. Add a server block to deny direct access to the uploads/order-updates-for-woo/ directory.', 'order-updates-for-woo' )
		);
	}
}
