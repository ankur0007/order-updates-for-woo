<?php
/**
 * Contract for a settings sub-tab controller.
 *
 * Each implementation owns one section under the Order Updates settings tab.
 * The orchestrator (`OrderUpdatesSettingsController`) collects implementers
 * via the `order_updates_for_woo_settings_section_controllers` filter and
 * dispatches render + save to the controller whose `id()` matches the
 * `?section=` URL parameter.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SettingsSectionController {
	/**
	 * Register WP/WC hooks the section depends on (admin-init handlers,
	 * AJAX, etc.). Called once by the orchestrator at boot. Sections that
	 * don't need extra hooks should implement this as a no-op.
	 */
	public function init(): void;

	/**
	 * URL-safe section id, e.g. 'members'. Empty string is the default
	 * section that loads when no `?section=` param is set.
	 */
	public function id(): string;

	/**
	 * Human-readable label shown in the left-rail nav.
	 */
	public function label(): string;

	/**
	 * Settings fields for `woocommerce_update_options()`. Return an empty
	 * array for view-only sections that have no persisted form fields.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_settings(): array;

	/**
	 * Render the section body. Implementers can mix custom HTML (notices,
	 * buttons, info cards) with `woocommerce_admin_fields()` calls.
	 */
	public function render(): void;
}
