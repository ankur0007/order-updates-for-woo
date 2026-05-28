<?php
/**
 * General settings controller — wires the General sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Helpers\AssetHelper;
use OrderUpdatesForWoo\Admin\Settings\Services\GeneralSettingsService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GeneralSettingsController implements SettingsSectionController {
	private const ASSET_HANDLE = 'order-updates-for-woo-general-tab';

	public function __construct( private GeneralSettingsService $service ) {}

	public function init(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets( string $hook ): void {
		if ( 'woocommerce_page_wc-settings' !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only tab check
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		// General is the unnamed default section under our tab.
		if ( 'order_updates_for_woo' !== $tab || '' !== $section ) {
			return;
		}

		$css_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/general-tab.css';

		wp_enqueue_style(
			self::ASSET_HANDLE,
			AssetHelper::url( 'assets/Admin/css/general-tab.css' ),
			array(),
			file_exists( $css_file ) ? (string) filemtime( $css_file ) : '1.0.0'
		);

		// Media-library picker for the note-panel background image fields.
		// `wp_enqueue_media()` ships the wp.media frame the JS opens.
		wp_enqueue_media();

		$js_file = ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/js/settings-media-picker.js';

		wp_enqueue_script(
			self::ASSET_HANDLE . '-media-picker',
			AssetHelper::url( 'assets/Admin/js/settings-media-picker.js' ),
			array( 'jquery', 'media-editor' ),
			file_exists( $js_file ) ? (string) filemtime( $js_file ) : '1.0.0',
			true
		);
	}

	public function id(): string {
		return GeneralSettingsService::SECTION_ID;
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
