<?php
/**
 * Settings tab orchestrator.
 *
 * Registers the WooCommerce settings tab and dispatches render/save to the
 * sub-tab controllers (one per section: General, Members, Emails, Cache,
 * Attachments, Shortcodes, API). Sub-controllers are passed in by the
 * plugin bootstrap and can be extended via the
 * `order_updates_for_woo_settings_section_controllers` filter so addons
 * can plug in their own tabs.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings;

use OrderUpdatesForWoo\Admin\Settings\Controllers\SettingsSectionController;
use OrderUpdatesForWoo\Shared\Team\TeamRosterService;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class OrderUpdatesSettingsController {
	private const TAB_ID = 'order_updates_for_woo';

	/** @var array<int, SettingsSectionController> */
	private array $resolved_sections = array();

	/**
	 * @param array<int, SettingsSectionController> $sections
	 */
	public function __construct(
		private array $sections,
		private ?TeamRosterService $team_roster = null
	) {}

	public function init(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'register_settings_tab' ), 50 );
		add_action( 'woocommerce_settings_tabs_' . self::TAB_ID, array( $this, 'render_settings_page' ) );
		add_action( 'woocommerce_update_options_' . self::TAB_ID, array( $this, 'save_settings_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_handle_actions' ) );

		// Each section controller registers its own hooks (admin-init
		// handlers, AJAX, etc.). Sections that need none have a no-op init.
		foreach ( $this->sections() as $section ) {
			$section->init();
		}

		$this->seed_default_options();
	}

	/**
	 * Write each setting's default into the DB on first load after install
	 * or upgrade.
	 *
	 * The settings UI renders defaults as checked, but until a save runs the
	 * option row doesn't exist, so reads fall back to code defaults — which
	 * can disagree with what the UI shows (e.g. a box that looks enabled but
	 * reads as off). Seeding keeps the UI, the stored value, and every read
	 * in sync. add_option() is a no-op when the row already exists, so a
	 * user's saved choices are never overwritten.
	 */
	public function seed_default_options(): void {
		if ( ORDER_UPDATES_FOR_WOO_VERSION === get_option( 'order_updates_for_woo_defaults_seeded', '' ) ) {
			return;
		}

		foreach ( $this->sections() as $section ) {
			foreach ( $section->get_settings() as $field ) {
				if ( empty( $field['id'] ) || ! array_key_exists( 'default', $field ) ) {
					continue;
				}

				$type = $field['type'] ?? '';
				if ( in_array( $type, array( 'title', 'sectionend', 'info' ), true ) ) {
					continue;
				}

				add_option( $field['id'], $field['default'] );
			}
		}

		update_option( 'order_updates_for_woo_defaults_seeded', ORDER_UPDATES_FOR_WOO_VERSION, false );
	}

	public function register_settings_tab( array $tabs ): array {
		if ( ! $this->user_can_manage_settings() ) {
			return $tabs;
		}

		$tabs[ self::TAB_ID ] = __( 'Order Updates Settings', 'order-updates-for-woo' );

		return $tabs;
	}

	public function render_settings_page(): void {
		if ( ! $this->user_can_manage_settings() ) {
			wp_die( esc_html__( 'You are not allowed to view order updates.', 'order-updates-for-woo' ), '', array( 'response' => 403 ) );
		}

		$this->maybe_show_team_refreshed_notice();
		$this->maybe_show_reset_notice();
		$this->render_section_nav();

		$active = $this->resolve_active_section();
		$active->render();
		$this->render_reset_button( $active );
	}

	public function save_settings_page(): void {
		if ( ! $this->user_can_manage_settings() ) {
			return;
		}

		$settings = $this->resolve_active_section()->get_settings();

		if ( empty( $settings ) ) {
			return;
		}

		woocommerce_update_options( $settings );
	}

	/**
	 * Legacy "refresh team roster" link still ships from the Members tab
	 * description. Handles its nonce-protected redirect here so the URL
	 * keeps working from anywhere on the WC settings page.
	 */
	public function maybe_handle_actions(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified inside
		if ( ! isset( $_GET['order_updates_for_woo_action'] ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = sanitize_key( wp_unslash( (string) $_GET['order_updates_for_woo_action'] ) );

		if ( 'refresh_team_roster' === $action ) {
			$this->handle_refresh_team_roster();
			return;
		}

		if ( 'reset_section' === $action ) {
			$this->handle_reset_section();
			return;
		}
	}

	private function handle_refresh_team_roster(): void {
		if ( ! $this->user_can_manage_settings() ) {
			wp_die( esc_html__( 'You are not allowed to view order updates.', 'order-updates-for-woo' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'order_updates_for_woo_refresh_team_roster' );

		( $this->team_roster ?? new TeamRosterService() )->flush_cache();

		wp_safe_redirect(
			remove_query_arg(
				array( 'order_updates_for_woo_action', '_wpnonce' ),
				add_query_arg( 'order_updates_for_woo_team_refreshed', '1' )
			)
		);
		exit;
	}

	/**
	 * Wipe every option owned by the active section so its fields fall back
	 * to their declared defaults on the next render. The section identity
	 * comes from the URL (already used by render_settings_page) and the
	 * nonce is section-scoped, so a CSRF target can't pivot to a different
	 * tab than the one the user actually opened.
	 */
	private function handle_reset_section(): void {
		if ( ! $this->user_can_manage_settings() ) {
			wp_die( esc_html__( 'You are not allowed to view order updates.', 'order-updates-for-woo' ), '', array( 'response' => 403 ) );
		}

		$section_id = $this->current_section_id();
		check_admin_referer( 'order_updates_for_woo_reset_section_' . $section_id );

		$active = $this->resolve_active_section();

		foreach ( $active->get_settings() as $field ) {
			$type = (string) ( $field['type'] ?? '' );
			$id   = (string) ( $field['id'] ?? '' );

			// Skip layout-only rows — their ids are anchors, not option keys.
			if ( in_array( $type, array( 'title', 'sectionend', '' ), true ) ) {
				continue;
			}

			if ( '' === $id ) {
				continue;
			}

			delete_option( $id );
		}

		wp_safe_redirect(
			remove_query_arg(
				array( 'order_updates_for_woo_action', '_wpnonce' ),
				add_query_arg( 'order_updates_for_woo_section_reset', '1' )
			)
		);
		exit;
	}

	/**
	 * @return array<int, SettingsSectionController>
	 */
	private function sections(): array {
		if ( empty( $this->resolved_sections ) ) {
			/**
			 * Filter the list of section controllers shown on the settings tab.
			 * Addons can append their own implementers of
			 * SettingsSectionController to add new sub-tabs.
			 */
			$this->resolved_sections = (array) apply_filters( 'order_updates_for_woo_settings_section_controllers', $this->sections );
		}

		return $this->resolved_sections;
	}

	private function resolve_active_section(): SettingsSectionController {
		$current_id = $this->current_section_id();

		foreach ( $this->sections() as $section ) {
			if ( $section->id() === $current_id ) {
				return $section;
			}
		}

		// Unknown section id (URL tampered or addon removed) — fall back to default.
		return $this->sections()[0];
	}

	private function current_section_id(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav lookup
		return isset( $_GET['section'] ) ? sanitize_key( wp_unslash( (string) $_GET['section'] ) ) : '';
	}

	private function render_section_nav(): void {
		$current = $this->current_section_id();
		$links   = array();

		foreach ( $this->sections() as $section ) {
			$url     = add_query_arg(
				array(
					'page'    => 'wc-settings',
					'tab'     => self::TAB_ID,
					'section' => $section->id(),
				),
				admin_url( 'admin.php' )
			);
			$class   = $section->id() === $current ? 'current' : '';
			$links[] = sprintf(
				'<li><a href="%1$s" class="%2$s">%3$s</a></li>',
				esc_url( $url ),
				esc_attr( $class ),
				esc_html( $section->label() )
			);
		}

		echo '<ul class="subsubsub">' . implode( ' | ', $links ) . '</ul><br class="clear" />'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc'd above
	}

	/**
	 * Append a "Reset to default" link below the section's fields. Only
	 * shown for sections that actually own option fields (skips info-only
	 * tabs like Shortcodes and the API reference). The link target is a
	 * GET URL handled by maybe_handle_actions(); a JS confirm() guards
	 * against accidental clicks since this wipes every option in the tab.
	 */
	private function render_reset_button( SettingsSectionController $section ): void {
		if ( ! $this->section_has_resettable_fields( $section->get_settings() ) ) {
			return;
		}

		$section_id = $section->id();
		$url        = wp_nonce_url(
			add_query_arg(
				array(
					'page'                         => 'wc-settings',
					'tab'                          => self::TAB_ID,
					'section'                      => $section_id,
					'order_updates_for_woo_action' => 'reset_section',
				),
				admin_url( 'admin.php' )
			),
			'order_updates_for_woo_reset_section_' . $section_id
		);

		$confirm = esc_js( __( 'Reset all settings in this tab to their defaults? This cannot be undone.', 'order-updates-for-woo' ) );

		printf(
			'<p class="submit"><a href="%1$s" class="button" onclick="return confirm(\'%2$s\');">%3$s</a></p>',
			esc_url( $url ),
			$confirm, // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_js'd above
			esc_html__( 'Reset to default', 'order-updates-for-woo' )
		);
	}

	/**
	 * True when a section has at least one non-layout option field — used to
	 * gate the reset button so info-only tabs (Shortcodes, API reference)
	 * don't get a no-op control.
	 *
	 * @param array<int, array<string, mixed>> $settings
	 */
	private function section_has_resettable_fields( array $settings ): bool {
		foreach ( $settings as $field ) {
			$type = (string) ( $field['type'] ?? '' );

			if ( in_array( $type, array( 'title', 'sectionend', '' ), true ) ) {
				continue;
			}

			if ( '' !== (string) ( $field['id'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	private function maybe_show_reset_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash
		if ( empty( $_GET['order_updates_for_woo_section_reset'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Settings reset to defaults.', 'order-updates-for-woo' )
		);
	}

	private function maybe_show_team_refreshed_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash
		if ( empty( $_GET['order_updates_for_woo_team_refreshed'] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Team list refreshed.', 'order-updates-for-woo' )
		);
	}

	/**
	 * Plugin settings are admin-only. Shop managers have manage_woocommerce
	 * (which gates the WC settings screen itself), but configuring how this
	 * plugin behaves is a site administration concern, not a daily-operations one.
	 */
	private function user_can_manage_settings(): bool {
		return current_user_can( 'manage_options' );
	}
}
