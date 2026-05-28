<?php
/**
 * Customers settings controller — wires the Customers sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\CustomersSettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class CustomersSettingsController implements SettingsSectionController {
	public function __construct( private CustomersSettingsService $service ) {}

	public function init(): void {
		// No section-specific hooks — save flows through the orchestrator.
	}

	public function id(): string {
		return CustomersSettingsService::SECTION_ID;
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
