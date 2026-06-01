<?php
/**
 * Notifications settings controller — wires the Notifications sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\NotificationsSettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class NotificationsSettingsController implements SettingsSectionController {
	public function __construct( private NotificationsSettingsService $service ) {}

	public function init(): void {
		// No section-specific hooks — settings save flows through the orchestrator.
	}

	public function id(): string {
		return NotificationsSettingsService::SECTION_ID;
	}

	public function label(): string {
		return $this->service->label();
	}

	public function get_settings(): array {
		return $this->service->get_settings();
	}

	public function render(): void {
		woocommerce_admin_fields( $this->service->get_settings() );
	}
}
