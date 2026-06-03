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

/**
 * Controller for the notifications settings section.
 */
final class NotificationsSettingsController implements SettingsSectionController {
	/**
	 * Inject dependencies.
	 *
	 * @param NotificationsSettingsService $service Injected dependency.
	 */
	public function __construct( private NotificationsSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// No section-specific hooks — settings save flows through the orchestrator.
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return NotificationsSettingsService::SECTION_ID;
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
