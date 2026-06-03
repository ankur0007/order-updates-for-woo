<?php
/**
 * Emails settings controller — wires the Emails sub-tab.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin\Settings\Controllers;

use OrderUpdatesForWoo\Admin\Settings\Services\EmailsSettingsService;
use OrderUpdatesForWoo\Helpers\View;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Controller for the emails settings section.
 */
final class EmailsSettingsController implements SettingsSectionController {
	private const NONCE_ACTION = 'order_updates_for_woo_email_toggle';
	private const QUERY_PARAM  = 'order_updates_for_woo_email_toggle';

	/**
	 * Inject dependencies.
	 *
	 * @param EmailsSettingsService $service Injected dependency.
	 */
	public function __construct( private EmailsSettingsService $service ) {}

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_toggle' ) );
	}

	/**
	 * URL-safe section id (empty string for the default section).
	 */
	public function id(): string {
		return EmailsSettingsService::SECTION_ID;
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
		$this->maybe_show_toggle_notice();
		woocommerce_admin_fields( $this->service->get_settings() );

		$emails = array_map(
			fn( array $email ) => array_merge( $email, array( 'toggle_url' => $this->build_toggle_url( (string) $email['id'] ) ) ),
			$this->service->emails()
		);

		View::render(
			'src/Admin/Settings/Views/emails/manage-link',
			array(
				'emails'        => $emails,
				'wc_emails_url' => admin_url( 'admin.php?page=wc-settings&tab=email' ),
			)
		);
	}

	/** Handle an email enable/disable toggle (nonce-checked), then redirect. */
	public function maybe_handle_toggle(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified below
		if ( ! isset( $_GET[ self::QUERY_PARAM ] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		check_admin_referer( self::NONCE_ACTION );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified above
		$email_id = sanitize_key( wp_unslash( (string) $_GET[ self::QUERY_PARAM ] ) );

		if ( '' === $email_id ) {
			return;
		}

		$this->service->toggle_email_enabled( $email_id );

		wp_safe_redirect(
			remove_query_arg(
				array( self::QUERY_PARAM, '_wpnonce' ),
				add_query_arg( 'order_updates_for_woo_email_toggled', $email_id )
			)
		);
		exit;
	}

	/**
	 * Build a nonce-protected toggle URL for one email.
	 *
	 * @param string $email_id Email identifier.
	 */
	private function build_toggle_url( string $email_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'            => 'wc-settings',
					'tab'             => 'order_updates_for_woo',
					'section'         => self::id_static(),
					self::QUERY_PARAM => $email_id,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_ACTION
		);
	}

	/** This section's id (static so URL builders can use it). */
	private static function id_static(): string {
		return EmailsSettingsService::SECTION_ID;
	}

	/** Show the success notice after an email toggle redirect. */
	private function maybe_show_toggle_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only flash
		$toggled = isset( $_GET['order_updates_for_woo_email_toggled'] ) ? sanitize_key( wp_unslash( (string) $_GET['order_updates_for_woo_email_toggled'] ) ) : '';

		if ( '' === $toggled ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__( 'Email setting updated.', 'order-updates-for-woo' )
		);
	}
}
