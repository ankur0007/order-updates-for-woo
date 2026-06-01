<?php

declare(strict_types=1);

namespace OrderUpdatesForWoo\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the plugin's top-level "Order Updates" menu in wp-admin.
 *
 * Sub-pages (Welcome, Analytics, future Dashboard + Notifications) hook
 * into this parent via add_submenu_page in their own controllers. Settings
 * stay under Woo → Settings → Order Updates by convention.
 */
final class AdminMenuController {

	public const PARENT_SLUG = 'order-updates-for-woo';

	public function init(): void {
		// Priority 9 so the top-level slot exists before sub-pages register at 10.
		add_action( 'admin_menu', array( $this, 'register_top_level' ), 9 );
		// Priority 11 so the auto-duplicate submenu is removed AFTER sub-pages register.
		add_action( 'admin_menu', array( $this, 'remove_auto_duplicate' ), 11 );
	}

	public function register_top_level(): void {
		add_menu_page(
			__( 'Order Updates', 'order-updates-for-woo' ),
			__( 'Order Updates', 'order-updates-for-woo' ),
			'manage_woocommerce',
			self::PARENT_SLUG,
			'__return_null',
			'dashicons-format-chat',
			56
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
