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

/**
 * Controller for the members settings section.
 */
final class MembersSettingsController implements SettingsSectionController {
	/**
	 * Inject dependencies.
	 *
	 * @param MembersSettingsService $service Injected dependency.
	 */
	public function __construct( private MembersSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// "Refresh team list" link is handled by the orchestrator's
		// admin_init router so the URL keeps working from any sub-tab.
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return MembersSettingsService::SECTION_ID;
	}

	/**
	 * Human-readable section label for the nav.
	 */
	public function label(): string {
		return $this->service->label();
	}

	/**
	 * WooCommerce settings fields for this section.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array {
		return $this->service->get_settings();
	}

	/**
	 * Render the section body.
	 */
	public function render(): void {
		woocommerce_admin_fields( $this->service->get_settings() );
	}
}
