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

/**
 * Controller for the customers settings section.
 */
final class CustomersSettingsController implements SettingsSectionController {
	/**
	 * Inject dependencies.
	 *
	 * @param CustomersSettingsService $service Injected dependency.
	 */
	public function __construct( private CustomersSettingsService $service ) {}

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
		return CustomersSettingsService::SECTION_ID;
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
