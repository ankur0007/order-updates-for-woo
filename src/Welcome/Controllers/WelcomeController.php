<?php
/**
 * Welcome page + first-activation redirect.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Welcome\Controllers;

use OrderUpdatesForWoo\Helpers\AssetHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Welcome controller.
 */
final class WelcomeController {
	private const REDIRECT_OPTION = 'order_updates_for_woo_do_activation_redirect';
	private const SLUG            = 'order-updates-for-woo-welcome';

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect' ) );
	}

	/** Flag that the welcome page should be shown on the next admin load. */
	public static function set_redirect_flag(): void {
		update_option( self::REDIRECT_OPTION, '1', false );
	}

	/** Register the hidden Welcome submenu page. */
	public function register_page(): void {
		add_submenu_page(
			\OrderUpdatesForWoo\Admin\AdminMenuController::PARENT_SLUG,
			__( 'Order Updates', 'order-updates-for-woo' ),
			__( 'Welcome', 'order-updates-for-woo' ),
			'manage_woocommerce',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/** Redirect to the welcome page once after activation, then clear the flag. */
	public function maybe_redirect(): void {
		if ( ! get_option( self::REDIRECT_OPTION ) ) {
			return;
		}

		delete_option( self::REDIRECT_OPTION );

		// `activate-multi` is set by WordPress core on bulk plugin activation —
		// not user-controlled state we're acting on, just a presence check.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( wp_doing_ajax() || isset( $_GET['activate-multi'] ) ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::SLUG ) );
		exit;
	}

	/**
	 * Render the section body.
	 */
	public function render(): void {
		$settings_url = admin_url( 'admin.php?page=wc-settings&tab=order_updates_for_woo' );

		wp_enqueue_style(
			'order-updates-for-woo-welcome',
			AssetHelper::url( 'assets/Admin/css/welcome.css' ),
			array(),
			file_exists( ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/welcome.css' ) ? (string) filemtime( ORDER_UPDATES_FOR_WOO_PATH . 'assets/Admin/css/welcome.css' ) : '1.0.0'
		);

		include __DIR__ . '/../Views/WelcomePageView.php';
	}
}
