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

final class EmailsSettingsController implements SettingsSectionController {
	private const NONCE_ACTION = 'order_updates_for_woo_email_toggle';
	private const QUERY_PARAM  = 'order_updates_for_woo_email_toggle';

	public function __construct( private EmailsSettingsService $service ) {}

	public function init(): void {
		add_action( 'admin_init', array( $this, 'maybe_handle_toggle' ) );
	}

	public function id(): string {
		return EmailsSettingsService::SECTION_ID;
	}

	public function label(): string {
		return $this->service->label();
	}

	public function get_settings(): array {
		return $this->service->get_settings();
	}

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

	private function build_toggle_url( string $email_id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'             => 'wc-settings',
					'tab'              => 'order_updates_for_woo',
					'section'          => self::id_static(),
					self::QUERY_PARAM => $email_id,
				),
				admin_url( 'admin.php' )
			),
			self::NONCE_ACTION
		);
	}

	private static function id_static(): string {
		return EmailsSettingsService::SECTION_ID;
	}

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
