<?php
/**
 * Cache settings controller — wires the Cache sub-tab.
 *
 * Cache-clear actions are dispatched via signed admin URLs so they can be
 * shared as bookmarks without breaking nonce guarantees.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\CacheSettingsService;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for the cache settings section.
 */
final class CacheSettingsController implements SettingsSectionController {
	private const NONCE_ACTION = 'order_updates_for_woo_cache_action';
	private const QUERY_PARAM  = 'order_updates_for_woo_cache';

	/**
	 * Inject dependencies.
	 *
	 * @param CacheSettingsService $service Injected dependency.
	 */
	public function __construct( private CacheSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_action' ) );
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return CacheSettingsService::SECTION_ID;
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
		$this->maybe_show_done_notice();

		$buttons = array_map(
			fn( array $button ) => array_merge( $button, array( 'url' => $this->build_action_url( (string) $button['id'] ) ) ),
			$this->service->action_buttons()
		);

		View::render(
			'src/Admin/Settings/Views/cache/buttons',
			array( 'buttons' => $buttons )
		);
	}

	/** Handle a cache action button click (nonce-checked), then redirect. */
	public function maybe_handle_action(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$action = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) );

		match ( $action ) {
			CacheSettingsService::ACTION_TEAM              => $this->service->clear_team_cache(),
			CacheSettingsService::ACTION_ANALYTICS         => $this->service->clear_analytics_cache(),
			CacheSettingsService::ACTION_REBUILD_ANALYTICS => $this->service->rebuild_analytics_lookup(),
			default                                        => null,
		};

		wp_safe_redirect(
			remove_query_arg(
				array( self::QUERY_PARAM, '_wpnonce' ),
				add_query_arg( 'order_updates_for_woo_cache_done', $action )
			)
		);
		exit;
	}

	/**
	 * Build a nonce-protected URL for one cache action.
	 *
	 * @param string $action Action key.
	 */
	private function build_action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'            => 'wc-settings',
					'tab'             => 'order_updates_for_woo',
					'section'         => self::id_static(),
					self::QUERY_PARAM => $action,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_ACTION
		);
	}

	/** This section's id (static so URL builders can use it). */
	private static function id_static(): string {
		return CacheSettingsService::SECTION_ID;
	}

	/** Show the success notice after a cache action redirect. */
	private function maybe_show_done_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash
		$done = isset( $_GET['order_updates_for_woo_cache_done'] ) ? sanitize_key( wp_unslash( (string) $_GET['order_updates_for_woo_cache_done'] ) ) : '';

		if ( '' === $done ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Cache cleared.', 'order-updates-for-woo' )
		);
	}
}
