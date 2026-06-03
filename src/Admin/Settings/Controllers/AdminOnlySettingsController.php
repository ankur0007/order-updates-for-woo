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

/**
 * Controller for the admin only settings section.
 */
final class AdminOnlySettingsController implements SettingsSectionController {
	/**
	 * Inject dependencies.
	 *
	 * @param AdminOnlySettingsService $service Injected dependency.
	 */
	public function __construct( private AdminOnlySettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// No section-specific hooks — save flows through the orchestrator.
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return AdminOnlySettingsService::SECTION_ID;
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
