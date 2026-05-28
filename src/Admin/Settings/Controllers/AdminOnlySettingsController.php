<?php
/**
 * Admin Only settings controller — wires the Admin Only sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\AdminOnlySettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class AdminOnlySettingsController implements SettingsSectionController {
	public function __construct( private AdminOnlySettingsService $service ) {}

	public function init(): void {
		// No section-specific hooks — save flows through the orchestrator.
	}

	public function id(): string {
		return AdminOnlySettingsService::SECTION_ID;
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
