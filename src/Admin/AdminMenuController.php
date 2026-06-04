<?php
/**
 * Registers the top-level Order Updates admin menu.
 *
 * @package OrderUpdatesForWoo
 */

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin;

use OrderUpdatesForWoo\Helpers\AdminBarNotificationStore;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's top-level "Order Updates" menu in wp-admin.
 *
 * Sub-pages (Welcome, Analytics, Notifications) hook into this parent via
 * add_submenu_page in their own controllers. The settings still live on the
 * WooCommerce → Settings → Order Updates tab; we add a submenu link that
 * jumps straight there.
 */
final class AdminMenuController {

	public const PARENT_SLUG = 'order-updates-for-woo';

	/**
	 * Submenu order under the Order Updates menu. WordPress sorts submenu items
	 * by this position, so the whole order is defined here in one place; each
	 * sub-page passes its own constant to add_submenu_page().
	 */
	public const POSITION_WELCOME       = 1;
	public const POSITION_NOTIFICATIONS = 2;
	public const POSITION_ASSIGNMENTS   = 3;
	public const POSITION_SETTINGS      = 4;
	public const POSITION_ANALYTICS     = 5;

	/**
	 * Register the hooks this section depends on.
	 */
	public function init(): void {
		// Priority 9 so the top-level slot exists before sub-pages register at 10.
		add_action( 'admin_menu', array( $this, 'register_top_level' ), 9 );
		// Display order comes from the POSITION_* constants, not this priority;
		// 12 just keeps registration after the top-level menu exists.
		add_action( 'admin_menu', array( $this, 'register_settings_link' ), 12 );
		// Priority 11 so the auto-duplicate submenu is removed AFTER sub-pages register.
		add_action( 'admin_menu', array( $this, 'remove_auto_duplicate' ), 11 );
	}

	/** Register the top-level Order Updates menu and its landing page. */
	public function register_top_level(): void {
		// Mirror the unread-notification count onto the top-level item, so it
		// stays visible when the admin menu is collapsed (the Notifications
		// sub-item's bubble is hidden then). Same markup as WordPress's
		// comments bubble.
		$menu_title = __( 'Order Updates', 'order-updates-for-woo' );
		$unread     = AdminBarNotificationStore::unread_count( get_current_user_id() );
		if ( $unread > 0 ) {
			$menu_title .= ' <span class="awaiting-mod"><span class="pending-count">' . esc_html( number_format_i18n( $unread ) ) . '</span></span>';
		}

		add_menu_page(
			__( 'Order Updates', 'order-updates-for-woo' ),
			$menu_title,
			'manage_woocommerce',
			self::PARENT_SLUG,
			'__return_null',
			'dashicons-format-chat',
			56
		);
	}

	/**
	 * Submenu link to the settings, which live on the WooCommerce settings
	 * tab. Passing the target URL as the slug makes WordPress render it as a
	 * plain link (no callback) that redirects on click.
	 */
	public function register_settings_link(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Order Updates Settings', 'order-updates-for-woo' ),
			__( 'Settings', 'order-updates-for-woo' ),
			'manage_woocommerce',
			'admin.php?page=wc-settings&tab=order_updates_for_woo',
			'',
			self::POSITION_SETTINGS
		);
	}

	/**
	 * WordPress adds an automatic submenu item that mirrors the parent slug;
	 * it has no real page. We register no default render callback, so this
	 * auto item would 404. Drop it once every sub-page has registered.
	 */
	public function remove_auto_duplicate(): void {
		remove_submenu_page( self::PARENT_SLUG, self::PARENT_SLUG );
	}
}
