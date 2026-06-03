<?php
/**
 * Shortcodes settings controller — wires the Shortcodes sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\ShortcodesSettingsService;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for the shortcodes settings section.
 */
final class ShortcodesSettingsController implements SettingsSectionController {
	/**
	 * Inject dependencies.
	 *
	 * @param ShortcodesSettingsService $service Injected dependency.
	 */
	public function __construct( private ShortcodesSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// Read-only tab — no hooks needed.
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return ShortcodesSettingsService::SECTION_ID;
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
		View::render(
			'src/Admin/Settings/Views/shortcodes/usage',
			array( 'shortcodes' => $this->service->shortcodes() )
		);
	}
}
