<?php
/**
 * Members settings controller — wires the Members sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\MembersSettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MembersSettingsController implements SettingsSectionController {
	public function __construct( private MembersSettingsService $service ) {}

	public function init(): void {
		// "Refresh team list" link is handled by the orchestrator's
		// admin_init router so the URL keeps working from any sub-tab.
	}

	public function id(): string {
		return MembersSettingsService::SECTION_ID;
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
