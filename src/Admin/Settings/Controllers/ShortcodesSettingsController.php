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

final class ShortcodesSettingsController implements SettingsSectionController {
	public function __construct( private ShortcodesSettingsService $service ) {}

	public function init(): void {
		// Read-only tab — no hooks needed.
	}

	public function id(): string {
		return ShortcodesSettingsService::SECTION_ID;
	}

	public function label(): string {
		return $this->service->label();
	}

	public function get_settings(): array {
		return $this->service->get_settings();
	}

	public function render(): void {
		View::render(
			'src/Admin/Settings/Views/shortcodes/usage',
			array( 'shortcodes' => $this->service->shortcodes() )
		);
	}
}
